# Changelog

Todos los cambios notables de este proyecto se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/), y el versioning
sigue [SemVer](https://semver.org/).

## [0.14.28] — 2026-06-15

Reorganización: dividendos en la página de Dividendos, análisis en
Análisis (sin repetir). La gráfica mensual neto+ISR, la proyección 12m
y el yield-on-cost vivían en Análisis; ahora están en la página
Dividendos junto a sus KPIs all-time y la tabla. La página Análisis
queda enfocada en allocation + la trayectoria de cost-basis con
benchmark. `PageController` ahora carga Chart.js también en la página
de Dividendos, y `js/dividends.js` lee `positions` para el yield-on-cost.

## [0.14.27] — 2026-06-15

Benchmark overlay fix — la comparación S&P 500 / NAFTRAC nunca se
renderizaba. `ApiController::benchmark()` emitía el shape crudo de Yahoo
`{t: epoch, c: close}`, pero el `analytics.js` compartido lee `h.date` /
`h.close` (el shape que produce `server.py` de gbm-dashboard). Así
`benchByDay` quedaba indexado por `undefined`, todo replay devolvía `{}`,
y ambos datasets del overlay se descartaban en silencio — el chart solo
mostraba la línea de cost-basis. Ahora emite `{date: 'YYYY-MM-DD',
close: float}` como el Dashboard upstream. El dashboard local siempre
estuvo bien; solo este port divergía (no era port verbatim).

## [0.14.26] — 2026-06-11

Layering-contract fix: the Settings "About" version probe used
`shell_exec(escapeshellarg(...))` — the only `shell_exec` in any of
the three owncloud ports. Replaced with `GbmService::libVersion()`,
which uses the inherited `runProcess()` (proc_open, array argv) like
every other subprocess. Surfaced by Portfolio-Master's new
`scripts/verify_layering.sh`.

No behavior change — the About list still shows the gbm-mx-api version.

## [0.14.25] — 2026-06-11

Cross-trio parity: **Yield on Cost** stat on the Analysis page.

Forward 12-month dividend ÷ cost basis (avg_price × quantity over
all positions). TR's analytics already showed this; GBM had the
forward dividend projection but not the yield-on-cost ratio. Added
as a fourth stat in the dividends row, blue like TR's `cf-yoc`.
Verbatim port from gbm-dashboard@97b95f2.

(Geographic-allocation parity does NOT apply to GBM — GBM positions
carry `issue_id`, not ISIN, so domicile bucketing has no input. That
gap is TR↔SC only.)

## [0.14.24] — 2026-06-10

Quality pass — applies the cross-repo code-review findings.

### Fixed

- **ESC no cerraba el modal TOTP** (`js/update_flow.js`): the
  keydown handler still checked `classList.contains('open')` after
  v0.14.23 switched the modal to `.show` — leftover from the same
  class-name mismatch. ESC works again.
- **submitTotp null guards**: naked `getElementById(...).value`
  derefs could throw and silently kill the flow if modal injection
  failed — same TypeError class as the settings-btn bug.
- **flash() null guard** (`js/settings.js`): callers pass `$(id)`
  which may be null.
- **init() re-entry guard**: a double script load duplicated every
  listener (two `/update` POSTs per click).
- **UI strings**: two leftover English/Spanglish error messages in
  the TOTP modal now read in Spanish ("El código debe tener
  exactamente 6 dígitos", "GBM+ limitó los intentos de login").

### Performance

- `showToast` skips the DOM write when the stage text is unchanged
  (the 500 ms progress poll rewrote the same string for minutes).

## [0.14.23] — 2026-06-10

Fix: TOTP modal didn't appear when GBM+ asked for a security code.

Carlos: "Le estoy dando actualizar y no aparece la ventana para el
código." Server-side was correct (`fetch_wrapper.py` exited 10
`MFA_REQUIRED`, `ApiController` mapped it to HTTP 401
`status: mfa_required`). Frontend `update_flow.js` called
`openTotpModal()` which added the CSS class **`.open`** to
`#totp-modal` — but `css/dashboard.css` only defines
`#gbm-app .modal-backdrop.show { display: flex }`, not `.open`.

The class-name mismatch slipped in with Refactor A (v0.14.16) when
`update_flow.js` was ported verbatim from `Trade-Republic-owncloud`
where the matching CSS rule IS `.modal-backdrop.open`. GBM kept the
old `.show` convention (used by the config modal, also driven by
`dashboard.js::openConfigModal` which still works).

Fix: change the two callsites in `js/update_flow.js` to add/remove
`.show` instead of `.open`. Aligns with GBM's CSS without changing
the upstream-portable JS structure.

Verified: verify_dom_ids + verify_wiring + 10/10 unit tests green.

## [0.14.22] — 2026-06-10

Fix: `last_update.date` written as UTC ISO 8601 with explicit `Z`.

Carlos reported the staleness chip showing "Updated 07:21 AM · 2 h
ago" right after clicking Actualizar. Root cause: `fetch_wrapper.py`
wrote `datetime.now()` (naive local-of-server) and cloud.damken.com
runs UTC. Browser JS parsed `2026-06-10 07:21:43` as the user's
local timezone, displaying the UTC wall-clock instead of his actual
local time.

Fix: emit `2026-06-10T07:21:43Z`. The `Z` lets JS parse as UTC;
`toLocaleTimeString()` renders in the user's TZ. The read-side
parser also accepts both old space-separated and new T-separated
formats so the next incremental fetch doesn't choke on stale on-disk
files.

Verbatim port from `gbm-dashboard@b1de14a`.

## [0.14.21] — 2026-06-10

Per-page CSV exports (gbm-owncloud closes upstream
[gbm-dashboard#1](https://github.com/cdamken/gbm-dashboard/issues/1)).

### What changed

- New route `api#exportPageCsv` at `GET /export/{kind}.csv`.
- 5 kinds whitelisted: `ordenes`, `historico`, `dividendos`,
  `transacciones`, `posiciones`.
- Each page's controls bar gets a "↓ Exportar CSV" link rendering
  inline — no JS, read-only GET.
- Verbatim port of `gbm-dashboard/app/server.py::_handle_export_page_csv()`.

### Why not replacing /export/transactions.csv

The existing 13-column SAT-shaped `transactions.csv` stays — it's
the right shape for the accountant. The new per-page CSVs are the
analyst's shape (only the columns visible on screen).

Verified: php -l clean, verify_dom_ids, verify_wiring, 10/10 unit
tests green.

## [0.14.20] — 2026-06-10

`BaseOwnCloudService` now also exposes `EXIT_TIMEOUT` (alias for the
existing `EXIT_RATE_LIMITED`, both = 21). The two names track
semantically distinct conditions — TR's API genuinely emits HTTP
429 rate limits, while GBM and Scalable hit the same code as a
wrapper timeout. PHP forbids self-referential `const` so the
literal `21` is duplicated.

Added in preparation for the Scalable-Capital-owncloud port to
the shared base class (Scalable's ApiController uses `EXIT_TIMEOUT`
naturally — it has no rate-limit semantics).

Also bumps the matching constant in `tests/test_fetch_wrapper_smoke.py`'s
PHP-drift check to look for either name. All 10 unit tests still
green.

## [0.14.19] — 2026-06-10

Refactor D: broke up the 216-line `renderNetWorthChart()` in
`js/analysis.js` into four single-purpose helpers + a thin
orchestrator.

### What changed

- `_buildNetWorthDailyMap(rows)` — walks transactions and builds
  the cost-basis trajectory (deposits/withdrawals/repos/MM-sweeps
  filtered out — same noise filter as Libro Diario).
- `_filterRangeDates(dailyMap, range)` — pads to today + applies
  the 1M/3M/6M/1Y/All range filter.
- `_netWorthDataset(label, data, color, isUserCurve)` — the
  Chart.js dataset shape used for the user's cost-basis line and
  the two benchmark overlays (NAFTRAC dashed, S&P 500 TR dashed).
- `_netWorthChartOptions(datasetsCount)` — Chart.js options. Pure
  config, no logic.

`renderNetWorthChart()` itself is now ~50 lines of orchestration
that reads top-down: load, early-out, build, render. Behavior
is unchanged.

Verified: `node --check`, verify_dom_ids, verify_wiring, all 10
unit tests green.

## [0.14.18] — 2026-06-10

Refactor C: shared JS formatters in `js/_shared.js`.

Six page-level files (`dashboard.js`, `analysis.js`, `orders.js`,
`orders_all.js`, `dividends.js`, `transactions.js`) each carried
their own copy of `fmtMoney` — five of which had slightly drifted
signatures (some accepted `sign:true`, some didn't, some hadn't
been updated when the convention changed in dashboard.js). Plus
`fmtPct` + `pnlClass` lived only in `dashboard.js`, so other pages
couldn't render P&L colors consistently.

### What changed

- `js/_shared.js` now exposes `window.fmtMoney`, `window.fmtPct`,
  `window.pnlClass` from inside its IIFE. The implementation matches
  upstream `gbm-dashboard/app/_shared.js` byte-for-byte (sign-aware
  variant; fmtPct treats `n` as a fraction so `0.05 → "+5.00%"`).
- Each of the six page-level files now grabs the helper via a
  one-line const alias:
  ```js
  const fmtMoney = window.fmtMoney;
  ```
  instead of redefining the 14-line body.
- Net: −60 lines across page-level files, +45 lines in `_shared.js`,
  net −15 lines but a single source of truth that the verifiers and
  CI can sanity-check.

Verified: 7-file `node --check` clean, verify_dom_ids,
verify_wiring, all 10 unit tests green.

### TR-owncloud parity

Trade-Republic-owncloud has the same shape (`_shared.js` with the
shared bits) but `_shared.js` is only loaded on `orders` + `ledger`
pages, and the older pages have tiny one-line inline helpers
declared INSIDE function bodies. Unifying them would require
loading `_shared.js` everywhere AND resolving a global-name collision
with `dashboard.js`'s top-level `const fmtEUR` — risk-to-reward is
poor for ~5 lines saved. Documented in
TR-GBM-Project/TECHNICAL-PATTERNS.md as a future cleanup.

## [0.14.17] — 2026-06-10

Refactor B: extracted `BaseOwnCloudService` parent class.

`GbmService` and the sister `TrService` (Trade-Republic-owncloud)
carried ~100 lines of byte-identical code each — DI constructor,
lazy `userId()` resolution, the EXIT_* constants, and the proc_open
`runProcess()` body. That duplication drifted independently twice
in the past, and any future bug fix would have had to be
remembered in both files.

### What changed

- New abstract class `BaseOwnCloudService` (171 lines) holds the
  shared plumbing:
  - DI-friendly constructor (IUserSession + IConfig + ICrypto)
  - Lazy `userId()` — security boundary against cross-user access
  - `userDir()` per-user data dir under `{datadir}/<uid>/<app>/`
    (subclass provides `<app>` via abstract `appDirName()`)
  - `runProcess()` — proc_open wrapper with timeout + fetch.log
  - `EXIT_OK` / `EXIT_MFA_REQUIRED` / `EXIT_MFA_INVALID` /
    `EXIT_AUTH_FAILED` / `EXIT_API_ERROR` / `EXIT_RATE_LIMITED` /
    `EXIT_CONFIG_ERROR`
- `GbmService` extends it, drops the duplicated lines, and now
  only carries GBM-specific logic (credentials, days config,
  sessionPath, resetSession + Cognito GlobalSignOut, wipeUserData).
- `GbmService.php`: 329 → 220 lines (−33%).
- `userDir()` replaces the old `userGbmDir()` (no external callers
  to migrate).
- The class is intentionally VENDORED-DUPLICATED with
  `Trade-Republic-owncloud/lib/Service/BaseOwnCloudService.php`
  (same content, different namespace) — two ownCloud apps can't
  share a class via composer without an extra package.

Verified: `php -l` clean, verify_dom_ids, verify_wiring, all 10
unit tests green.

## [0.14.16] — 2026-06-09

Refactor A: removed the duplicate update-flow implementation from
`js/dashboard.js`. Since v0.14.15 every page already loads the
single-owner `js/update_flow.js`, but `dashboard.js` kept its own
verbatim copy of `triggerUpdate` / `submitTotp` / `openTotpModal` /
`closeTotpModal` / `showProgressOverlay` / `hideProgressOverlay` /
`startProgressPolling` / `stopProgressPolling` /
`revalidateTotpSubmit` / `onTotpInput` — roughly 330 duplicate
lines that drifted independently from `update_flow.js`.

### What changed

- Removed 10 update-flow functions from `js/dashboard.js` (file
  dropped from 1130 → 845 lines).
- Removed the duplicate wire-up of `#update-btn`, `#totp-modal`,
  `#totp-cancel`, `#totp-input`, `#totp-submit` and
  `#toast-close-btn` from `dashboard.js`'s DOMContentLoaded —
  `update_flow.js` is now the sole owner of those listeners.
- `submitConfig()` no longer calls a local `triggerUpdate()`;
  it now invokes `window.UpdateFlow.updateData()` after a
  successful save (same public entry point used everywhere else).
- The config flow (`openConfigModal`, `closeConfigModal`,
  `loadConfigStatus`, `maybeShowConfigOnFirstLoad`,
  `onConfigInput`, `submitConfig`) stays in `dashboard.js`
  — it is genuinely GBM-specific (only main.php has the modal).

### Why

Bug fixes to the update flow only had to be remembered in one
place going forward. The duplicate was the root cause of the
"⟳ spinning emoji" and "TOTP modal raced the toast" regressions
that ate a chunk of v0.14.4 → v0.14.11.

Verified: `verify_dom_ids`, `verify_wiring`, 10 unit tests all
green; Portafolio's "🔄 Actualizar" still works end-to-end via
`update_flow.js`.

## [0.14.15] — 2026-06-06

The "🔄 Actualizar" button in the top-bar finally works on **every
page**, not just Portafolio. Carlos noticed clicking it from
Órdenes / Dividendos / Libro Diario / Análisis / Glosario /
Configuración did nothing.

### Root cause

`dashboard.js` carried the entire update flow (triggerUpdate,
submitTotp, openTotpModal, modal HTML, etc) — but
`PageController` only loads it on the Portafolio page. The
other pages got `orders.js` / `dividends.js` / etc. which never
wired the top-bar Update button. So the click landed on a button
with no listener.

### Fix

Mirrored the pattern that `Trade-Republic-owncloud` already uses:

- New **`js/update_flow.js`** (467 lines) — self-contained
  update flow, auto-injects the TOTP modal + toast HTML on
  pages that don't already have them, wires the `#update-btn`
  click, runs the staleness chip refresh.
- **`PageController` now loads `update_flow.js` on every page**
  (right after `_shared.js`, before the per-page JS).
- **`main.php` opts out** with `data-update-flow-owner="page"`
  on `#gbm-app`. `dashboard.js` continues to handle the flow
  there itself (verbatim port from `gbm-dashboard` upstream,
  not refactored to avoid an unnecessary divergence).

### Verified gates

```
verify_dom_ids.py: ✅ PASS
verify_wiring.py : ✅ PASS
unittest discover: ✅ 10/10
```

## [0.14.14] — 2026-06-06

Fix tab highlight bug — clicking "Libro Diario" / "Órdenes" /
"Glosario" navigated to the correct URL but the **Portafolio
tab stayed visually highlighted**. Carlos caught it on
`/transactions`.

### Root cause

Two bugs compounded:

1. **4 templates were missing `data-tab=` attrs**
   (`transactions.php`, `orders.php`, `orders_all.php`,
   `glossary.php`). The other 4 had them. The injectTopBar code
   falls through to `_tabFromUrl()` when `data-tab` is absent.

2. **`_tabFromUrl()` used `path.indexOf('/' + slug)`** which is
   substring-match, not segment-match. Every ownCloud URL starts
   with `/index.php/...`, so when the loop checked the portfolio
   tab (slug=`index`) it matched `/index` in **every** path and
   returned `portfolio` before checking transactions / orders /
   etc.

### Fix

- Added `data-tab="<id>"` to all 4 missing templates so the
  fast path always wins.
- Rewrote `_tabFromUrl()` to match the **last path segment**
  exactly (`path.split('/').pop()`), not via `indexOf`. The
  portfolio tab now serves as the fallback at the end.

### Tests

```
verify_dom_ids.py: ✅ PASS
verify_wiring.py : ✅ PASS
unittest discover: ✅ 10/10
```

## [0.14.13] — 2026-06-05

CI + automated test harness. Closes the largest remaining gap
surfaced by today's code review: zero tests, zero CI. Now there
are 10 tests on every push.

### Added

- **`scripts/verify_wiring.py`** — companion to `verify_dom_ids.py`.
  Walks `js/*.js`, collects every callable reference (call sites,
  addEventListener args, our `on()` helper args), and verifies
  each one points at a function defined somewhere in the JS or in
  the BUILTINS allowlist. Catches the *other* half of the
  settings-btn class of bugs: a JS file referencing a function
  name that no JS file defines (typo, rename without updating
  callers, etc).
- **`tests/test_fetch_wrapper_smoke.py`** — 5 stdlib-unittest
  tests that spawn `python/fetch_wrapper.py` with controlled env
  and assert: file exists, no Python crash on `--help`, missing
  creds returns `EXIT_CONFIG_ERROR`, `--full` flag is recognized,
  exit-code constants stay in sync with `lib/Service/GbmService.php`.
- **`tests/test_verify_scripts.py`** — 5 regression tests for the
  verifiers themselves. Plants known-bad mini-repos in `tmp/` and
  asserts the verifier flags them. If the verifier is broken,
  nothing protects us; these are its safety net.
- **`.github/workflows/ci.yml`** — runs both verifiers + the
  unittest suite on every push and PR to `main`. Pure stdlib
  Python, no `pip install` required, ~2 s runtime.
- **`scripts/deploy.sh`** now also runs `verify_wiring.py` and
  `python3 -m unittest discover -s tests` in pre-deploy step 0.
  All three gates green or the deploy aborts.

### Tests

```
$ python3 -m unittest discover -s tests -v
... 10 tests ...
----------------------------------------------------------------------
Ran 10 tests in 0.276s

OK
```

## [0.14.12] — 2026-06-05

Catch-up release rounding out the structural fixes from today:
adds `scripts/deploy.sh` (the GBM port never had one — Carlos
was deploying manually via raw rsync, which is exactly what the
script's "three pillars" header rant warns against), ports the
last functional divergence vs upstream, integrates the DOM-ID
verifier as a mandatory pre-deploy check, and refreshes
INSTALL.md.

### Added

- **`scripts/deploy.sh`** — full-feature deploy script ported
  from `Trade-Republic-owncloud@scripts/deploy.sh` and adapted
  for the GBM topology (`/opt/gbm-venv`, `gbm-mx-api`, app id
  `gbm`). Includes `--bump patch/minor/major`, `--no-lib`,
  `--no-app`, `--skip-verify` (latter only for debugging the
  verifier itself).
- Pre-deploy step 0 in `scripts/deploy.sh`: runs
  `scripts/verify_dom_ids.py`; aborts deploy if a JS reference
  points to an unknown DOM id. This is what would have caught
  the v0.11 `settings-btn` bug before it shipped.

### Changed

- **Port `window.onAccountChanged` callback** to `submitConfig`
  (was missing — divergence vs `gbm-dashboard@v0.13.0
  _shared.js:892`). When the user switches GBM accounts via
  the Config modal, the page can now wipe its in-memory state
  before the next fetch fills it.
- `INSTALL.md`'s "Actualizar el app" section now instructs to
  use `./scripts/deploy.sh` from the developer's laptop instead
  of `git pull` on the server (which would skip the lib + cache
  pillars).
- New section "Garantías de paridad con upstream" in
  `INSTALL.md` with a link to
  `TR-GBM-Project/OWNCLOUD-PATCHES.md` (the catalog of permitted
  dashboard→ownCloud transformations).

## [0.14.11] — 2026-06-05

### THE bug

`$('settings-btn').addEventListener('click', ...)` was throwing a
`TypeError: Cannot read properties of null` in the DOMContentLoaded
callback because **`#settings-btn` no longer exists** in the DOM —
when we moved settings into the top-bar nav as the
"⚙ Configuración" tab (back in v0.11), we removed the old subtitle
settings link from the template but the JS wire-up never got
updated. The error aborted the rest of the callback, so every
listener below that line silently failed to attach:

- `search`, `account-filter`, `market-filter`, `pnl-filter` inputs
- `positions-table` sort headers
- `config-modal` / `totp-modal` backdrop close
- `config-cancel`, `totp-cancel`
- `config-email`, `config-password`, `config-submit`
- **`totp-input` input handler** (no validation on type)
- **`totp-input` keydown Enter → submitTotp**
- **`totp-submit` click → submitTotp** ← Carlos's complaint
- `toast-close-btn`

The visible symptom was: click "Actualizar" inside the TOTP modal
→ nothing happens. The button event fired but no listener was
attached, so the request never went out. We spent hours hunting
for a flow / cache / autofill / event-ordering bug; the actual
cause was a one-line crash 27 lines above the broken listener.

### Fix

Wrapped every `addEventListener` call in a tiny `on(id, evt, fn)`
helper that no-ops if the element is missing. One missing ID can
no longer take out every listener after it. Defensive wiring; no
behavior change for elements that do exist.

### Upstream

The upstream `gbm-dashboard` doesn't have this bug — it never had
a settings-btn in the first place, and its wire-up uses inline
`oninput="..."` attrs that fail-soft per-attribute. No upstream
change needed.

## [0.14.10] — 2026-06-05

Rewrite `submitTotp` as a verbatim port of Trade-Republic-owncloud's
`submitMfa` flow. GBM was delegating to a shared `triggerUpdate()`
that used `setTimeout(startOverlay, 0)` to close the modal and show
the toast — that deferred-start pattern occasionally left the
modal open and the toast invisible because the timer didn't fire
reliably in all event-loop orderings. Carlos hit this end-to-end:
clicking Actualizar in the modal produced no progress feedback.

### Changed

- `js/dashboard.js::submitTotp` — now self-contained: validates
  input, reads checkbox, **closes modal SYNCHRONOUSLY**, **shows
  toast SYNCHRONOUSLY**, then awaits the fetch and handles every
  status path inline. Identical structure to TR's `submitMfa`.
- All UI state changes (button text, disabled, overlay) happen
  in the synchronous prologue before `await`. No more
  `setTimeout(_, 0)`.

### Behavior diff

- Before: click → modal stays open for one tick → setTimeout
  fires → modal closes + toast appears → fetch in flight.
  If the timer was deprioritized by the JS engine the modal
  could stay open indefinitely with no visible progress.
- After: click → modal gone + toast on screen IMMEDIATELY
  (single event-loop tick) → fetch in flight.

### Why this is the same as v0.14.4 (which I previously reverted)

v0.14.4 also did sync close, but it kept the toast inside
`triggerUpdate` with a deferred timer. The new flow doesn't use
`triggerUpdate` at all on the TOTP-submit path — it mirrors TR
bit-for-bit. `triggerUpdate` is still used by the top-bar
Actualizar button for the first MFA probe (no TOTP code yet);
that path is untouched.

### Upstream

Mirrors `gbm-dashboard@HEAD` (`app/_shared.js::submitTotp`).

## [0.14.9] — 2026-06-05

Make the TOTP submit button robust against paste / Chrome
autofill / IME composition. Carlos hit a case today where the
input had a valid 6-digit code ("952251") but the Actualizar
button stayed `disabled` — `onTotpInput` never fired because the
code arrived via a path that didn't emit the `input` event.

### Changed

- `js/dashboard.js` — refactored validation into a standalone
  `revalidateTotpSubmit()` function. `onTotpInput` is now a thin
  wrapper that just calls it.
- `openTotpModal()` — starts a 200 ms `setInterval` that calls
  `revalidateTotpSubmit()` while the modal is open. This is the
  safety net: even when paste / autofill skips the `input` event,
  the button catches up within 200 ms of any value change.
- `closeTotpModal()` — clears the interval the instant the modal
  closes; no wasted polling.
- `submitTotp()` — already re-validated the code at click time, so
  it correctly rejects an empty / malformed code if somehow the
  button got clicked while empty.

### Why a poll vs more events

We could have added listeners for `paste`, `change`, `focus`,
`blur` — but those still miss some autofill paths (Chrome password
manager has been known to skip all of them). A 200 ms poll always
sees the truth and the cost is negligible (a couple of DOM reads).

### Upstream

Mirrors `gbm-dashboard@HEAD` (`app/_shared.js`).

## [0.14.8] — 2026-06-05

Add `.htaccess` that forces browsers to revalidate JS/CSS requests
instead of relying on the 6-month `max-age` ownCloud sets by default.
This eliminates the class of bugs where Carlos's browser kept loading
v0.14.5's broken `dashboard.js` even after the v0.14.6 and v0.14.7
deploys had landed on the server.

### Added

- `.htaccess` at the app root with a `<FilesMatch "\.(js|css|map)$">`
  block setting `Cache-Control: no-cache, must-revalidate, max-age=0`
  and unsetting `Expires`. ETags and `Last-Modified` (already emitted
  by Apache) handle the actual conditional GETs, so unchanged files
  return a tiny 304 and changed files return 200 with the new body.

### Why this over fixing the `?v=` cache-buster

ownCloud's `Util::addScript` builds `/apps/gbm/js/dashboard.js?v=<H>`
URLs where `<H>` is derived from the app version. Bumping `info.xml`
SHOULD regenerate H — but Carlos hit cases (today, 2026-06-05) where
v0.14.7 was on the server, the version was bumped, and his browser
was still running v0.14.5's JS for an hour. Trusting the `?v=` token
alone has proved insufficient. Forcing revalidation is the only
race-free pattern.

## [0.14.7] — 2026-06-05

Revert the v0.14.4 + v0.14.5 changes to the TOTP submit flow.
Carlos reported the token authentication broke entirely after
those refactors. The visual problems they were trying to address
(brief toast flash during MFA probe, perceived race) are far
less serious than a broken auth flow, so the safe move is to
restore the proven v0.14.0 behavior verbatim.

### Reverted (3 changes in `js/dashboard.js`)

- `triggerUpdate` — restore the deferred-timer pattern:
  `const overlayDelay = totpCode != null ? 0 : 700;` and
  `const overlayTimer = setTimeout(startOverlay, overlayDelay);`.
  `stopOverlay` calls `clearTimeout(overlayTimer)` again. The
  post-await `clearTimeout(overlayTimer)` line is restored too.
- `startOverlay` — restore the `if (totpCode) closeTotpModal()`
  line that auto-closes the modal when the toast takes over.
- `submitTotp` — remove the synchronous `closeTotpModal()` call;
  modal close now happens inside `startOverlay` as before.
- `openTotpModal` — drop the Full Reload checkbox reset; the
  state will persist across re-prompts like it used to.

### Kept

- `🔄` glyph (v0.14.6) — fixes the animated-emoji spinner issue.
- Toast + thin progress-bar HTML/CSS (v0.14.1) — the
  non-blocking visual replacement of the old `.progress-overlay`
  was a clean win, only the JS submit logic was the regression.

### Upstream

Mirrors `gbm-dashboard@HEAD` (`app/_shared.js`).

## [0.14.6] — 2026-06-05

Replace the `⟳` glyph with `🔄` everywhere the button or status
text uses an icon. Carlos saw the `⟳` (U+27F3 ANTICLOCKWISE
GAPPED CIRCLE ARROW) **animated as a spinning wheel** on the
Actualizar button — recent macOS releases ship animated SVG
emoji renderings for some Unicode arrow glyphs, and `⟳` is
among them. From the user's perspective the button looked
"already updating" even though nothing was happening.

`🔄` (U+1F504 COUNTERCLOCKWISE ARROWS BUTTON) is the standard
refresh emoji — Trade-Republic-owncloud uses it and it renders
static on macOS / iOS, so this matches the working TR pattern.

### Touched (11 + 10 + 1 = 22 occurrences)

- `js/_shared.js` (1) — the injected top-bar button label.
- `js/dashboard.js` (10) — `btn.textContent = …` assignments
  + the error-box hint + the staleness chip "Tu snapshot es
  viejo — dale 🔄 Actualizar" string.

### Upstream

Mirrors `gbm-dashboard@HEAD` (`app/_shared.js`).

## [0.14.5] — 2026-06-05

Eliminate the toast race on the first MFA probe. Carlos reported
that after v0.14.4 the page still looked like it was *"already
updating"* on arrival — the Actualizar button felt frozen because
the toast appeared **before** the modal whenever Cognito took
longer than the deferred timer (700 ms in v0.14.1–v0.14.3, then
5500 ms in v0.14.4, but never long enough to reliably cover
worst-case auth latency).

### Changed

- `js/dashboard.js::triggerUpdate` — for the first probe
  (no TOTP code), **never** show the toast. The button text
  changing to `"⟳ Conectando..."` is the only feedback during
  the 1–6 s auth probe. The toast only shows once the user has
  typed a code and we know the fetch will take minutes.
- Removed the deferred-timer machinery (`overlayTimer`,
  `setTimeout(startOverlay, …)`, the `clearTimeout` after
  `await fetch`). The toast is now strictly demand-driven from
  `submitTotp` / `submitConfig`'s explicit `startOverlay()` call.

### Why this is safer than tweaking the delay

There is no single delay that covers worst-case Cognito latency
(I've seen 6+ s during AWS hiccups) while also feeling responsive
on the typical 1-2 s case. Eliminating the race by not arming
the timer at all is the only pattern that's race-free.

### Upstream

Mirrors `gbm-dashboard@HEAD` (`app/_shared.js`).

## [0.14.4] — 2026-06-05

Match Trade-Republic-owncloud's MFA submit flow exactly. Carlos
reported two symptoms in GBM after the v0.14.1 toast refactor:

1. *"Empieza a actualizar al escribir el código"* — the toast
   would briefly appear during the first-probe MFA roundtrip
   when Cognito took > 700 ms to respond, then disappear, then
   the modal opened. From the user's perspective it looked like
   the fetch started by itself before they entered the code.
2. *"Si quería poner Recargar todo, ya no funciona"* — on
   re-prompting after a wrong/expired code, the previous Full
   Reload checkbox state could carry over, and the brief
   modal+toast overlap on submit made it feel like the checkbox
   wasn't being read.

### Changed

- `js/dashboard.js::triggerUpdate` — first-probe overlay delay
  bumped **700 ms → 5500 ms** (matches TR's `update_flow.js`).
  Quick `mfa_required` responses (sub-5 s, which is essentially
  always) now dismiss the timer before the toast appears.
- `js/dashboard.js::triggerUpdate` — when `totpCode` is present
  the toast is now shown by calling `startOverlay()` directly,
  not via a `setTimeout(_, 0)`. Removes the brief frame where
  the modal and toast could both be visible.
- `js/dashboard.js::submitTotp` — closes the TOTP modal
  **synchronously** (after reading checkbox state) before
  calling `triggerUpdate`. The `closeTotpModal()` call that
  used to live inside `startOverlay` is removed.
- `js/dashboard.js::openTotpModal` — resets `#totp-full-reload`
  to unchecked on every open, so a stale `true` from a previous
  attempt can't silently re-trigger an expensive wipe.

### Upstream

Mirrors `gbm-dashboard@HEAD` (`app/_shared.js`).

## [0.14.3] — 2026-06-05

USA benchmark switched from `^GSPC` (price-return index) to
**`^SP500TR`** — the S&P 500 **Total Return** index, with
dividends reinvested. Carlos pointed out that a price-return
index undercounts returns vs a real buy-and-hold investor; an
accumulating ETF like CSPX.L would also work but carries
~0.07%/year expense ratio. `^SP500TR` is the cleanest possible
benchmark — dividends reinvested, zero costs.

### Changed

- `js/analysis.js` — USA benchmark symbol: `^GSPC` → `^SP500TR`.
- Chart label: "Si invirtieras en el S&P 500 en su lugar" →
  "Si invirtieras en el S&P 500 (Total Return) en su lugar".

### Notes

- Yahoo Finance serves `^SP500TR` (verified `200 OK`).
- Old `^GSPC.json` / `SPY.json` caches sit unused.
- Variable names (`benchSP500`, `sp500Map`, `sp500Values`)
  unchanged — still refer to the S&P 500 conceptually.

## [0.14.2] — 2026-06-05

USA benchmark in the Análisis chart switched from **SPY** (the
SPDR ETF) to **^GSPC** (the S&P 500 index itself). Carlos prefers
the pure index — SPY tracks it but lags ~0.09% per year (expense
ratio) and pays dividends separately, so the overlay drifts
slightly below "what the market really did".

### Changed

- `js/analysis.js` — benchmark fetch list:
  `['NAFTRACISHRS.MX', 'SPY']` → `['NAFTRACISHRS.MX', '^GSPC']`.
- Variable names + chart label updated: `spy*` → `sp500*`, label
  from "Si compraras SPY en su lugar" → "Si invirtieras en el
  S&P 500 en su lugar".

### Notes

- The benchmark allowlist regex (`/^[A-Za-z0-9.^_-]{1,40}$/` in
  `ApiController::benchmark`) already permits `^`, so no
  server-side change needed.
- Old `SPY.json` cache files sit unused; the new symbol writes
  `^GSPC.json`.
- Upstream `gbm-dashboard/app/analysis.html` carries the same
  switch.

## [0.14.1] — 2026-06-05

Replace the blocking update modal+overlay with the non-blocking
toast + thin-progress-bar pattern from Trade-Republic-owncloud. The
page stays interactive while `/update` runs, matching the
**non-blocking update flow** unification policy in `TR-GBM-Project/`.

### Removed

- The full-viewport `#progress-overlay` div with the centered
  `.progress-box` (big spinner + H2 title + stage + hint). It dimmed
  the entire page and blocked clicks while a fetch was running,
  which Carlos found heavy compared to TR's lighter status banner.

### Added

- **`#progress-bar`** — 2px indeterminate bar pinned to the top of
  the viewport (`top: 0; height: 2px; z-index: 100`).
- **`#toast`** — small status banner under the sticky top-bar
  (`top: 200px; left: 50%`). Shows the same "Conectando…" /
  "Descargando portafolio…" stage text the overlay used to show.
- **`#toast-close-btn`** — × button to dismiss the toast manually.

### Touched

- `templates/main.php` — overlay HTML → progress-bar + toast HTML.
- `css/dashboard.css` — `.progress-overlay` / `.progress-box` /
  `.spinner` CSS blocks replaced by `.progress-bar` + `.toast`
  selectors (all scoped under `#gbm-app`).
- `js/dashboard.js` — `showProgressOverlay()` / `hideProgressOverlay()`
  now toggle `.active` on `#progress-bar` and `#toast` instead of
  `.show` on `#progress-overlay`. Polling logic untouched — it
  still writes the rotating stage text into `#progress-stage`.

### Upstream

Mirrors `gbm-dashboard@HEAD` (`app/_shared.js`).

## [0.14.0] — 2026-06-03

Cockpit parity with TR — add **Investment Cost** and **Available
Cash** KPIs. Closes 2 of the gaps surfaced in the GBM vs TR audit.

### Added

- **Costo invertido** card: sum of cost basis (precio promedio ×
  cantidad) across all positions. Equivalent to TR's "Investment
  Cost". Already computed internally as `totalCost`; now surfaced.
- **Cash disponible** card: sum of Smart Cash account balances
  (idle pesos/dollars not yet redeployed). Equivalent to TR's
  "Available Cash". Typically $0 because GBM auto-sweeps idle cash
  into money market funds; non-zero only between sell and rebuy.

Both cards have explanatory `title` tooltips on hover.

### Cockpit order (now 7 KPIs)

| # | Card | Source |
|---|---|---|
| 1 | Valor total | accounts sum |
| 2 | **Costo invertido** ⭐ NEW | sum(average_cost) |
| 3 | P&L acumulado | sum(yield_value) |
| 4 | XIRR (anualizado) | xirr() |
| 5 | **Cash disponible** ⭐ NEW | Smart Cash balances |
| 6 | Posiciones | unique issue_ids |
| 7 | Cuentas activas | accounts.length |

The `.cards` grid uses auto-fit, so 7 cards wrap to 2 rows on
narrow screens and fit on one row at full width.

### Notes

- TR cockpit has 5 cards. GBM has 7 because the broker model is
  multi-account (Personal / Asesor / Smart Cash / Trading USA),
  which justifies keeping Posiciones + Cuentas activas. Per
  UNIFICATION.md "Where they CAN diverge → Account model".

## [0.13.6] — 2026-06-03

Honor the "no backdrop-blur on modals" policy. Carlos spotted that
the GBM ownCloud progress overlay had a blurred background, which
he'd asked me to remove from both apps earlier (documented as
`feedback_no_backdrop_blur.md` in memory). I re-violated it in
0.13.5 by adding `backdrop-filter: blur(8px)` to the progress
overlay. Cleaned up now.

### Fixed

- `css/dashboard.css`: remove `backdrop-filter` from
  `#gbm-app .progress-overlay` and `#gbm-app .modal-backdrop`.
  Use solid darkened scrim only.
- Policy added to `TR-GBM-Project/UNIFICATION.md` so future passes
  don't reintroduce it. Top-bar's translucent header blur is fine —
  it's not a scrim.

## [0.13.5] — 2026-06-03

UX fix attempt: progress overlay not visible during /update fetch.
Carlos reports "no aparece la pantalla de descargando, solo lo hace".
The JS path is correct (showProgressOverlay → .classList.add('show'))
and the HTML/CSS look right, but ownCloud's chrome may be creating
a stacking context that swallows our `z-index: 200`.

### Fixed

- `css/dashboard.css`: bump progress-overlay `z-index` 200 → 9999
  and add `!important` on `position`, `z-index`, and the `.show`
  display to defeat any owncloud rule trying to override.

If the overlay still doesn't show after this, the issue is browser
cache (the previous dashboard.js without `closeTotpModal()` is still
loaded). Force "Empty Cache and Hard Reload" from Chrome.

## [0.13.4] — 2026-06-03

UX fix: tighten the vertical space at the top of every page.
Carlos noticed the cockpit started a long way below the ownCloud
red navigation bar — way more dead space than the local dashboard.

### Fixed

- `css/dashboard.css`: drop the 24px top padding on `#gbm-app`.
  ownCloud's own `#app-content` already pads the container, so we
  were double-padding. Bottom/left/right stay at 24px.
- Adjust the top-bar's negative-top-margin compensation accordingly
  (`margin: -24px -24px 24px` → `margin: 0 -24px 24px`, including
  the mobile breakpoint) so it sits flush with our content top.

## [0.13.3] — 2026-06-03

Fix benchmark replay granularity. Carlos noticed the NAFTRAC/SPY
overlay lines were "step-shaped" — flat for a whole month, then
jumping. Same pattern in TR. Root cause: we were asking Yahoo for
`interval=1mo` and the JS chart was aggregating cost-basis deltas
by month — two compounding mistakes hiding the day-by-day movement
of the index.

### Fixed

- `lib/Controller/ApiController::benchmark()`: Yahoo URL now uses
  `interval=1d`. Returns ~252 closes/year instead of ~12.
- `js/analysis.js::_replayBenchmark`: rewritten to walk **calendar
  day by calendar day**. Carries the last known close forward on
  weekends/holidays so the line stays continuous. Cost-basis deltas
  consumed daily (not month-aggregated). Output is one value per day,
  not per month-start.
- `alignBench`: maps by exact date instead of month-key.

### Notes

- Old benchmark caches (monthly data) need to be invalidated. On
  next ⟳ Actualizar the per-user `benchmark_cache/*.json` is
  regenerated with daily data.
- Both NAFTRAC (BMV) and SPY (USA) benchmarks affected.
- Same fix applied upstream in `gbm-dashboard` and mirrored to TR.

## [0.13.2] — 2026-06-03

Fix data quality: the "Capital invertido en el tiempo" chart was
swinging ±$200k MXN per day from overnight cash-sweep operations
that aren't real capital deployment. Carlos noticed it on the local
GBM dashboard after the 10-year defaults kicked in. The Libro Diario
already had an `isNoise()` filter that hides these — the chart in
the Análisis page never applied it. Also, the existing filter was
narrow (only Personal `GBMF2 BF`). The Asesor account has the same
peso fund AND a dollar money-market `GBMDINT BO`, both auto-rolling.

### Fixed

- `js/analysis.js::renderNetWorthChart`: filter sweep / repo /
  internal-transfer transactions before accumulating the cost-basis
  line. Without this, GBM's overnight cash sweeps (peso GBMF2 BF,
  dollar GBMDINT BO) and repos showed as ±$200k buys/sells that
  inflated the line beyond reality.
- `js/transactions.js::isNoise`: broaden to cover GBMF2 BF + GBMDINT
  BO across all sub-accounts (not just `EP47NC05`).

### Result

The "Capital invertido en el tiempo" chart now renders a smooth,
monotonically-growing line that reflects real stock/fund purchases.
NAFTRAC and SPY benchmark overlays align meaningfully again.

## [0.13.1] — 2026-06-03

UX fix: close the TOTP modal when the progress overlay opens so the
two don't stack visually. Reported by Carlos during a local debug —
GBM dashboard showed both `🔒 Código de seguridad` modal AND the
`Actualizando tu portafolio` overlay at the same time after entering
the code. TR already handles this correctly; this brings GBM to
parity.

### Fixed

- `js/dashboard.js::startOverlay`: when invoked from the TOTP-flow
  (totpCode present), call `closeTotpModal()` before showing the
  progress overlay.

## [0.13.0] — 2026-06-03

Incremental fetch — el ⟳ Actualizar diario ya no re-descarga 10 años
cada vez. Lee `last_update.date`, baja solo el delta y hace merge por
id único en el JSON existente. La descarga completa sigue siendo
accesible vía el checkbox "Recargar todo desde cero" en el modal TOTP.

### Added

- `python/fetch_wrapper.py`: nuevas funciones `read_last_update_date()`
  + `merge_records()`; flag `--full`; lógica de modo incremental con
  ventana de seguridad de 14 días para capturar liquidaciones tardías.
- `lib/Controller/ApiController::update()`: nuevo parámetro `bool $full`.
- `lib/Service/GbmService::runFetch()`: pasa `--full` al script Python
  cuando el browser lo pide.
- `templates/main.php`: checkbox **Recargar todo desde cero** en el
  modal TOTP (mismo patrón que TR-owncloud's "↻ Full Reload").
- `js/dashboard.js::triggerUpdate()`: acepta `opts.full`; `submitTotp()`
  lo lee del checkbox y lo pasa al POST.

### Behavior

- **Sin `last_update.date`** (primer run, post-reset): full fetch
  usando la ventana configurada (default 10 años).
- **Con `last_update.date`** (subsecuentes): fetch desde
  `last_update - 14 días` y merge en `orders.json`, `orders_all.json`,
  `dividends.json`, `transactions.json` por sus IDs únicos. Mucho más
  rápido (~10-30s vs ~3-5min en cuentas grandes).
- **`full=true`** desde el checkbox: bypassea el incremental, baja la
  ventana completa, sobrescribe. Útil después de cambiar de cuenta o
  cuando los números se ven raros.

### Merge keys

| Archivo | Key | Comportamiento en colisión |
|---|---|---|
| `orders.json` | `sob_id` | new wins (pending → filled propagates) |
| `orders_all.json` | `sob_id` | new wins |
| `dividends.json` | `transaction_id` | new wins |
| `transactions.json` | `transaction_id` | new wins |
| `accounts.json`, `positions.json`, `investments_groups.json` | — | siempre overwrite (snapshots) |

### Notes

- El `from_date` del JSON se mantiene como el más antiguo (no se
  encoge cuando el incremental fetcha solo 14 días).
- Si pip cachea parcialmente `gbm-mx-api`, los IDs pueden volverse
  inconsistentes — usar `Recargar todo desde cero` lo arregla.

## [0.12.0] — 2026-06-03

Quita el límite artificial de 365 días en los rangos de orders /
dividends / transactions. La API de GBM no impone un techo duro de
fecha — la librería pagina lo que pidas. El default viejo (90/365/365)
hacía que XIRR pareciera roto en cuentas con más de un año de
historial porque no veía los depósitos viejos.

### Changed

- `GbmService::getOrdersDays/getDividendsDays/getTransactionsDays`:
  defaults **90/365/365 → 3650/3650/3650** (10 años, la ventana
  máxima validada).
- `templates/glossary.php` (XIRR): reemplaza la narrativa de "la
  API solo expone 365 días" por "el rango es configurable, default
  10 años".
- `templates/settings.php` (rangos de datos): texto explicativo
  actualizado para reflejar el nuevo default.

### Notes

- Existing users keep su valor per-user en `oc_preferences` — el
  cambio de default solo afecta a usuarios nuevos o los que no han
  tocado la configuración. Quien quiera el nuevo default puede
  resetearlo (o subirlo manualmente) en Configuración → Rangos de
  datos.
- El primer ⟳ Actualizar con 10 años de ventana toma más tiempo (es
  un endpoint paginado), pero los siguientes son incrementales.

## [0.11.0] — 2026-06-03

Port del chrome compartido del upstream `gbm-dashboard v0.13.0` —
cierra el último gap visual identificado al comparar local vs cloud.

### Changed — chrome unificado

- **Top-bar sticky** (brand + 7 tabs centrados + Actualizar a la
  derecha) inyectada por `js/_shared.js` en todas las páginas.
  Reemplaza el viejo `<h1>` + pill-nav per-template.
- **7 tabs** (antes 8): Portafolio · Análisis · Órdenes · Dividendos ·
  Libro Diario · Glosario · Configuración. "Histórico" deja de estar
  en el nav (sigue accesible vía Movimientos) — mismo conjunto que
  gbm-dashboard v0.13.0.
- Cada template declara su tab activa vía `data-tab` en `#gbm-app`;
  `_shared.js` infiere por URL como fallback.

### Added

- `js/_shared.js` (NEW): orquestador del chrome compartido. TABS
  como single source of truth; lee rutas de los `data-route-*` attrs
  para no hardcodear paths.
- CSS `#gbm-app .top-bar { ... }` scoped en `css/dashboard.css`:
  sticky, brand-adjacent navy→teal gradient en el logo, blue brand
  para Actualizar.
- `PageController::renderTemplate` carga `_shared` **antes** del JS
  per-page para que `#update-btn` exista cuando dashboard.js intenta
  bindear su listener.

### Notes

- El botón `#update-btn` viejo (en la subtitle) se quita de cada
  template; el nuevo (inyectado por `_shared.js`) hereda el mismo id
  para que el handler existente en dashboard.js / per-page JS siga
  funcionando sin cambios.
- Los modales (TOTP, config, progress overlay) en main.php no se
  tocan — su flujo en js/dashboard.js se mantiene.

## [0.10.0] — 2026-06-03

Cierre del último gap de parity con gbm-dashboard: Análisis ahora
porta el contenido completo en lugar del stub "Próximamente".

### Added — Análisis funcional

- **📈 Análisis** (`/analysis`): port verbatim de
  `gbm-dashboard/app/analysis.html`. Tres charts con Chart.js:
  - **Ring chart** de composición por mercado (BMV, SIC, Extranjero,
    F. Común, F. Deuda, Efectivo).
  - **Bar chart stacked** de dividendos mensuales (neto vs ISR
    retenido, últimos 12 meses).
  - **Line chart** de capital invertido en el tiempo (cost basis,
    compras − ventas) con overlay de benchmark replay (NAFTRAC + SPY)
    y range pills (1M / 3M / 6M / 1Y / All).
- Stat row con dividendos recibidos (12m), ISR retenido (12m) y
  proyección forward (requiere ≥90 días de historial).
- Chart.js 4.x vendored en `js/vendor/chart.umd.min.js` (CSP forbids
  CDN). `PageController` lo carga solo en la página de Análisis.
- CSS scoped bajo `#gbm-app.analysis-page` (chart-card,
  chart-container, stat-row, range-pills, chart-empty).

### Notes

- Los benchmarks usan el endpoint existente `/benchmark/{symbol}`
  (Yahoo Finance proxy con caché 24h en el server).
- Compatible con cualquier número de cuentas (Personal/Asesor/Smart
  Cash). Trading USA sigue limitado en el upstream — no es regresión.

## [0.9.0] — 2026-06-02

Upstream catch-up: gbm-mx-api v0.2.6 → v0.3.1 + gbm-dashboard v0.11.1 → v0.13.0 (partial).

### Added — three new pages and four new endpoints

- **📖 Glosario** (`/glossary`): ~25 términos en 5 secciones (Mercados,
  Cuentas, Fiscal, Métricas, Categorías) con búsqueda live (Esc limpia).
- **⚙ Configuración** (`/settings`): sidebar con 4 secciones (Cuenta,
  Rangos de datos, Sesión, Acerca de). Permite cambiar credenciales,
  configurar `orders_days` / `dividends_days` / `transactions_days`
  per-user, exportar CSV para SAT, y revocar la sesión (con Cognito
  GlobalSignOut real).
- **📈 Análisis** (`/analysis`): página stub. El contenido completo
  (ring chart, dividendos mensuales, línea de capital, XIRR, benchmark
  replay NAFTRAC + SPY) llega en una entrega posterior.
- `GET /api/settings` + `POST /api/settings`: leer/guardar days config
  per-user (validado 1..3650).
- `POST /api/reset`: Cognito GlobalSignOut (best-effort) + wipe del
  caché del usuario.
- `GET /export/transactions.csv`: CSV con headers en español listo
  para pasarlo al contador (Compra/Venta derivadas de is_buy/is_sell,
  RFC 4180 quoting).
- `GET /benchmark/{symbol}`: proxy a Yahoo Finance v8 chart endpoint
  con cache de 24h por usuario (`benchmark_cache/`). Allowlist regex
  estricta en el symbol.

### Server-side

- **gbm-mx-api upgraded a v0.3.1**: silent Cognito refresh
  (`GbmClient.from_saved()` auto-renueva el access token usando el
  refresh_token cada hora sin pedirte TOTP) + `global_signout()` helper
  para revoke real.
- `fetch_wrapper.py` aprende `--revoke` para invocar
  `global_signout(session)` (best-effort GlobalSignOut).
- `GbmService.php` ahora pasa `GBM_ORDERS_DAYS` + `GBM_DIVIDENDS_DAYS`
  + `GBM_TRANSACTIONS_DAYS` (per-user) al wrapper.
- `setCredentials()` detecta cambio de email y wipea la sesión y data
  cacheada automáticamente.

### UI

- Brand-adjacent palette: `--blue` deeper (`#1e88e5`), nuevo
  `--accent-teal` (`#00b8a9`), logo-box con gradiente navy → teal.
- Nav ampliada a 8 pestañas en todas las páginas: Portafolio · Movimientos
  · Histórico · Dividendos · Libro Diario · Análisis · Glosario ·
  Configuración.

### Deferred to next release

- Análisis full content (Chart.js doughnut/bar/line + XIRR cálculo +
  benchmark replay algorithm + range pills + forward dividend projection).
- index.html updates: 5th KPI card (XIRR), concentration warnings
  banner, clickable ticker → side panel con research links.

## [0.5.0] — 2026-05-26

Upstream catch-up: `gbm-mx-api` v0.1.4 → v0.2.0 + `gbm-dashboard` v0.6.2 → v0.8.0.

### Added — two new pages

- **💰 Dividendos** (`/dividends`) — port of `gbm-dashboard@v0.7`.
  Cash payouts, capital returns, fiscal-result distributions and their
  matching ISR retentions. Filter by month / ticker / account / kind.
  New `dividends.json` per-user file via `api#data?type=dividends`.
- **📒 Libro Diario** (`/transactions`) — port of `gbm-dashboard@v0.8`.
  Full ledger of every movement across all accounts (Personal, Asesor,
  Smart Cash): stock and fund buys/sells, repos, cash transfers, FX,
  dividends, ISR. Classified into 12 categories with summary cards and
  filters by category / month / account. New `transactions.json`
  per-user file via `api#data?type=transactions`.

### Server-side

- **`gbm-mx-api` upgraded to v0.2.0** — adds `client.dividends` and
  `client.transactions` clients on `api.appgbm.com`.
- `python/fetch_wrapper.py` now downloads dividends (365 days,
  configurable via `GBM_DIVIDENDS_DAYS`) and transactions (365 days,
  configurable via `GBM_TRANSACTIONS_DAYS`) for every account, not just
  trading. Smart Cash and Asesor activity becomes visible.

### Routing

- New page routes: `page#dividends` (`/dividends`),
  `page#transactions` (`/transactions`).
- New `api#data` types: `dividends`, `transactions`.

### UI

- Nav across all pages now reads:
  Portafolio · Movimientos · Histórico · Dividendos · Libro Diario.
- New pill styles for `kind-*` (Dividendos) and `cat-*` (Libro Diario).

### Deferred to a follow-up

- The Movimientos + Histórico → Órdenes merge that gbm-dashboard@v0.8
  shipped is not yet ported. Both pages still exist here separately.

## [0.4.0] — 2026-05-22

Upstream catch-up: `gbm-mx-api` v0.1.4 + `gbm-dashboard` v0.5.3 → v0.6.2.

### Server-side

- **`gbm-mx-api` upgraded to v0.1.4** (now reclassifies HTTP 422
  `NotAuthorizedException` from `auth.gbm.com` as `AuthError`). Removed
  the local 422-detection hack in `python/fetch_wrapper.py` — the
  AuthError handler already exits 12 (`auth_failed`).

### Added

- **Progress overlay during data fetches** (ports `gbm-dashboard` v0.6.0
  → v0.6.2). Full-screen overlay with a spinner, "Actualizando tu
  portafolio" title, and a stage message that rotates by elapsed time:
  0–3s "Conectando con GBM…", 3–12s "Descargando tu portafolio…",
  12–45s "Descargando posiciones…", 45s–2m "Descargando historial de
  operaciones…", 2–3m "Ya casi terminamos…", 3m+ "Sigue trabajando,
  espera un poco más…". The overlay is deferred 700ms on the first
  probe so quick `mfa_required` responses don't flash it before the
  TOTP modal; when a TOTP code is present it shows instantly.

### Fixed

- **Top emisoras now includes Trading USA holdings.** Trading USA
  fractional shares (DRAM, EWY, …) live in `mercado_extranjero` but
  GBM's USA orders endpoint (`api.trading-usa.gbm.com`) returns 503
  reliably, so they were absent from the ranking. We now synthesize
  them from `positions.json` (`average_cost`) and tag each row with a
  small "USA" pill. Ports `gbm-dashboard` v0.5.4.
- **i18n: button label "Update" → "Actualizar"** everywhere. Ports
  `gbm-dashboard` v0.5.3.
- **Top emisoras trimmed from 15 to 5 entries** to match the Top
  ganadores/perdedores tables on the Portafolio page. From
  `gbm-dashboard` v0.6.1.

## [0.3.1] — 2026-05-21

### Fixed

- **Form inputs and selects clipped text in half.** ownCloud's core CSS
  forces `input, select { height: 32px; margin: 3px 3px 3px 0; padding:
  7px 6px 5px; }` globally. Our `padding: 10px 14px` inside a forced
  32px-tall box pushed the text outside, so the placeholder and select
  labels showed as their bottom halves only ("Buscar emisora...", "Todas
  las cuentas", "Todos los mercados", "P&L: todos"). Override with
  `!important` (scoped under `#gbm-app`), set `height: auto` +
  `line-height: 1.4`, restore the placeholder color (Firefox dims it to
  0.54 by default), and re-add a custom select arrow since
  `appearance: none` removed the native one.
- Same treatment for the TOTP input (`.modal input.totp`) and the
  credentials fields (`.modal input.field`).

## [0.3.0] — 2026-05-21

### Added

- **Port of `gbm-dashboard@v0.5.1`** (commit `3eccda2`): account chips
  and the top P&L card now compute P&L from `sum(yield_value)` over
  positions, not `accounts.plus_minus.amount`. The old source was
  intraday-only and reported $0 for accounts whose market was closed
  (e.g. Trading USA at night, even when the underlying position had
  +$29k in historical gains). New helper `accountPnL()` falls back to
  `accounts.plus_minus` when no positions are present (Smart Cash at
  zero, first-ever fetch).
- **Port of `gbm-dashboard@v0.5.2`** (commit `386116c`): account values
  and total value now compute from `sum(market_value)` over each
  account's positions (all sections including `efectivo`), matching
  what GBM web shows. `accounts.position.amount` can undercount when an
  account holds cross-border positions (e.g. SIC inside a BMV-typed
  account). New helper `accountValue()` falls back to
  `accounts.position.amount` when no detailed data is available.

### Fixed

- **White bands around the dashboard.** ownCloud's user layout wraps
  app content in `#content` / `#app-content` / `#content-wrapper` with
  a default light background, leaving thin white margins around our
  dark dashboard. Now the body and those wrappers get the dashboard's
  dark background when our `#gbm-app` root is present — via a `:has()`
  selector (modern browsers) and a `body.gbm-app-active` class set by
  the three JS modules at `DOMContentLoaded` (fallback for older
  browsers).

## [0.2.4] — 2026-05-21

### Fixed

- **Credential rejection by GBM showed as generic "api error".** When GBM's
  auth endpoint (`auth.gbm.com/api/v1/session/user`) rejected an email /
  password combo it returned HTTP 422, which `gbm-mx-api` surfaces as
  `ApiError` (not `AuthError`). The wrapper let it bubble through as exit
  20 → 502 → generic alert. Now we detect 422 from the auth host inside
  the login flow and exit 12 (`auth_failed`), so the browser reopens the
  config modal with the right error message.

## [0.2.3] — 2026-05-21

### Fixed

- **Every `/api/update` returned HTTP 500 instead of the right status.**
  PHP's `proc_close()` returns `-1` once `proc_get_status()` has already
  reaped the child's exit code in the polling loop. `GbmService` was
  reading the exit code from `proc_close()`, so it always got `-1`, the
  map lookup fell through to `'error'/500`, and the browser saw
  `Update falló (HTTP 500): MFA_REQUIRED: session expired, TOTP needed`
  instead of opening the TOTP modal. Now we capture
  `$status['exitcode']` from the last non-running `proc_get_status()`
  call and only use `proc_close()` to release the handle.

## [0.2.2] — 2026-05-21

### Fixed

- **All /api/* and /data/* endpoints returned 500.** The ownCloud 10 DI
  container resolves services by class name first and only consults
  registered closures for non-class service ids. My
  `registerService(GbmService::class, ...)` was being ignored; the
  container tried to auto-wire `GbmService` and choked on the `string
  $userId` and `string $dataDirRoot` constructor parameters ("Could not
  resolve dataDirRoot! Class dataDirRoot does not exist").
- `GbmService` now takes only `IUserSession`, `IConfig`, `ICrypto` (all
  auto-wireable). `userId` is resolved lazily via a private `userId()`
  helper that calls `IUserSession::getUser()->getUID()` on first use.
  `dataDirRoot` is read from `IConfig::getSystemValue('datadirectory')`
  inside the constructor.
- Per-user isolation is preserved — the userId still derives from the
  current session and can't be set from outside.

## [0.2.1] — 2026-05-21

### Fixed

- **Buttons did nothing.** ownCloud injects `Util::addScript()` files in
  `<head>` without `defer`, so our IIFE ran before the body was parsed.
  `document.getElementById('gbm-app')` returned `null`, the dataset access
  threw a `TypeError`, and the entire script aborted — handlers never
  attached, so Cuenta / Update / TOTP buttons silently did nothing.
- Routes are now read inside the `DOMContentLoaded` handler in all three
  modules (`dashboard.js`, `orders.js`, `orders_all.js`).

## [0.2.0] — 2026-05-21

### Added

- **Histórico page** (`/orders_all`, nav entry 📜). Mirrors gbm-dashboard's
  v0.5 page: shows every order (filled / cancelled / pending / partial)
  with status pills, summary cards by status, and filters by status, side,
  month, ticker and account.
- `fetch_wrapper.py` now iterates **every trading account** (not just the
  primary) using `client.orders.list_for_range`, writes both
  `orders.json` (filled only, for Movimientos) and `orders_all.json` (any
  status, for Histórico) from a single backend pass.
- `ApiController` accepts `/data/orders_all`; `GbmService::dataPath()`
  whitelist updated.
- New status-pill CSS classes (`status-filled`, `status-cancelled`,
  `status-other`).

### Fixed

- **Top movers** now aggregate by ticker across accounts (matches
  gbm-dashboard a1a1f2c). FSHOP 13 held in Personal + Asesor shows as
  one row with combined totals and recomputed P&L %, plus a subtitle
  listing the accounts.
- **Posiciones table** now aggregates by ticker too (matches
  gbm-dashboard 7f4b1d5). Quantity / market value / yield / cost summed;
  prices and P&L % recomputed from totals. "Cuenta" column shows "N
  cuentas" with detail when split across more than one. P&L filter
  applied on aggregated values.
- **Posiciones counter** in the top cards now counts unique tickers, not
  per-account entries.
- **Movimientos** gets a "Cuenta" column and a hidden-by-default account
  filter (matches gbm-dashboard v0.4.3), supports the new multi-account
  payload from the wrapper while staying backwards-compatible with the
  old single-account shape.

## [0.1.2] — 2026-05-21

### Fixed

- **Save credentials / TOTP didn't fire any request.** ownCloud's default
  Content Security Policy blocks inline `<script>` tags without a nonce, so
  the snippet in `templates/main.php` that populated `window.OC_GBM.routes`
  never executed. When `dashboard.js` ran, `OC_GBM` was undefined, the
  first reference threw, and no event handlers got attached → clicks did
  nothing.
- Routes are now passed via `data-route-*` attributes on `#gbm-app` and
  read with `dataset` in JS. No inline script, CSP-clean.

## [0.1.1] — 2026-05-20

### Fixed

- **CSRF check failed en el GET inicial a `/apps/gbm/`.** Añadido
  `@NoCSRFRequired` a `PageController::index`, `PageController::orders`,
  `ApiController::data` y `ApiController::getConfig`. Los POST
  state-changing (`setConfig`, `update`) siguen requiriendo el token, que
  el JS envía como header `requesttoken`.

## [0.1.0] — 2026-05-20

### Added

- Versión inicial. App ownCloud que envuelve `gbm-mx-api` para dar a cada
  usuario un dashboard privado del portafolio GBM+.
- Estructura mínima de app ownCloud 10:
  `appinfo/{info.xml,app.php,routes.php}` + `lib/Application.php` +
  `lib/Controller/{PageController,ApiController}.php` +
  `lib/Service/GbmService.php`.
- Bridge a Python (`python/fetch_wrapper.py`) parametrizado por
  `--session-path` y `--data-dir` — cada usuario tiene sus propios.
- Credenciales por usuario via `IConfig::setUserValue`; password
  cifrada con `ICrypto`.
- Sesión 2FA + JSON de datos viven en `{datadir}/<uid>/gbm/` (0600).
- Dashboard portado desde `gbm-dashboard` con templates ownCloud + JS
  vanilla + CSS scopeado bajo `#gbm-app`.
- Página de Movimientos con desglose por mes y por emisora.
- Modales TOTP y de configuración de credenciales.
- README, INSTALL, ARCHITECTURE.

[0.1.1]: https://github.com/cdamken/gbm-owncloud/releases/tag/v0.1.1
[0.1.0]: https://github.com/cdamken/gbm-owncloud/releases/tag/v0.1.0
