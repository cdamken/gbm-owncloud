# Cutover: `gbm_next` → `gbm` (single-app consolidation)

**Date:** 2026-06-30
**Status:** Design approved, pending spec review
**Repo:** `gbm-owncloud` (app id currently `gbm_next`)

## Goal

Retire the original JSON-based ownCloud app (`gbm`, v0.14.37) and promote the
DB-based app (`gbm_next`, v0.15.8) to be the one and only GBM app, under the
clean id `gbm`. Do it quickly and safely, preserving the irreplaceable daily
portfolio-value history. Everything lands in GitHub.

## Background

Two ownCloud apps exist on the server:

- **`gbm`** (id `gbm`, v0.14.37) — original, JSON-file storage, no `lib/Db`. Not
  in git; lives only as the deployed copy + on the server.
- **`gbm_next`** (id `gbm_next`, v0.15.8) — this repo. DB-based: `lib/Db/`
  mappers, `IngestService`, `AnalysisService`, `LotsService`, occ commands,
  `appinfo/database.xml`.

A full diff established that `gbm_next` is **strictly ahead** of `gbm`: same
templates/JS surface, identical `fetch_wrapper.py` (so the TOTP/session flow is
the same — the remembered "TOTP problem" is not a regression), plus a corrected
Analysis page (the old app computed P&L as `value − (deposits − withdrawals)`,
which GBM can't report reliably and printed nearly the whole portfolio as
profit; the new app uses cost basis from GBM's reliable `average_cost`), plus
v0.15.x UX fixes (6-digit TOTP placeholder, settings password handling, browser
autofill suppression). There is no feature the old app has that the new one
lacks.

## Key facts that shape the migration

1. **DB tables are already named `oc_gbm_*`** (`gbm_accounts`, `gbm_holdings`,
   `gbm_orders`, `gbm_portfolio_snapshots`, `gbm_lots`, `gbm_dividends`,
   `gbm_transactions`, `gbm_securities`, and the snapshot/fx/benchmark tables).
   They were never named `gbm_next_*`. The table names are **not** tied to the
   app id. **No table rename is needed.**
2. **The snapshot history survives automatically.** Rows in
   `oc_gbm_portfolio_snapshots` are keyed by `user_id` (`carlos`, `feli`), not by
   app id or by the JSON data dir. Renaming the app id and clearing per-user
   credentials/JSON does **not** touch these rows.
3. **Only these things are bound to the id `gbm_next`:** the code (namespace
   `OCA\GbmNext`, `APPID` const, route names `gbm_next.*`, occ command names
   `gbm_next:*`, per-user data-dir name), the `oc_preferences`/`oc_appconfig`
   rows with `appid='gbm_next'`, and the filesystem folders `apps/gbm_next/` and
   `{datadir}/<uid>/gbm_next/`.
4. **Two users only:** `carlos` and `feli`. Re-entering GBM credentials once is
   trivial, so per-user credential/data migration is skipped — we delete and
   re-enter instead.

## Scope

### In scope
- Rename app id `gbm_next` → `gbm` across all code.
- Replace the app icon (drop the red "X" badge; use the clean GBM logo).
- Automate `lots` recompute after `ingest` in the web `/update` path.
- Server-side cutover: disable + rename old app to `gbm_old`; deploy the renamed
  app as `gbm`; clean orphaned prefs/appconfig and stale per-user JSON dirs.
- Version bump (minor), CHANGELOG entry, git hygiene, push to GitHub.

### Out of scope (deferred)
- Quality debt found in review: splitting the `ApiController` god-class,
  deduplicating JS formatters, broad error-handling and accessibility work, CI
  setup, PHP/DB unit tests. Tracked separately; not part of this cutover.
- Backup/`mysqldump` (explicitly skipped per owner; rollback relies on the
  retained disabled `gbm_old` app).

## Design

### A. Code rename (in repo, → GitHub)

`gbm_next` is a distinctive token, so a scoped global replace is safe (no
collision with the bare `gbm`):

- `OCA\GbmNext` → `OCA\Gbm` (namespace; ~30 files under `lib/`, plus
  `appinfo/app.php`, `appinfo/register_command.php`).
- `const APPID = 'gbm_next'` → `'gbm'`; `appId()`/`appDirName()` return value
  `'gbm_next'` → `'gbm'` (`lib/Service/GbmService.php`).
- Route names `gbm_next.*` → `gbm.*` (`lib/Controller/PageController.php`, the
  route map; 17 references including `analysis_data`).
- occ command names `gbm_next:ingest|analyze|lots` → `gbm:ingest|analyze|lots`
  (`lib/Command/{Ingest,Analyze,Lots}.php`).
- `appinfo/info.xml`: `<id>gbm</id>`, clean `<name>` (no "next"/"X" suffix).
- Per-user data-dir name const `gbm_next` → `gbm`; fix the stale "About" label
  in `templates/settings.php` to `{data_dir}/{user}/gbm/`.
- Stale comments referencing `gbm_next` in service files updated to `gbm`.
- **DB table names (`gbm_*`) are NOT changed.**

After the replace, `scripts/verify_dom_ids.py`, `scripts/verify_wiring.py`, and
`python -m unittest discover -s tests` must still pass (existing pre-deploy
gates).

### B. App icon

Replace `img/app.svg` with the clean GBM logo (no red X). The retired app's
`img/app.svg` is the clean source and can be reused verbatim.

### C. Automate `lots` after `ingest`

In `ApiController::update()`, after the successful `ingest->ingestForUser(uid)`
call, also invoke the FIFO/realized-P&L recompute (the logic behind
`gbm:lots`) for the same user, so realized P&L is no longer stale until a manual
`occ gbm:lots` run. Reuse `LotsService` (the occ command is a thin wrapper over
it). Failures are caught and logged like the existing ingest call — the update
itself still succeeds.

### D. Server-side cutover (scripted in `scripts/cutover.sh`, → GitHub)

A new script following the `scripts/deploy.sh` pattern (SSH array, steps chained
in one connection where they must be atomic). No backup step.

1. **Disable + retire old app:** `occ app:disable gbm`; rename
   `/var/www/owncloud/apps/gbm` → `apps/gbm_old` and set its `info.xml`
   `<id>` to `gbm_old`. It stays installed but disabled — the rollback safety
   net. It is **not** deleted by the script.
2. **Clean app-id-bound DB rows:**
   - `DELETE FROM oc_preferences WHERE appid='gbm_next';`
   - `DELETE FROM oc_preferences WHERE appid='gbm';`  (old app's saved creds)
   - `DELETE FROM oc_appconfig  WHERE appid='gbm_next';`
   - `DELETE FROM oc_appconfig  WHERE appid='gbm';`
   - The `oc_gbm_*` data tables (incl. `oc_gbm_portfolio_snapshots`) are **left
     untouched** — history preserved.
3. **Clean stale per-user JSON dirs:** for each uid (`carlos`, `feli`), remove
   `{datadir}/<uid>/gbm` (old stale JSON) and `{datadir}/<uid>/gbm_next`. The
   renamed app recreates `{datadir}/<uid>/gbm/` on the next fetch.
4. **Deploy renamed app as `gbm`:** rsync the renamed source to
   `apps/gbm/`, `chown -R www-data`, then in one SSH command `occ upgrade` +
   `occ maintenance:mode --off` (the `oc_gbm_*` tables already exist → schema
   sync is a no-op), then `occ app:enable gbm`.
5. **Post-cutover:** the 2 users open the app, re-enter GBM credentials in
   Settings, and run "Actualizar" once. The Analysis page shows the preserved
   daily history immediately (it reads `oc_gbm_portfolio_snapshots`).

### E. Rollback

If the cutover fails: `occ app:disable gbm`, re-enable `gbm_old`
(`occ app:enable gbm_old` after renaming its folder/id back if needed). The
`oc_gbm_*` data is never destructively altered, so no DB restore is required.
Owner judges this scenario unlikely.

### F. Version, changelog, git hygiene

- Bump `appinfo/info.xml` `<version>` with `--bump minor` (marks the cutover as
  a release milestone).
- Add a `CHANGELOG.md` entry describing the consolidation.
- Remove the two stranded macOS "conflicted copy" files from the git index:
  - `git rm --cached "appinfo/info (conflicted copy 2026-06-20 184730).xml"`
  - `git rm --cached "lib/Service/GbmService (conflicted copy 2026-06-20 184052).php"`
- Commit and push to `origin` (`cdamken/gbm-owncloud`).
- Update `CLAUDE.md`: drop the stale "downstream of gbm-dashboard / copy
  verbatim" framing (gbm is now the primary, DB-first app), fix the `gbm_next`/
  data-dir references, and correct the "CI green on push" line (no CI exists).

## Order of operations

1. Code: global rename + icon + `lots`-after-ingest + version/changelog (commit,
   do not push yet).
2. Run pre-deploy verifiers + unit tests locally; fix until green.
3. Run `scripts/cutover.sh` (server steps D1–D4).
4. Manual verification (D5): both users re-auth + fetch; confirm all pages
   render and the Analysis history is intact.
5. Push to GitHub; commit the git-hygiene cleanup.

## Verification / success criteria

- `occ app:list` shows `gbm` enabled and `gbm_old` disabled.
- Navigating to the app loads all pages (portafolio, órdenes, histórico,
  dividendos, libro diario, análisis, configuración, glosario) without errors.
- After each user re-enters creds and runs "Actualizar":
  - Positions/orders/dividends/transactions populate from a fresh fetch.
  - Analysis page shows the **pre-cutover** daily value history (proof the
    snapshot rows survived).
  - Realized P&L is current without a manual `occ gbm:lots` run (proof C works).
- No `gbm_next` references remain in the deployed app code.
- Repo pushed; `git status` clean (conflicted-copy files gone).

## Risks

- **App-id rename leaves orphaned ownCloud state.** Mitigated by explicitly
  deleting `gbm_next`/`gbm` rows from `oc_preferences` and `oc_appconfig` in D2.
- **`occ upgrade` on an app whose tables already exist.** ownCloud schema sync is
  additive and will not drop populated tables; verified the table names are
  unchanged, so this is a no-op.
- **Data-dir name collision** (`gbm_next/` → `gbm/` while old `gbm/` exists).
  Mitigated by removing both per-user dirs in D3 before the renamed app runs.
