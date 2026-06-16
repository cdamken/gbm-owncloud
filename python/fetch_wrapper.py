#!/usr/bin/env python3
"""Per-user GBM fetch wrapper, invoked by OCA\\Gbm\\Service\\GbmService.

Diffs vs. gbm-dashboard/app/fetch_data.py:

  * Session path is supplied via --session-path (one file per ownCloud user)
    instead of the global ``~/.gbm-mx/session.json``.
  * Output JSON dir is supplied via --data-dir (per-user data dir under
    ownCloud's datadirectory).
  * Credentials come from env (GBM_EMAIL / GBM_PASSWORD) injected by the PHP
    layer after decrypting the per-user prefs — there's no .env file.
  * Always runs non-interactively (PHP can't talk to stdin).

Exit codes (kept identical to the original so the PHP layer's mapping stays
the same as in server.py):

  0   success
  10  session expired AND no TOTP provided  → browser must show MFA modal
  11  TOTP code invalid (challenge failed)
  12  credentials invalid (wrong email / password)
  20  network / API error
  30  configuration error (lib missing, args missing, etc.)
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import date, datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path

try:
    from gbm_mx_api import ApiError, AuthError, GbmClient, MfaRequired
    from gbm_mx_api.errors import TransportError
except ImportError:
    sys.stderr.write(
        "gbm-mx-api is not installed in this Python. "
        "Point gbm.python_bin at the venv where it lives.\n"
    )
    sys.exit(30)


# ---------------------------------------------------------------------------
# Login
# ---------------------------------------------------------------------------
def make_fixed_totp_provider(code: str):
    if not (code.isdigit() and len(code) == 6):
        sys.stderr.write("ERROR: --totp must be exactly 6 digits.\n")
        sys.exit(11)

    def _provider() -> str:
        return code

    return _provider


def get_client(session_path: Path, totp_code: str | None) -> GbmClient:
    """Reuse the saved session if valid, otherwise log in fresh.

    If the session is missing/expired AND no TOTP was supplied, exit 10 so
    the browser opens its TOTP modal and retries with --totp <code>.
    """
    # Only try the saved session when we're NOT explicitly completing MFA.
    # With a --totp code in hand the user is finishing a fresh login, so go
    # straight to it: calling from_saved() first would attempt a doomed
    # refresh_session() network round-trip on the dead session (the reason
    # we're here), and that latency can push complete_mfa() past the code's
    # 30-second TOTP window → a spurious "invalid or expired TOTP code".
    if totp_code is None:
        client = GbmClient.from_saved(session_path)
        if client is not None:
            return client
        sys.stderr.write("MFA_REQUIRED: session expired, TOTP needed.\n")
        sys.exit(10)

    email = os.environ.get("GBM_EMAIL", "").strip()
    password = os.environ.get("GBM_PASSWORD", "")
    if not email or not password:
        sys.stderr.write("ERROR: GBM_EMAIL / GBM_PASSWORD missing in environment.\n")
        sys.exit(30)

    try:
        return GbmClient.login(
            email=email,
            password=password,
            totp_provider=make_fixed_totp_provider(totp_code),
            persist_to=session_path,
        )
    except AuthError as e:
        msg = str(e).lower()
        if any(k in msg for k in ("code", "mfa", "challenge", "totp")):
            sys.stderr.write("ERROR: invalid or expired TOTP code.\n")
            sys.exit(11)
        sys.stderr.write(f"ERROR: bad credentials ({e}).\n")
        sys.exit(12)
    except MfaRequired as e:
        sys.stderr.write(f"ERROR: MFA challenge unresolved ({e}).\n")
        sys.exit(11)
    # NOTE: gbm-mx-api >= 0.1.4 now reclassifies HTTP 422 from auth.gbm.com
    # as AuthError (NotAuthorizedException), so the AuthError handler above
    # catches it. No special ApiError branch needed here anymore.


# ---------------------------------------------------------------------------
# JSON helpers
# ---------------------------------------------------------------------------
def to_jsonable(obj):
    if isinstance(obj, dict):
        return {k: to_jsonable(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [to_jsonable(x) for x in obj]
    if isinstance(obj, Decimal):
        return float(obj)
    if isinstance(obj, datetime):
        return obj.isoformat()
    return obj


def write_json(path: Path, data) -> None:
    payload = to_jsonable(data)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass
    print(f"  wrote {path.name} ({path.stat().st_size / 1024:.1f} KB)")


# ---------------------------------------------------------------------------
# Incremental fetch helpers — mirrors gbm-dashboard/app/fetch_data.py
# ---------------------------------------------------------------------------
INCREMENTAL_BUFFER_DAYS = 14


def read_last_update_date(data_dir: Path):
    """Date portion of {data_dir}/last_update.date, or None.

    Accepts both formats:
      - Legacy:  "2026-06-10 09:21:43" (naive local-of-server)
      - Current: "2026-06-10T09:21:43Z" (UTC ISO 8601, written 2026-06-10+)
    Only the YYYY-MM-DD prefix matters for the incremental window
    calculation; the time component is consumed by the staleness chip.
    """
    path = data_dir / "last_update.date"
    if not path.exists():
        return None
    try:
        first_line = path.read_text(encoding="utf-8").strip().splitlines()[0]
        # Split on either 'T' (ISO) or whitespace (legacy) — both leave
        # the YYYY-MM-DD prefix in the first element.
        date_part = first_line.split('T')[0].split()[0]
        return date.fromisoformat(date_part)
    except (OSError, ValueError, IndexError):
        return None


def merge_records(
    existing_path: Path,
    new_payload: dict,
    list_field: str,
    key_fn,
    sort_key: str,
    sort_reverse: bool = True,
) -> dict:
    """Merge new_payload[list_field] into the existing JSON at path.

    New records overwrite existing ones on key collision (so pending →
    filled transitions propagate). Existing records not in this fetch
    stay intact (they're older than the incremental cutoff). Preserves
    the older from_date so the JSON metadata reflects the full window.
    """
    existing_records: list = []
    existing_from = None
    if existing_path.exists():
        try:
            with existing_path.open(encoding="utf-8") as f:
                existing = json.load(f)
            existing_records = existing.get(list_field, []) or []
            existing_from = existing.get("from_date")
        except (json.JSONDecodeError, OSError):
            pass

    by_key: dict = {}
    for r in existing_records:
        try:
            by_key[key_fn(r)] = r
        except (KeyError, TypeError):
            continue
    for r in new_payload.get(list_field, []) or []:
        try:
            by_key[key_fn(r)] = r
        except (KeyError, TypeError):
            continue
    merged = list(by_key.values())
    merged.sort(key=lambda r: r.get(sort_key, "") or "", reverse=sort_reverse)
    new_payload[list_field] = merged

    new_from = new_payload.get("from_date")
    if existing_from and (not new_from or existing_from < new_from):
        new_payload["from_date"] = existing_from
    return new_payload


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
def main() -> None:
    parser = argparse.ArgumentParser(description="Fetch GBM data into a per-user dir.")
    parser.add_argument("--session-path", required=True, help="Where to read/write session.json.")
    parser.add_argument("--data-dir", help="Where to write *.json output files (required unless --revoke).")
    parser.add_argument("--totp", help="6-digit TOTP code; supplied by the browser modal.")
    parser.add_argument("--revoke", action="store_true",
                        help="Call Cognito GlobalSignOut on the saved session and exit. "
                             "Best-effort: exits 0 if revoked, non-zero with stderr otherwise.")
    parser.add_argument("--full", action="store_true",
                        help="Force full-window fetch (skip incremental merge). "
                             "Used after /reset, on first run, or when a user "
                             "explicitly checks 'Recargar todo desde cero'.")
    args = parser.parse_args()

    session_path = Path(args.session_path).expanduser().resolve()

    # --revoke short-circuit: don't authenticate, don't write data — just
    # invalidate the existing refresh_token server-side via Cognito.
    if args.revoke:
        try:
            from gbm_mx_api.auth import global_signout
            from gbm_mx_api.auth.session import Session
        except ImportError as e:
            sys.stderr.write(f"global_signout unavailable: {e}\n")
            sys.exit(30)
        if not session_path.is_file():
            sys.stderr.write("no saved session to revoke\n")
            sys.exit(0)  # nothing to do, treat as success
        try:
            sess = Session.try_load(session_path)
            if sess is None:
                sys.stderr.write("session file unreadable\n")
                sys.exit(20)
            global_signout(sess)
            print("Cognito GlobalSignOut OK")
            sys.exit(0)
        except Exception as e:
            sys.stderr.write(f"GlobalSignOut failed: {e}\n")
            sys.exit(20)

    if not args.data_dir:
        sys.stderr.write("--data-dir is required (unless --revoke)\n")
        sys.exit(30)
    data_dir = Path(args.data_dir).expanduser().resolve()
    data_dir.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(data_dir, 0o700)
    except OSError:
        pass

    # Decide incremental vs full BEFORE any API call so we know which
    # date range to ask for and whether to merge or overwrite.
    last_update = read_last_update_date(data_dir)
    incremental = last_update is not None and not args.full
    if incremental:
        incremental_from = last_update - timedelta(days=INCREMENTAL_BUFFER_DAYS)
        print(
            f"Incremental mode — fetching since {incremental_from} "
            f"(last_update = {last_update}, buffer = {INCREMENTAL_BUFFER_DAYS}d)"
        )
    else:
        reason = "forced via --full" if args.full else "first run / no last_update.date"
        print(f"Full mode ({reason}) — pulling the configured days window.")

    print("Connecting to GBM+...")
    try:
        with get_client(session_path, args.totp) as client:
            contract = client.contracts.get_main()
            print(f"  contract: {contract.legacy_contract_id}")

            # list_with_dashboard merges the legacy /v2 endpoint (balances)
            # with the newer appgbm.com /dashboard endpoint (which includes
            # the otherwise-hidden Smart Cash Dólares account).
            accounts = client.accounts.list_with_dashboard(contract.contract_id)
            print(f"  accounts: {len(accounts)}")

            # Mobile app's "TOTAL INVERTIDO" endpoint. Matches the mobile
            # number to the cent (uses live FX, unlike GetPositionSummary).
            email = os.environ.get("GBM_EMAIL", "")
            if email:
                try:
                    ig = client.dashboard.investments_groups(contract.contract_id, email)
                    write_json(
                        data_dir / "investments_groups.json",
                        ig.model_dump(by_alias=False),
                    )
                    print(
                        f"  investments-groups: total=${float(ig.total_position.amount):,.2f} "
                        f"({len(ig.groups)} groups)"
                    )
                except (ApiError, TransportError) as e:
                    # Slow endpoint, may time out — non-fatal.
                    print(f"  investments-groups: SKIPPED ({type(e).__name__}: {e})")

            accounts_payload = [
                {
                    "legacy_contract_id": a.legacy_contract_id,
                    "account_id": a.account_id,
                    "name": a.name,
                    "number": a.number,
                    "management_type_template": a.management_type_template,
                    "position": {
                        "amount": float(a.position.amount) if a.position else None,
                        "currency": a.position.currency if a.position else None,
                    },
                    "plus_minus": {
                        "amount": float(a.plus_minus.amount) if a.plus_minus else None,
                        "currency": a.plus_minus.currency if a.plus_minus else None,
                    },
                    "plus_minus_percentage": a.plus_minus_percentage,
                    "status": a.status,
                    "created_at": a.created_at.isoformat() if a.created_at else None,
                }
                for a in accounts
            ]
            write_json(data_dir / "accounts.json", accounts_payload)

            INVEST_SECTIONS = (
                "mercados_globales_sic",
                "mercado_capitales",
                "sociedades_inversion_deuda",
                "sociedades_inversion_comun",
                "mercado_extranjero",
            )
            positions_by_account: dict[str, object] = {}
            for a in accounts:
                try:
                    summary = client.positions.summary(
                        a.legacy_contract_id, account_id=a.account_id
                    )
                    positions_by_account[a.legacy_contract_id] = to_jsonable(
                        summary.model_dump(by_alias=False)
                    )
                    count = sum(
                        1
                        for section_key in INVEST_SECTIONS
                        for p in positions_by_account[a.legacy_contract_id].get(section_key) or []
                        if p.get("issue_id") != "Subtotal"
                    )
                    print(f"  positions for {a.legacy_contract_id} ({a.name}): {count}")
                except ApiError as e:
                    print(f"  positions for {a.legacy_contract_id} ({a.name}): {e}")
                    positions_by_account[a.legacy_contract_id] = None

            write_json(data_dir / "positions.json", positions_by_account)

            # ----------------------------------------------------------
            # Orders for EVERY trading account, all statuses. One backend
            # call per account (list_for_range returns any status); we
            # derive two JSON files from the same data so the UI never
            # has to query twice:
            #
            #   orders.json       — only filled  (Movimientos page)
            #   orders_all.json   — every status (Histórico page)
            #
            # Schema follows gbm-dashboard@v0.5: accounts as an array,
            # each order tagged with its account.
            # ----------------------------------------------------------
            # Include Trading USA (template "trading_usa") alongside the
            # Mexican "trading" accounts — its orders come from the same
            # GetBlotterOrders endpoint. The "== 'trading'" filter silently
            # dropped Trading USA, so USA buys/sells (e.g. a partial sell)
            # never showed in Movimientos even though MX ones did. The
            # per-account call is wrapped in try/except, so if GBM rejects
            # the USA account it's logged and skipped rather than fatal.
            trading_accounts = [
                a for a in accounts
                if a.management_type_template in ("trading", "trading_usa")
            ]
            if trading_accounts:
                to_date_ = date.today()
                if incremental:
                    from_date_ = incremental_from
                else:
                    # Full backfill window. GetBlotterOrders is queried DAY BY
                    # DAY (one day per call), so this window directly drives
                    # how many sequential HTTP calls a full reload makes (×
                    # each trading account). The old 3650-day (10-year)
                    # default meant ~3,650 calls per account even on a
                    # months-old account — mostly empty — blowing past the
                    # 180s subprocess timeout and getting SIGKILL'd mid-fetch
                    # (which then looked like a failed/expired TOTP). 365 days
                    # covers a young account with margin; bump GBM_ORDERS_DAYS
                    # for an older one.
                    days_back = int(os.environ.get("GBM_ORDERS_DAYS", "365"))
                    from_date_ = to_date_ - timedelta(days=days_back)
                print(
                    f"  fetching orders {from_date_} → {to_date_} "
                    f"for {len(trading_accounts)} trading account(s)..."
                )

                all_orders: list[dict] = []
                filled_orders: list[dict] = []
                for acct in trading_accounts:
                    try:
                        raw_orders = client.orders.list_for_range(
                            acct.legacy_contract_id, from_date_, to_date_
                        )
                    except ApiError as e:
                        print(f"  {acct.name} ({acct.legacy_contract_id}): {e}")
                        continue
                    n_filled = sum(1 for o in raw_orders if o.is_filled)
                    print(
                        f"  {acct.name} ({acct.legacy_contract_id}): "
                        f"{len(raw_orders)} total, {n_filled} filled"
                    )
                    for o in raw_orders:
                        amount = float(o.assigned_quantity * o.average_price)
                        common = {
                            "sob_id": o.sob_id,
                            "account_id": o.account_id,
                            "issue_id": o.issue_id,
                            "instrument_type": int(o.instrument_type),
                            "side": o.side.name,
                            "status": o.status,
                            "status_label": o.status_label,
                            "is_filled": o.is_filled,
                            "is_cancelled": o.is_cancelled,
                            "original_quantity": o.original_quantity,
                            "assigned_quantity": o.assigned_quantity,
                            "cancel_quantity": o.cancel_quantity,
                            "quantity": (o.assigned_quantity
                                         if o.is_filled
                                         else o.original_quantity),
                            "average_price": float(o.average_price),
                            "limit_price": float(o.price),
                            "amount": amount,
                            "commission": float(o.commission),
                            "iva": float(o.iva),
                            "processed_at": o.process_date.isoformat(),
                            "cancel_message": o.cancel_message,
                            "account_legacy_id": acct.legacy_contract_id,
                            "account_name": acct.name,
                        }
                        all_orders.append(common)
                        if o.is_filled:
                            filled_orders.append(common)

                all_orders.sort(key=lambda o: o["processed_at"])
                filled_orders.sort(key=lambda o: o["processed_at"])

                accounts_meta = [
                    {
                        "legacy_contract_id": a.legacy_contract_id,
                        "name": a.name,
                    }
                    for a in trading_accounts
                ]
                filled_payload = {
                    "from_date": from_date_.isoformat(),
                    "to_date": to_date_.isoformat(),
                    "accounts": accounts_meta,
                    "orders": filled_orders,
                }
                all_payload = {
                    "from_date": from_date_.isoformat(),
                    "to_date": to_date_.isoformat(),
                    "accounts": accounts_meta,
                    "orders": all_orders,
                }
                if incremental:
                    filled_payload = merge_records(
                        data_dir / "orders.json", filled_payload,
                        list_field="orders",
                        key_fn=lambda r: r.get("sob_id"),
                        sort_key="processed_at",
                    )
                    all_payload = merge_records(
                        data_dir / "orders_all.json", all_payload,
                        list_field="orders",
                        key_fn=lambda r: r.get("sob_id"),
                        sort_key="processed_at",
                    )
                write_json(data_dir / "orders.json", filled_payload)
                write_json(data_dir / "orders_all.json", all_payload)
            else:
                print("  no trading accounts → skipping orders download.")

            # ----------------------------------------------------------
            # Dividends — cash distributions via api.appgbm.com.
            # Paginates server-side; we iterate every trading account so
            # users with multiple contracts see them all.
            # ----------------------------------------------------------
            if trading_accounts:
                div_to = date.today()
                if incremental:
                    div_from = incremental_from
                else:
                    div_days_back = int(os.environ.get("GBM_DIVIDENDS_DAYS", "3650"))
                    div_from = div_to - timedelta(days=div_days_back)
                print(
                    f"  fetching dividends {div_from} → {div_to} "
                    f"for {len(trading_accounts)} trading account(s)..."
                )
                dividends_payload: list[dict] = []
                for acct in trading_accounts:
                    try:
                        divs = client.dividends.list_for_range(
                            contract.contract_id,
                            acct.legacy_contract_id,
                            div_from,
                            div_to,
                        )
                    except ApiError as e:
                        print(
                            f"  dividends {acct.name} ({acct.legacy_contract_id}): {e}"
                        )
                        continue
                    print(
                        f"  dividends {acct.name} ({acct.legacy_contract_id}): "
                        f"{len(divs)} item(s)"
                    )
                    for d in divs:
                        dividends_payload.append(
                            {
                                "transaction_id": d.transaction_id,
                                "security_id": d.security_id,
                                "security_name": d.security_name,
                                "description": d.transaction_description,
                                "amount": float(d.transaction_amount),
                                "net_amount": float(d.transaction_net_amount),
                                "is_withholding": d.is_withholding,
                                "process_date": d.process_date.isoformat(),
                                "settlement_date": (
                                    d.settlement_date.isoformat()
                                    if d.settlement_date
                                    else None
                                ),
                                "transaction_time": d.transaction_time,
                                "account_legacy_id": acct.legacy_contract_id,
                                "account_name": acct.name,
                            }
                        )
                dividends_payload.sort(key=lambda d: d["process_date"], reverse=True)
                div_file_payload = {
                    "from_date": div_from.isoformat(),
                    "to_date": div_to.isoformat(),
                    "dividends": dividends_payload,
                }
                if incremental:
                    div_file_payload = merge_records(
                        data_dir / "dividends.json", div_file_payload,
                        list_field="dividends",
                        key_fn=lambda r: r.get("transaction_id"),
                        sort_key="process_date",
                    )
                write_json(data_dir / "dividends.json", div_file_payload)

            # ----------------------------------------------------------
            # Transactions (full ledger). Same endpoint as dividends but
            # with no transac_type filter so we get EVERY movement. We
            # iterate ALL accounts (not just trading) — Smart Cash and
            # Asesor have rich activity that the blotter cannot see.
            # ----------------------------------------------------------
            if accounts:
                tx_to = date.today()
                if incremental:
                    tx_from = incremental_from
                else:
                    tx_days_back = int(os.environ.get("GBM_TRANSACTIONS_DAYS", "3650"))
                    tx_from = tx_to - timedelta(days=tx_days_back)
                print(
                    f"  fetching transactions {tx_from} → {tx_to} "
                    f"for {len(accounts)} account(s)..."
                )
                transactions_payload: list[dict] = []
                for acct in accounts:
                    try:
                        txs = client.transactions.list_for_range(
                            contract.contract_id,
                            acct.legacy_contract_id,
                            tx_from,
                            tx_to,
                        )
                    except ApiError as e:
                        print(
                            f"  transactions {acct.name} "
                            f"({acct.legacy_contract_id}): {e}"
                        )
                        continue
                    print(
                        f"  transactions {acct.name} ({acct.legacy_contract_id}): "
                        f"{len(txs)} item(s)"
                    )
                    for t in txs:
                        transactions_payload.append(
                            {
                                "transaction_id": t.transaction_id,
                                "security_id": t.security_id,
                                "security_name": t.security_name,
                                "transaction_type": t.transaction_type,
                                "sub_transaction_type": t.sub_transaction_type,
                                "description": t.transaction_description,
                                "category": t.category,
                                "is_buy": t.is_buy,
                                "is_sell": t.is_sell,
                                "is_cash_flow": t.is_cash_flow,
                                "amount": float(t.transaction_amount),
                                "net_amount": float(t.transaction_net_amount),
                                "quantity": float(t.quantity),
                                "price": float(t.transaction_price),
                                "commission": float(t.transaction_commission),
                                "tax": float(t.transaction_tax),
                                "process_date": t.process_date.isoformat(),
                                "settlement_date": (
                                    t.settlement_date.isoformat()
                                    if t.settlement_date
                                    else None
                                ),
                                "transaction_time": t.transaction_time,
                                "account_legacy_id": acct.legacy_contract_id,
                                "account_name": acct.name,
                            }
                        )
                transactions_payload.sort(
                    key=lambda t: t["process_date"], reverse=True
                )
                accounts_meta_all = [
                    {"legacy_contract_id": a.legacy_contract_id, "name": a.name}
                    for a in accounts
                ]
                tx_file_payload = {
                    "from_date": tx_from.isoformat(),
                    "to_date": tx_to.isoformat(),
                    "accounts": accounts_meta_all,
                    "transactions": transactions_payload,
                }
                if incremental:
                    tx_file_payload = merge_records(
                        data_dir / "transactions.json", tx_file_payload,
                        list_field="transactions",
                        key_fn=lambda r: r.get("transaction_id"),
                        sort_key="process_date",
                    )
                write_json(data_dir / "transactions.json", tx_file_payload)

            # ISO 8601 UTC with explicit Z — browser JS parses the `Z`
            # and converts to user-local via toLocaleTimeString(). Fixes
            # the "Updated 07:21 AM" stale chip on a UTC server.
            (data_dir / "last_update.date").write_text(
                datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ\n"),
                encoding="utf-8",
            )

    except AuthError as e:
        # Saved session was rejected (revoked mid-flight). Wipe it and tell
        # the caller MFA is required so the browser reopens its TOTP modal.
        try:
            session_path.unlink(missing_ok=True)
        except OSError:
            pass
        sys.stderr.write(f"Saved session rejected by GBM ({e}). Removed.\n")
        sys.exit(10)
    except ApiError as e:
        sys.stderr.write(f"API error: {e}\n")
        sys.exit(20)

    print("OK Fetch complete.")


if __name__ == "__main__":
    main()
