# Changelog

Todos los cambios notables de este proyecto se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/), y el versioning
sigue [SemVer](https://semver.org/).

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
