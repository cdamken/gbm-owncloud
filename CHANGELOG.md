# Changelog

Todos los cambios notables de este proyecto se documentan aquí.

El formato sigue [Keep a Changelog](https://keepachangelog.com/), y el versioning
sigue [SemVer](https://semver.org/).

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
