#!/usr/bin/env bash
# cutover.sh — promote gbm_next → gbm as the single GBM ownCloud app.
#
# Retires the old JSON app to gbm_old (disabled, NOT deleted), deploys this
# renamed repo as apps/gbm, and clears app-id-bound state (oc_preferences /
# oc_appconfig rows for 'gbm' and 'gbm_next', plus the stale per-user JSON
# dirs). The oc_gbm_* DB tables — including the daily snapshot history — are
# left untouched, so history survives. No mysqldump backup (owner decision);
# rollback relies on the retained disabled gbm_old.
#
# Run AFTER the code rename (Tasks 1-4) is committed. Run from the repo root.
# The two app users (carlos, feli) must re-enter GBM creds + run Actualizar
# once afterwards.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_HOST="carlos@cloud.damken.com"
SERVER_PORT="2222"
SERVER_KEY="${HOME}/.ssh/id_ed25519"
APPS="/var/www/owncloud/apps"
OCC="/var/www/owncloud/occ"
CONFIG="/var/www/owncloud/config/config.php"
USERS=(carlos feli)
SSH_OPTS=(-A -i "${SERVER_KEY}" -p "${SERVER_PORT}")
RSYNC_SSH="ssh -A -i ${SERVER_KEY} -p ${SERVER_PORT}"

say() { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# ── 0. Sanity: the local repo must already be renamed to id 'gbm'. ──────────
grep -q '<id>gbm</id>' "${REPO_ROOT}/appinfo/info.xml" \
  || die "appinfo/info.xml id is not 'gbm' — run the code rename (Task 1) first."
if grep -rqE 'gbm_next|GbmNext' --include='*.php' "${REPO_ROOT}/lib"; then
  die "'gbm_next' still present in lib/ — rename incomplete."
fi
[[ -f "$SERVER_KEY" ]] || die "SSH key not found at $SERVER_KEY"

# ── 1. Retire old app: disable, rename folder + its info.xml id to gbm_old. ─
say "Retiring old app → gbm_old (disabled, kept as rollback)"
ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
  set -e
  sudo -u www-data php ${OCC} app:disable gbm || true
  if [ -d ${APPS}/gbm ]; then
    sudo rm -rf ${APPS}/gbm_old
    sudo mv ${APPS}/gbm ${APPS}/gbm_old
    sudo sed -i 's#<id>gbm</id>#<id>gbm_old</id>#' ${APPS}/gbm_old/appinfo/info.xml
  fi
"

# ── 2. Clear app-id-bound DB rows via a PHP helper (robust quoting). ────────
#    The helper reads config.php with www-data, deletes the prefs/appconfig
#    rows for both ids, and prints the datadirectory on stdout.
say "Clearing oc_preferences / oc_appconfig for gbm + gbm_next (tables untouched)"
ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "cat > /tmp/gbm_cutover.php" <<'PHP'
<?php
// One-shot cutover DB cleanup. Deletes only id-bound config rows; never the
// oc_gbm_* data tables. Prints the datadirectory so the caller can clean dirs.
// ownCloud's config.php assigns to a $CONFIG global and does NOT return the
// array, so `include` yields int(1). Read the global after including.
require '/var/www/owncloud/config/config.php';
$cfg = $CONFIG;
$host = $cfg['dbhost'];
$port = '';
if (strpos($host, ':') !== false) { list($host, $port) = explode(':', $host, 2); }
$dsn = "mysql:host={$host};dbname={$cfg['dbname']}";
if ($port !== '') { $dsn .= ";port={$port}"; }
$pdo = new PDO($dsn, $cfg['dbuser'], $cfg['dbpassword']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pfx = isset($cfg['dbtableprefix']) ? $cfg['dbtableprefix'] : 'oc_';
$pdo->exec("DELETE FROM {$pfx}preferences WHERE appid IN ('gbm','gbm_next')");
$pdo->exec("DELETE FROM {$pfx}appconfig  WHERE appid IN ('gbm','gbm_next')");
echo $cfg['datadirectory'], "\n";
PHP
DATADIR="$(ssh "${SSH_OPTS[@]}" "$SERVER_HOST" \
  "sudo -u www-data php /tmp/gbm_cutover.php && sudo rm -f /tmp/gbm_cutover.php" \
  | tail -1)"
[[ -n "$DATADIR" ]] || die "Could not determine datadirectory — aborting before dir cleanup."
say "datadirectory = ${DATADIR}"

# ── 3. Remove stale per-user JSON dirs (old gbm/ and gbm_next/). ────────────
#    The renamed app recreates {datadir}/<uid>/gbm/ on the next fetch. History
#    is in MySQL, not these dirs.
say "Removing stale per-user JSON dirs"
for uid in "${USERS[@]}"; do
  ssh "${SSH_OPTS[@]}" "$SERVER_HOST" \
    "sudo rm -rf ${DATADIR}/${uid}/gbm ${DATADIR}/${uid}/gbm_next || true"
done

# ── 4. Deploy the renamed app as apps/gbm and enable it. ────────────────────
say "Deploying renamed app → ${APPS}/gbm"
ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "rm -rf /tmp/gbm_deploy"
rsync -a --delete -e "$RSYNC_SSH" \
  --exclude='.git/' --exclude='DATA/' --exclude='__pycache__/' \
  "${REPO_ROOT}/" "${SERVER_HOST}:/tmp/gbm_deploy/"
ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
  set -e
  sudo rm -rf ${APPS}/gbm
  sudo mv /tmp/gbm_deploy ${APPS}/gbm
  sudo chown -R www-data:www-data ${APPS}/gbm
  sudo -u www-data php ${OCC} upgrade 2>&1 | tail -3 || true
  sudo -u www-data php ${OCC} maintenance:mode --off 2>&1 | tail -1
  # The oc_gbm_* tables are preserved from gbm_next (history lives there), so
  # the fresh-install schema path (createDbFromStructure) would fail with
  # 'table already exists'. Register the app as already installed at its
  # current version so app:enable adopts the existing tables instead of
  # recreating them.
  VER=\$(grep -oE '<version>[^<]+</version>' ${APPS}/gbm/appinfo/info.xml | sed -E 's#</?version>##g')
  sudo -u www-data php ${OCC} config:app:set gbm installed_version --value=\"\${VER}\"
  sudo -u www-data php ${OCC} app:enable gbm 2>&1 | tail -3
  echo '--- app:list (gbm / gbm_old) ---'
  sudo -u www-data php ${OCC} app:list 2>&1 | grep -E 'gbm|gbm_old' || true
"

say "Cutover done. Next: each user (carlos, feli) opens the app, re-enters GBM"
say "credentials in Settings, and runs 'Actualizar' once. The Analysis page will"
say "show the preserved daily snapshot history."
