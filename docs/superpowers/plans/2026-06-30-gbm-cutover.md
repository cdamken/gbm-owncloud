# GBM Cutover (`gbm_next` → `gbm`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote the DB-based app `gbm_next` to be the single GBM app under the clean id `gbm`, retiring the old JSON-based `gbm` app, preserving the daily snapshot history, and landing everything in GitHub.

**Architecture:** A code rename (`gbm_next`→`gbm`, `GbmNext`→`Gbm`) in this repo, one small functional addition (`lots` recompute after `ingest` in the web update path), then a scripted server-side cutover that disables/renames the old app, deploys the renamed app, and clears app-id-bound state (prefs/appconfig + stale per-user JSON dirs). DB tables are already named `oc_gbm_*` and are left untouched, so the snapshot history survives automatically.

**Tech Stack:** PHP (ownCloud 10 app framework), JS (vanilla), Python 3.11 (`gbm-mx-api` consumer), MySQL/MariaDB (ownCloud DB), bash deploy scripts, SSH to `cloud.damken.com:2222`.

## Global Constraints

- App id target: `gbm` (folder `apps/gbm/`, `<id>gbm</id>`). Old app retired to `gbm_old` (disabled, **not deleted**).
- DB table names `gbm_*` (`oc_gbm_*` live) are **NOT** renamed — they are not tied to the app id.
- Snapshot history lives in `oc_gbm_portfolio_snapshots`, keyed by `user_id` (`carlos`, `feli`) — must remain untouched.
- Two users only: `carlos`, `feli`. Their GBM credentials and per-user JSON dirs are deleted, not migrated; they re-enter creds and re-fetch once.
- No `mysqldump` backup step (owner decision). Rollback relies on the retained disabled `gbm_old`.
- The replace token is the distinctive `gbm_next` / `GbmNext`. Do **not** touch `gbm-mx-api`, `gbm-dashboard` (hyphenated), or the `gbm_*` DB table names.
- All code, identifiers, docstrings, commits in **English**; UI strings in **Spanish**.
- Server facts: SSH host `carlos@cloud.damken.com`, port `2222`, key `~/.ssh/id_ed25519`; apps at `/var/www/owncloud/apps/`; occ run as `sudo -u www-data php /var/www/owncloud/occ ...`.
- Pre-deploy gates (must stay green): `python3 scripts/verify_dom_ids.py`, `python3 scripts/verify_wiring.py`, `python3 -m unittest discover -s tests`.

---

## File Structure

**Modified (code rename — all files containing `gbm_next`/`GbmNext`):**
- `appinfo/app.php`, `appinfo/info.xml`, `appinfo/register_command.php`
- `lib/Application.php`
- `lib/Controller/ApiController.php`, `lib/Controller/PageController.php`
- `lib/Service/{GbmService,BaseOwnCloudService,AnalysisService,IngestService,LotsService}.php`
- `lib/Db/*.php` (entities + mappers — namespace only; table-name strings stay)
- `lib/Command/{Ingest,Analyze,Lots}.php`
- `lib/Analytics/{FifoLots,PortfolioAnalytics}.php`
- `templates/settings.php` (data-dir label)

**Modified (functional):**
- `lib/Controller/ApiController.php` — inject `LotsService`, call `recompute()` after `ingestForUser()` in `update()`.

**Replaced:**
- `img/app.svg` — clean GBM logo (no red X).

**Created:**
- `scripts/cutover.sh` — server-side cutover.

**Docs:**
- `CHANGELOG.md` (new entry), `CLAUDE.md` (de-stale), `appinfo/info.xml` (version bump).
- Git index cleanup of two stranded "conflicted copy" files.

---

## Task 1: Global code rename `gbm_next` → `gbm`

**Files:**
- Modify: every tracked file under `appinfo/`, `lib/`, `templates/` containing `gbm_next` or `GbmNext` (see File Structure).
- Verify: `scripts/verify_dom_ids.py`, `scripts/verify_wiring.py`, `tests/`

**Interfaces:**
- Produces: namespace `OCA\Gbm\…`, `GbmService::APPID === 'gbm'`, `appDirName() === 'gbm'`, route names `gbm.*`, occ command names `gbm:ingest|analyze|lots`. Later tasks and the cutover script rely on these exact strings.

- [ ] **Step 1: Confirm the blast radius before editing**

Run:
```bash
cd ~/damkencloud/Claude/gbm-owncloud
grep -rlE 'gbm_next|GbmNext' --include='*.php' --include='*.js' --include='*.xml' . | grep -v '/\.git/' | sort
```
Expected: the file list from the plan's File Structure (≈33 files). Note it — every one must be clean after the replace.

- [ ] **Step 2: Apply the scoped replace**

Run (BSD sed on macOS; `-i ''` keeps no backup):
```bash
cd ~/damkencloud/Claude/gbm-owncloud
grep -rlE 'gbm_next|GbmNext' --include='*.php' --include='*.js' --include='*.xml' . | grep -v '/\.git/' \
  | while read -r f; do
      sed -i '' -e 's/GbmNext/Gbm/g' -e 's/gbm_next/gbm/g' "$f"
    done
```
This turns `OCA\GbmNext`→`OCA\Gbm`, `'gbm_next'`→`'gbm'`, route prefixes `gbm_next.`→`gbm.`, command names `gbm_next:`→`gbm:`, and the `appDirName()` return `gbm_next`→`gbm`. Table-name strings (`gbm_accounts`, etc.) contain no `gbm_next`, so they are untouched.

- [ ] **Step 3: Verify no token survives in code**

Run:
```bash
grep -rnE 'gbm_next|GbmNext' --include='*.php' --include='*.js' --include='*.xml' . | grep -v '/\.git/'
```
Expected: **no output**. (If anything prints, fix it by hand.)

- [ ] **Step 4: Verify table names were NOT damaged**

Run:
```bash
grep -rhoE "gbm_[a-z_]+" lib/Db/*Mapper.php | sort -u
```
Expected: `gbm_accounts gbm_dividends gbm_holdings gbm_lots gbm_orders gbm_portfolio_snapshots gbm_securities gbm_transactions` — i.e. unchanged real table names, none mangled to a double prefix.

- [ ] **Step 5: Run the pre-deploy gates**

Run:
```bash
python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py && python3 -m unittest discover -s tests
```
Expected: all pass (verifiers report OK, unittest `OK`).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: rename app id gbm_next → gbm (cutover step 1)"
```

---

## Task 2: Replace the app icon with the clean GBM logo

**Files:**
- Replace: `img/app.svg`

**Interfaces:**
- Consumes: nothing. Produces: a clean navigation icon (no red "X" badge).

- [ ] **Step 1: Copy the clean logo from the old app**

The old app's icon is the clean GBM logo (the red X was added only to distinguish `gbm_next`).
Run:
```bash
cd ~/damkencloud/Claude/gbm-owncloud
cp ~/damkencloud/oc_Apps/gbm/img/app.svg img/app.svg
```

- [ ] **Step 2: Confirm the X badge is gone**

Run:
```bash
grep -iE 'red|#e|x-badge|<text' img/app.svg | head
```
Expected: no red-X / badge markup that was unique to the `gbm_next` icon. (Open it visually if unsure.)

- [ ] **Step 3: Commit**

```bash
git add img/app.svg
git commit -m "feat: use clean GBM logo (drop gbm_next X badge)"
```

---

## Task 3: Automate `lots` recompute after `ingest` in the web update

**Files:**
- Modify: `lib/Controller/ApiController.php` (constructor + `update()`)

**Interfaces:**
- Consumes: `LotsService::recompute(string $uid): array` (already exists, used by `occ gbm:lots`).
- Produces: realized P&L / FIFO lots are refreshed on every successful web "Actualizar", not only via the occ command.

- [ ] **Step 1: Add the `use` import and inject `LotsService`**

In `lib/Controller/ApiController.php`, add near the other service imports:
```php
use OCA\Gbm\Service\LotsService;
```
Add the property next to `$ingest`/`$analysis`:
```php
	private $lots;
```
Change the constructor signature and body:
```php
	public function __construct(string $appName, IRequest $request, GbmService $gbm, IngestService $ingest, AnalysisService $analysis, LotsService $lots) {
		parent::__construct($appName, $request);
		$this->gbm = $gbm;
		$this->ingest = $ingest;
		$this->analysis = $analysis;
		$this->lots = $lots;
	}
```

- [ ] **Step 2: Call `recompute()` after `ingestForUser()`**

In `update()`, inside the `if ($httpStatus === Http::STATUS_OK)` block, extend the existing try so the DB stays fully in sync (ingest first, then lots):
```php
			// Keep the DB in sync with the freshly-written JSON: ingest the
			// new state/events, then recompute FIFO lots + realized P&L so the
			// analytics are current without a manual `occ gbm:lots`. A DB
			// hiccup must NOT fail the fetch.
			try {
				$uid = $this->gbm->currentUserId();
				$this->ingest->ingestForUser($uid);
				$this->lots->recompute($uid);
			} catch (\Throwable $e) {
				\OC::$server->getLogger()->logException($e, ['app' => 'gbm']);
			}
```
(Replace the existing `try { $this->ingest->ingestForUser(...); } catch ...` block with the above.)

- [ ] **Step 3: Confirm DI resolves (no syntax/type errors)**

Run:
```bash
php -l lib/Controller/ApiController.php
```
Expected: `No syntax errors detected in lib/Controller/ApiController.php`.

- [ ] **Step 4: Run the pre-deploy gates**

Run:
```bash
python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py && python3 -m unittest discover -s tests
```
Expected: all pass. (Runtime DI is verified on the server in Task 6.)

- [ ] **Step 5: Commit**

```bash
git add lib/Controller/ApiController.php
git commit -m "feat: recompute FIFO lots after ingest on web update"
```

---

## Task 4: Version bump + changelog

**Files:**
- Modify: `appinfo/info.xml` (`<version>`), `CHANGELOG.md`

**Interfaces:**
- Consumes: nothing. Produces: a new release version string used by ownCloud's asset cache-buster.

- [ ] **Step 1: Read the current version**

Run:
```bash
grep -m1 '<version>' appinfo/info.xml
```
Note the value (e.g. `0.15.8`). The cutover is a **minor** bump → next is `0.16.0`.

- [ ] **Step 2: Set the new version**

Edit `appinfo/info.xml`: change `<version>0.15.8</version>` to `<version>0.16.0</version>` (use the actual current value + minor bump).

- [ ] **Step 3: Add the CHANGELOG entry**

Prepend under the top of `CHANGELOG.md` (keep the existing Keep-a-Changelog format):
```markdown
## [0.16.0] - 2026-06-30

### Changed
- **Cutover**: this app is now the single GBM app, id `gbm` (was the parallel
  staging app `gbm_next`). The original JSON-based `gbm` app is retired to
  `gbm_old` (disabled). Namespace, routes, occ commands (`gbm:ingest|analyze|
  lots`) and the per-user data dir renamed `gbm_next` → `gbm`. DB tables
  (`oc_gbm_*`) and the daily snapshot history are unchanged.
- App icon: dropped the red "X" badge that distinguished `gbm_next`.

### Added
- Web "Actualizar" now recomputes FIFO lots + realized P&L right after ingest,
  so realized P&L is current without a manual `occ gbm:lots`.
```

- [ ] **Step 4: Commit**

```bash
git add appinfo/info.xml CHANGELOG.md
git commit -m "chore: bump to 0.16.0 (cutover release)"
```

---

## Task 5: Write `scripts/cutover.sh`

**Files:**
- Create: `scripts/cutover.sh`

**Interfaces:**
- Consumes: the renamed code (Tasks 1–4) and the server facts in Global Constraints.
- Produces: a one-shot, re-runnable-ish server cutover. Does **not** push to git.

- [ ] **Step 1: Write the script**

Create `scripts/cutover.sh` (model the SSH/rsync style on `scripts/deploy.sh`):
```bash
#!/usr/bin/env bash
# cutover.sh — promote gbm_next → gbm as the single GBM ownCloud app.
#
# Retires the old JSON app to gbm_old (disabled, NOT deleted), deploys this
# renamed repo as apps/gbm, and clears app-id-bound state. DB tables (oc_gbm_*)
# and the snapshot history are left untouched. No backup (owner decision).
#
# Run AFTER the code rename (Tasks 1-4) is committed. Run from the repo root.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVER_HOST="carlos@cloud.damken.com"
SERVER_PORT="2222"
SERVER_KEY="${HOME}/.ssh/id_ed25519"
APPS="/var/www/owncloud/apps"
OCC="/var/www/owncloud/occ"
USERS=(carlos feli)
SSH_OPTS=(-A -i "${SERVER_KEY}" -p "${SERVER_PORT}")
RSYNC_SSH="ssh -A -i ${SERVER_KEY} -p ${SERVER_PORT}"

say() { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }

# 0. Sanity: the local repo must already be renamed to id 'gbm'.
grep -q '<id>gbm</id>' "${REPO_ROOT}/appinfo/info.xml" \
  || { echo "ERROR: appinfo/info.xml id is not 'gbm' — run the code rename first."; exit 1; }
! grep -rqE 'gbm_next|GbmNext' --include='*.php' "${REPO_ROOT}/lib" \
  || { echo "ERROR: 'gbm_next' still present in lib/ — rename incomplete."; exit 1; }

# 1. Retire old app: disable, rename folder + its info.xml id to gbm_old.
say "Retiring old app → gbm_old (disabled)"
ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
  set -e
  sudo -u www-data php ${OCC} app:disable gbm || true
  if [ -d ${APPS}/gbm ]; then
    sudo mv ${APPS}/gbm ${APPS}/gbm_old
    sudo sed -i 's#<id>gbm</id>#<id>gbm_old</id>#' ${APPS}/gbm_old/appinfo/info.xml
  fi
"

# 2. Clear app-id-bound DB rows (prefs + appconfig). Tables oc_gbm_* untouched.
say "Clearing oc_preferences / oc_appconfig for gbm and gbm_next"
ssh "${SSH_OPTS[@]}" "$SERVER_HOST" '
  set -e
  cfg=/var/www/owncloud/config/config.php
  DB=$(sudo php -r "\$c=include \"'"'"'$cfg'"'"'"\"; echo \$c[\"dbname\"];")
  DH=$(sudo php -r "\$c=include \"'"'"'$cfg'"'"'"\"; echo \$c[\"dbhost\"];")
  DU=$(sudo php -r "\$c=include \"'"'"'$cfg'"'"'"\"; echo \$c[\"dbuser\"];")
  DP=$(sudo php -r "\$c=include \"'"'"'$cfg'"'"'"\"; echo \$c[\"dbpassword\"];")
  mysql -h"$DH" -u"$DU" -p"$DP" "$DB" -e "
    DELETE FROM oc_preferences WHERE appid IN ('"'"'gbm'"'"','"'"'gbm_next'"'"');
    DELETE FROM oc_appconfig  WHERE appid IN ('"'"'gbm'"'"','"'"'gbm_next'"'"');
  "
'
```
(Continue in Step 2.)

- [ ] **Step 2: Append the per-user dir cleanup + deploy to the script**

Append to `scripts/cutover.sh`:
```bash
# 3. Remove stale per-user JSON dirs (old gbm/ and gbm_next/). The renamed app
#    recreates {datadir}/<uid>/gbm/ on the next fetch. DB history is in MySQL.
say "Removing stale per-user JSON dirs"
DATADIR=$(ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "sudo php -r '\$c=include \"/var/www/owncloud/config/config.php\"; echo \$c[\"datadirectory\"];'")
for uid in "${USERS[@]}"; do
  ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
    sudo rm -rf ${DATADIR}/${uid}/gbm ${DATADIR}/${uid}/gbm_next || true
  "
done

# 4. Deploy the renamed app as apps/gbm and enable it.
say "Deploying renamed app → ${APPS}/gbm"
rsync -a --delete -e "$RSYNC_SSH" --exclude='.git/' --exclude='DATA/' \
  "${REPO_ROOT}/" "${SERVER_HOST}:/tmp/gbm_deploy/"
ssh "${SSH_OPTS[@]}" "$SERVER_HOST" "
  set -e
  sudo rm -rf ${APPS}/gbm
  sudo mv /tmp/gbm_deploy ${APPS}/gbm
  sudo chown -R www-data:www-data ${APPS}/gbm
  sudo -u www-data php ${OCC} upgrade 2>&1 | tail -3
  sudo -u www-data php ${OCC} maintenance:mode --off 2>&1 | tail -1
  sudo -u www-data php ${OCC} app:enable gbm 2>&1 | tail -3
  sudo -u www-data php ${OCC} app:list 2>&1 | grep -E 'gbm|gbm_old' || true
"
say "Cutover done. Both users must re-enter GBM creds and run Actualizar once."
```

- [ ] **Step 3: Make it executable and lint it**

Run:
```bash
chmod +x scripts/cutover.sh
bash -n scripts/cutover.sh && echo "syntax OK"
```
Expected: `syntax OK`.

- [ ] **Step 4: Commit**

```bash
git add scripts/cutover.sh
git commit -m "feat: add scripts/cutover.sh (gbm_next → gbm server cutover)"
```

---

## Task 6: Execute the cutover + manual verification

**Files:** none (operational).

**Interfaces:** Consumes Tasks 1–5. Produces the live single `gbm` app.

- [ ] **Step 1: Run the cutover**

Run:
```bash
cd ~/damkencloud/Claude/gbm-owncloud
./scripts/cutover.sh
```
Expected: each `▶` step completes; the final `app:list` shows `gbm` under **Enabled** and `gbm_old` under **Disabled**. The `occ upgrade` may print a fake security-advisory banner — ignore it.

- [ ] **Step 2: Verify app state**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com \
  "sudo -u www-data php /var/www/owncloud/occ app:list | grep -E 'gbm|gbm_old'"
```
Expected: `gbm` enabled, `gbm_old` disabled.

- [ ] **Step 3: Manual UI check (both users)**

In a browser, for `carlos` and `feli`:
1. Open the GBM app — all pages load (portafolio, órdenes, histórico, dividendos, libro diario, análisis, configuración, glosario), no 500s.
2. Settings → enter GBM email + password → save (no "Contraseña muy corta" error).
3. "Actualizar" → enter 6-digit TOTP → fetch completes (`status: ok`).
4. Positions/orders/dividends/transactions populate.
5. **Analysis page shows the pre-cutover daily value history** (proves `oc_gbm_portfolio_snapshots` survived).
6. Realized P&L on the Analysis/relevant page is current **without** running `occ gbm:lots` (proves Task 3).

- [ ] **Step 4: Confirm no `gbm_next` remains live**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com \
  "grep -rlE 'gbm_next|GbmNext' /var/www/owncloud/apps/gbm/lib 2>/dev/null | head; echo done"
```
Expected: only `done` (no files listed).

---

## Task 7: Git hygiene, docs de-stale, push

**Files:**
- Remove from index: the two macOS "conflicted copy" files.
- Modify: `CLAUDE.md`

**Interfaces:** Consumes a verified-working cutover (Task 6). Produces the pushed final state.

- [ ] **Step 1: Remove the stranded conflicted-copy files from the index**

Run:
```bash
cd ~/damkencloud/Claude/gbm-owncloud
git rm --cached "appinfo/info (conflicted copy 2026-06-20 184730).xml" \
                "lib/Service/GbmService (conflicted copy 2026-06-20 184052).php"
```

- [ ] **Step 2: De-stale `CLAUDE.md`**

Edit `CLAUDE.md`:
- Remove/soften the "DOWNSTREAM of gbm-dashboard / copy verbatim" framing — `gbm` is now the primary, DB-first app, no longer a verbatim port.
- Fix every `gbm` deploy-path / data-dir reference that should now read `gbm` (the deploy targets, `{datadir}/<uid>/gbm/`).
- Correct the "Recently resolved" line claiming "CI green on push (2026-06-05)" — there is no `.github/workflows/`; state that the pre-deploy gates run only via `scripts/deploy.sh`.
- Add a one-line note that `gbm_old` is the retired JSON app (disabled).

- [ ] **Step 3: Commit and push**

```bash
git add -A
git commit -m "chore: drop conflicted-copy files + de-stale CLAUDE.md post-cutover"
git push origin main
```
Expected: push succeeds to `cdamken/gbm-owncloud`.

- [ ] **Step 4: Final git status check**

Run:
```bash
git status --short
```
Expected: clean (no conflicted-copy entries, no uncommitted changes).

---

## Self-Review notes

- **Spec coverage:** A (rename) → Tasks 1,4; B (logo) → Task 2; C (server migration, no backup, prefs/data delete) → Tasks 5,6; D (`gbm_old` disabled, not deleted) → Task 5; E (rollback) → retained `gbm_old`; F (lots automation) → Task 3; version/changelog/git-hygiene/CLAUDE.md → Tasks 4,7. Snapshot-preservation success criterion → Task 6 Step 3.6.
- **Deferred (per spec):** ApiController split, JS dedup, error-handling/a11y, CI, PHP/DB unit tests — not in this plan by design.
- **Type consistency:** `LotsService::recompute(string $uid): array` used in Task 3 matches its definition; `currentUserId()`, `ingestForUser()` match existing signatures; route/command/namespace strings produced in Task 1 are consumed verbatim by Tasks 3,5.
