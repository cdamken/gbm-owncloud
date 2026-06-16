#!/usr/bin/env python3
"""Per-user GBM fetch wrapper, invoked by OCA\\Gbm\\Service\\GbmService.

Thin host adapter around ``gbm_mx_api.sync`` — the shared data núcleo. The
fetch+write pipeline lives in the library (gbm-mx-api/src/gbm_mx_api/sync.py);
this script only does what's specific to the *multi-user ownCloud* host:

  * Session path via --session-path (one file per ownCloud user) instead of
    the global ``~/.gbm-mx/session.json``.
  * Output dir via --data-dir (per-user dir under ownCloud's datadirectory).
  * Credentials from env (GBM_EMAIL / GBM_PASSWORD) injected by the PHP layer
    after decrypting the per-user prefs — there's no .env file.
  * Always non-interactive (PHP can't talk to stdin).
  * A --revoke short-circuit that calls Cognito GlobalSignOut.

See ADR ``2026-06-16 — ALL — Núcleo compartido`` in
Portfolio-Master/DECISIONS.md.

Exit codes (kept identical to the dashboard so the PHP layer's mapping stays
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
import os
import sys
from pathlib import Path

try:
    from gbm_mx_api import (
        ApiError,
        AuthError,
        GbmClient,
        MfaRequired,
        sync,
        try_refresh_saved,
    )
except ImportError:
    sys.stderr.write(
        "gbm-mx-api is not installed in this Python. "
        "Point gbm.python_bin at the venv where it lives.\n"
    )
    sys.exit(30)


# ---------------------------------------------------------------------------
# Login (host-specific: fixed TOTP, per-user session path)
# ---------------------------------------------------------------------------
def make_fixed_totp_provider(code: str):
    if not (code.isdigit() and len(code) == 6):
        sys.stderr.write("ERROR: --totp must be exactly 6 digits.\n")
        sys.exit(11)

    def _provider() -> str:
        return code

    return _provider


def get_client(session_path: Path, totp_code: str | None) -> GbmClient:
    """Reuse the saved session if usable, otherwise log in fresh.

    If the session is missing/expired AND no TOTP was supplied, wipe it and
    exit 10 so the browser opens its TOTP modal and retries with --totp <code>.
    """
    if totp_code is None:
        client = try_refresh_saved(session_path)
        if client is not None:
            return client
        try:
            session_path.unlink(missing_ok=True)
        except OSError:
            pass
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

    print("Connecting to GBM+...")
    try:
        with get_client(session_path, args.totp) as client:
            sync(
                client,
                data_dir,
                full=args.full,
                email=os.environ.get("GBM_EMAIL"),
                secure=True,
            )
    except AuthError as e:
        # Saved session was rejected (revoked mid-flight). Wipe it and tell the
        # caller MFA is required so the browser reopens its TOTP modal.
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
