#!/usr/bin/env bash
#
# bootstrap.sh — build this app's self-contained Python runtime, in place.
#
# The app carries everything it needs inside its own folder:
#   python/lib-src/          vendored source of gbm-mx-api (not on PyPI)
#   python/requirements.lock pinned PyPI deps
#   python/.venv/            the runtime (BUILD ARTIFACT — never copied)
#
# Migrate or copy the app = copy the folder, then run this on the target
# host with a compatible python3 (>=3.10). Nothing is installed in /opt.
#
# Idempotent: rebuilds .venv from scratch each run.
#
# Usage:
#   python/bootstrap.sh            # uses python3 from PATH
#   PYTHON=python3.14 python/bootstrap.sh
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$HERE"

PYTHON="${PYTHON:-python3}"

echo "▸ building .venv with $("$PYTHON" --version 2>&1)"
rm -rf .venv
"$PYTHON" -m venv .venv
.venv/bin/pip install -q --upgrade pip

echo "▸ installing pinned deps (requirements.lock)"
.venv/bin/pip install -q -r requirements.lock

echo "▸ installing vendored lib (lib-src, no deps)"
.venv/bin/pip install -q --no-deps ./lib-src

echo "▸ smoke test"
.venv/bin/python -c "import gbm_mx_api, httpx, pydantic; print('bootstrap OK — gbm_mx_api', gbm_mx_api.__version__, '· httpx', httpx.__version__, '· pydantic', pydantic.__version__)"
