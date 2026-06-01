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
from datetime import date, datetime, timedelta
from decimal import Decimal
from pathlib import Path

try:
    from gbm_mx_api import ApiError, AuthError, GbmClient, MfaRequired
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
    client = GbmClient.from_saved(session_path)
    if client is not None:
        return client

    if totp_code is None:
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
# Main
# ---------------------------------------------------------------------------
def main() -> None:
    parser = argparse.ArgumentParser(description="Fetch GBM data into a per-user dir.")
    parser.add_argument("--session-path", required=True, help="Where to read/write session.json.")
    parser.add_argument("--data-dir", required=True, help="Where to write *.json output files.")
    parser.add_argument("--totp", help="6-digit TOTP code; supplied by the browser modal.")
    args = parser.parse_args()

    session_path = Path(args.session_path).expanduser().resolve()
    data_dir = Path(args.data_dir).expanduser().resolve()
    data_dir.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(data_dir, 0o700)
    except OSError:
        pass

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
            trading_accounts = [
                a for a in accounts if a.management_type_template == "trading"
            ]
            if trading_accounts:
                days_back = int(os.environ.get("GBM_ORDERS_DAYS", "90"))
                to_date_ = date.today()
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
                write_json(
                    data_dir / "orders.json",
                    {
                        "from_date": from_date_.isoformat(),
                        "to_date": to_date_.isoformat(),
                        "accounts": accounts_meta,
                        "orders": filled_orders,
                    },
                )
                write_json(
                    data_dir / "orders_all.json",
                    {
                        "from_date": from_date_.isoformat(),
                        "to_date": to_date_.isoformat(),
                        "accounts": accounts_meta,
                        "orders": all_orders,
                    },
                )
            else:
                print("  no trading accounts → skipping orders download.")

            # ----------------------------------------------------------
            # Dividends — cash distributions via api.appgbm.com.
            # Paginates server-side; we iterate every trading account so
            # users with multiple contracts see them all.
            # ----------------------------------------------------------
            if trading_accounts:
                div_days_back = int(os.environ.get("GBM_DIVIDENDS_DAYS", "365"))
                div_to = date.today()
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
                write_json(
                    data_dir / "dividends.json",
                    {
                        "from_date": div_from.isoformat(),
                        "to_date": div_to.isoformat(),
                        "dividends": dividends_payload,
                    },
                )

            # ----------------------------------------------------------
            # Transactions (full ledger). Same endpoint as dividends but
            # with no transac_type filter so we get EVERY movement. We
            # iterate ALL accounts (not just trading) — Smart Cash and
            # Asesor have rich activity that the blotter cannot see.
            # ----------------------------------------------------------
            if accounts:
                tx_days_back = int(os.environ.get("GBM_TRANSACTIONS_DAYS", "365"))
                tx_to = date.today()
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
                write_json(
                    data_dir / "transactions.json",
                    {
                        "from_date": tx_from.isoformat(),
                        "to_date": tx_to.isoformat(),
                        "accounts": accounts_meta_all,
                        "transactions": transactions_payload,
                    },
                )

            (data_dir / "last_update.date").write_text(
                datetime.now().strftime("%Y-%m-%d %H:%M:%S\n"), encoding="utf-8"
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
