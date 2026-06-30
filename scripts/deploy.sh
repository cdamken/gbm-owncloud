#!/usr/bin/env bash
# =============================================================================
# deploy.sh — sync gbm-owncloud + gbm-mx-api to cloud.damken.com
#
# Three moving parts that must stay in lockstep:
#
#   1. THE APP   →  /var/www/owncloud/apps/gbm/
#                   (PHP controllers, JS, CSS, templates, python wrapper)
#
#   2. THE LIB   →  /opt/gbm-venv/   (Python venv with gbm-mx-api installed)
#                   The local Dashboard has gbm-mx-api as `pip install -e ../gbm-mx-api`
#                   so it always picks up changes. The server has a STATIC
#                   install that stays frozen at install time. If you add a
#                   new module to gbm-mx-api and only deploy the app,
#                   fetch_wrapper.py crashes with `ImportError`.
#                   pip caches partial installs of git packages — we always
#                   pass `--force-reinstall --no-deps` to guarantee every
#                   file gets replaced (see CLAUDE.md note).
#
#   3. CACHE     →  ownCloud appends `?v=<hash>` to script URLs and that
#                   hash is derived from <version> in appinfo/info.xml.
#                   If you change JS/CSS but don't bump the version, the
#                   browser sees the "same URL" and serves the cached
#                   file. The .htaccess in this repo forces revalidation
#                   for JS/CSS, but a version bump is still good hygiene.
#
# Pre-deploy checks (mandatory, can't be skipped):
#
#   • python3 scripts/verify_dom_ids.py
#       Fails if any $('xxx') or getElementById('xxx') in js/*.js
#       references a DOM id that no templates/*.php defines. Catches
#       the class of bug that hit us on 2026-06-05 with `settings-btn`
#       (one null reference aborted the entire wire-up callback).
#
# Usage:
#   ./scripts/deploy.sh                       # app + lib, no version bump
#   ./scripts/deploy.sh --bump patch          # also bump 0.14.x → 0.14.(x+1)
#   ./scripts/deploy.sh --no-lib              # JS-only change, skip pip
#   ./scripts/deploy.sh --lib --no-app        # gbm-mx-api hot-fix only
#
# =============================================================================

set -euo pipefail

# ---------- paths / hosts (edit if the deploy topology moves) ----------
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GBM_API_REPO="${HOME}/damkencloud/Claude/gbm-mx-api"
LOCAL_OC_APPS="${HOME}/damkencloud/oc_Apps/gbm"

SERVER_HOST="carlos@cloud.damken.com"
SERVER_PORT="2222"
SERVER_KEY="${HOME}/.ssh/id_ed25519"

SERVER_APP_DIR="/var/www/owncloud/apps/gbm"
SERVER_GBM_API_SRC="/opt/gbm-mx-api-src"
SERVER_VENV="/opt/gbm-venv"
SERVER_OCC="/var/www/owncloud/occ"

SSH_OPTS=(-A -i "${SERVER_KEY}" -p "${SERVER_PORT}")
RSYNC_SSH="ssh -A -i ${SERVER_KEY} -p ${SERVER_PORT}"

# ---------- flags ----------
DO_APP=1
DO_LIB=1
DO_BUMP=""
SKIP_VERIFY=0

usage() {
  cat <<EOF
Usage: ${0##*/} [options]

Sync gbm-owncloud + gbm-mx-api to cloud.damken.com.

Options:
  --app / --no-app         Deploy the app (default: yes)
  --lib / --no-lib         Reinstall gbm-mx-api in /opt/gbm-venv (default: yes)
  --bump LEVEL             Bump <version> in appinfo/info.xml before deploy.
                           LEVEL = patch | minor | major
  --skip-verify            Skip the verify_dom_ids.py pre-deploy check.
                           Don't use this unless you're debugging the
                           verifier itself — the check is mandatory for
                           a reason (see header).
  -h, --help               Show this help

When to bump:
  - JS or CSS changed         → --bump patch
  - PHP/template only         → --bump patch
  - New feature shipped       → --bump minor
  - Breaking change           → --bump major
  - Pure gbm-mx-api lib       → (skip bump, JS doesn't change)
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --app)         DO_APP=1; shift ;;
    --no-app)      DO_APP=0; shift ;;
    --lib)         DO_LIB=1; shift ;;
    --no-lib)      DO_LIB=0; shift ;;
    --bump)        DO_BUMP="${2:-}"; shift 2 ;;
    --skip-verify) SKIP_VERIFY=1; shift ;;
    -h|--help)     usage; exit 0 ;;
    *)             echo "Unknown flag: $1" >&2; usage >&2; exit 2 ;;
  esac
done

# ---------- pretty output ----------
say() { printf '\033[1;36m▸ %s\033[0m\n' "$*"; }
ok()  { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn(){ printf '\033[1;33m! %s\033[0m\n' "$*"; }
die() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ---------- pre-flight ----------
[[ -d "$REPO_ROOT/.git" ]] || die "REPO_ROOT does not look like a git repo: $REPO_ROOT"
[[ -f "$REPO_ROOT/appinfo/info.xml" ]] || die "appinfo/info.xml not found"
if [[ $DO_LIB -eq 1 ]] && [[ ! -d "$GBM_API_REPO" ]]; then
  die "gbm-mx-api repo not found at $GBM_API_REPO (set GBM_API_REPO env or use --no-lib)"
fi
[[ -f "$SERVER_KEY" ]] || die "SSH key not found at $SERVER_KEY"

# ---------- step 0: pre-deploy checks ----------
if [[ $DO_APP -eq 1 ]] && [[ $SKIP_VERIFY -eq 0 ]]; then
  say "Pre-deploy: verify DOM-ID sync (scripts/verify_dom_ids.py)"
  if ! python3 "${REPO_ROOT}/scripts/verify_dom_ids.py"; then
    die "DOM-ID check failed. Fix the missing IDs or pass --skip-verify (not recommended)."
  fi

  say "Pre-deploy: verify JS wiring (scripts/verify_wiring.py)"
  if ! python3 "${REPO_ROOT}/scripts/verify_wiring.py"; then
    die "JS wiring check failed. Fix the stranded refs or pass --skip-verify."
  fi

  say "Pre-deploy: unit tests (python3 -m unittest)"
  if ! (cd "${REPO_ROOT}" && python3 -m unittest discover -s tests >/dev/null 2>&1); then
    die "Tests failed. Run 'python3 -m unittest discover -s tests -v' to see details."
  fi
  ok "All pre-deploy checks green"
fi

# ---------- step 1: optional version bump ----------
if [[ -n "$DO_BUMP" ]]; then
  case "$DO_BUMP" in patch|minor|major) ;; *)
    die "--bump must be one of: patch, minor, major (got: $DO_BUMP)" ;;
  esac
  say "Bumping app version ($DO_BUMP) in appinfo/info.xml"
  cur=$(grep -oE '<version>[^<]+</version>' "$REPO_ROOT/appinfo/info.xml" | sed 's/<[^>]*>//g')
  [[ -n "$cur" ]] || die "Could not read current version from appinfo/info.xml"
  IFS=. read -r maj min pat <<< "$cur"
  case "$DO_BUMP" in
    patch) pat=$((pat + 1)) ;;
    minor) min=$((min + 1)); pat=0 ;;
    major) maj=$((maj + 1)); min=0; pat=0 ;;
  esac
  new="${maj}.${min}.${pat}"
  tmp=$(mktemp)
  sed "s|<version>${cur}</version>|<version>${new}</version>|" \
      "$REPO_ROOT/appinfo/info.xml" > "$tmp"
  mv "$tmp" "$REPO_ROOT/appinfo/info.xml"
  ok "Version: $cur → $new"
  warn "Don't forget to commit this version bump in git."
fi

# ---------- step 2: sync app → local oc_Apps + server ----------
if [[ $DO_APP -eq 1 ]]; then
  say "Syncing app source → local oc_Apps copy ($LOCAL_OC_APPS)"
  mkdir -p "$LOCAL_OC_APPS"
  rsync -a --delete \
        --exclude='__pycache__/' --exclude='*.pyc' \
        --exclude='.git/' --exclude='.DS_Store' --exclude='.scrapped/' \
        "$REPO_ROOT/" "$LOCAL_OC_APPS/"
  ok "Local copy in sync"

  say "Syncing app → server (${SERVER_APP_DIR})"
  rsync -a -e "$RSYNC_SSH" \
        --exclude='__pycache__/' --exclude='*.pyc' --exclude='.git/' \
        --rsync-path="sudo rsync" \
        "$REPO_ROOT/" "${SERVER_HOST}:${SERVER_APP_DIR}/"
  ok "Server app in sync"

  say "chown app dir → www-data"
  ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "sudo chown -R www-data:www-data ${SERVER_APP_DIR}"
  ok "Ownership set"

  say "occ upgrade + maintenance:mode --off (run together; bumping <version> locks the server otherwise)"
  ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
    sudo -u www-data php ${SERVER_OCC} upgrade 2>&1 | tail -3
    sudo -u www-data php ${SERVER_OCC} maintenance:mode --off 2>&1 | tail -1
  "
  ok "ownCloud upgrade done"
fi

# ---------- step 3: sync gbm-mx-api repo + reinstall in venv ----------
if [[ $DO_LIB -eq 1 ]]; then
  say "Syncing gbm-mx-api repo → server (${SERVER_GBM_API_SRC})"
  rsync -a -e "$RSYNC_SSH" \
        --exclude='.git/' --exclude='__pycache__/' --exclude='*.pyc' \
        --exclude='.venv/' --exclude='dist/' --exclude='build/' \
        --exclude='*.egg-info/' --exclude='.DS_Store' \
        --rsync-path="sudo rsync" \
        "${GBM_API_REPO}/" "${SERVER_HOST}:${SERVER_GBM_API_SRC}/"
  ok "gbm-mx-api source in sync"

  say "Reinstalling gbm-mx-api in ${SERVER_VENV}"
  ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
    sudo chown -R root:root ${SERVER_GBM_API_SRC}
    sudo ${SERVER_VENV}/bin/pip install --upgrade --force-reinstall --no-deps ${SERVER_GBM_API_SRC}/ 2>&1 | tail -3
  "
  ok "gbm-mx-api reinstalled"

  say "Smoke-test: import gbm_mx_api on server"
  ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
    ${SERVER_VENV}/bin/python -c 'import gbm_mx_api; print(\"OK\", gbm_mx_api.__version__ if hasattr(gbm_mx_api, \"__version__\") else \"(no version attr)\")'
  "
  ok "gbm-mx-api importable on server"
fi

# ---------- step 4: cache invalidation ----------
if [[ $DO_APP -eq 1 ]] || [[ -n "$DO_BUMP" ]]; then
  say "Invalidating ownCloud asset cache (occ app:enable gbm)"
  ssh "${SSH_OPTS[@]}" "$SERVER_HOST" \
    "sudo -u www-data php ${SERVER_OCC} app:enable gbm" | tail -3
  ok "App re-enabled"

  say "Reading version reported by occ"
  srv_ver=$(ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
    sudo grep '<version>' ${SERVER_APP_DIR}/appinfo/info.xml | sed -E 's|.*<version>([^<]+)</version>.*|\\1|'
  ")
  ok "App version on server: ${srv_ver}"
  echo
  echo "  Browsers cache /apps/gbm/js/dashboard.js?v=<hash>."
  echo "  The .htaccess in this repo forces revalidation (no-cache), so"
  echo "  changed files are picked up on next request. A version bump is"
  echo "  belt-and-suspenders, not strictly required after deploy."
fi

echo
ok "Deploy complete."
