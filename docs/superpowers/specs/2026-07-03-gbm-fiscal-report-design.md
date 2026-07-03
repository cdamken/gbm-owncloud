# GBM F — Reporte fiscal (botón → CSV) — Design

- **Date:** 2026-07-03
- **Status:** Approved (design); ready for writing-plans
- **Repo:** `gbm-owncloud`, branch `analytics-fiscal` (app `gbm`, v0.19.0+)
- **Tracks:** GitHub issue #12 (closes on merge); simplifies the rejected
  page+API+Phase-0 approach of #10. Epic: #15.

## Goal

A button that generates an **income-side fiscal report** and writes it as CSV
files into the user's ownCloud **Files** area (`GBM/`), so carlos/feli can hand
the itemized backup to their accountant once a year. No page, no nav tab, no
download route, no `occ` surface — the owner explicitly rejected that as
overengineering for 2–3 users once a year.

## Scope decision (from the roadmap revision + this session)

- **Income side only**: dividends, interest, and ISR withholding — the amounts
  exactly present in the data. **No realized capital gains, no ISR-on-gains
  estimate** (needs FIFO reconciliation + the accountant's rules; deferred).
- **No schema change**: classification is done **on the fly** at generation time
  from the `type` (GBM category) + `booked_at` already stored on
  `gbm_transactions`. The `fiscal_class`/`fiscal_year` columns and
  `gbm_cash_flows` from the superseded Phase 0 plan are **not** needed.
- **Single source, no double-count**: dividends/interest/withholding all come
  from `gbm_transactions` via one classifier pass. `gbm_dividends` (gross/net/
  tax) stays for the Dividendos page; it is NOT also summed here (its ISR would
  double-count the `tax_withholding` transactions).
- Owner confirmed (2026-07-03): button → 2 clean CSVs (summary + detail) in
  `GBM/`; classify on the fly; no page.

## Data reality (from exploration)

- `gbm_transactions` rows carry: `type` (the GBM `category`), `amount` (exact
  decimal string, MXN), `booked_at` (ISO date), `securityId`, `externalId`.
- Fiscal-relevant categories: `dividend` → dividendo, `repo_mature` → interés,
  `tax_withholding` → retención (ISR). Everything else → none.
- `TransactionMapper::findByUser(uid)` exists (used elsewhere); `SecurityMapper`
  resolves a securityId → issuer name for the detail's "emisora" column.
- No `IRootFolder`/`getUserFolder` usage exists in the repo yet — F introduces
  it (standard ownCloud 10 API).

## Architecture

Mirror the established layering: pure compute core (unit-tested) + thin
DB-backed service + a framework-coupled file writer + a controller endpoint +
a settings button.

### Compute core — pure, ported verbatim from the superseded plan

- **`lib/Analytics/FiscalClassifier.php`** — `classify(array $tx): string`
  (`dividend|interest|withholding|none`, keyed on `$tx['category']` with
  fuzzy fallbacks) and `fiscalYear($processDate): ?int` (year from an ISO
  date, null if unparseable). Ported verbatim (plan lines 205-259).
- **`lib/Analytics/FiscalReport.php`** — `build(array $rows): array` where
  each row is `['fiscal_class'=>string,'fiscal_year'=>int,'amount'=>float]`;
  returns a list (newest year first) of
  `['year'=>int,'dividends'=>float,'interest'=>float,'withholding'=>float,'net'=>float]`
  (`net = dividends + interest − withholding`; year 0 dropped; withholding
  summed as `abs`). Ported verbatim (plan lines 768-807).

Both get `tests/php` unit tests (the harness already exists from M1).

### Service — `lib/Service/FiscalService.php` (thin, DB-backed)

`perUser(string $uid): array` returns `['summary'=>array, 'detail'=>array]`:

- Loads `TransactionMapper::findByUser($uid)`; for each transaction builds
  `['category'=>$t->getType()]`, calls `FiscalClassifier::classify()`; skips
  `none`. Uses `FiscalClassifier::fiscalYear($t->getBookedAt())` for the year.
- **summary**: feeds `['fiscal_class','fiscal_year','amount']` rows to
  `FiscalReport::build()`.
- **detail**: one entry per fiscally-relevant transaction —
  `['date'=>string(YYYY-MM-DD), 'year'=>int, 'class'=>string, 'security'=>string, 'amount'=>float]`,
  sorted by date ascending; `security` is the issuer name resolved from a
  `securityId→name` map built once from `SecurityMapper::findByUser($uid)`
  (empty string when absent). Money parsed to float at this edge.

Per-user scoped by the `$uid` argument, which the controller takes from the
session (never from request input).

### File writer — `lib/Service/FiscalFileService.php` (framework-coupled, thin)

`writeFiles(string $uid, array $files): array` where `$files` is
`filename => csvContent`:

- `$folder = $rootFolder->getUserFolder($uid)` (the user's Files root); get or
  create a `GBM` subfolder (`get('GBM')` catching `NotFoundException` →
  `newFolder('GBM')`).
- For each file: overwrite if it exists (`get($name)->putContent($content)`),
  else `newFolder`/`newFile($name)->putContent($content)`.
- Returns the list of written relative paths (`GBM/<name>`) for the flash.
- Injected with `OCP\Files\IRootFolder` via DI. This is the one new
  framework-coupled unit; it is verified on the server, not unit-tested by the
  pure PHP harness.

> **Correction to the exploration's sketch:** `getUserFolder($uid)` already
> returns the user's *Files* root, so the target is `GBM/…` — NOT
> `files/GBM/…`. Do not prepend `files/`.

### Endpoint — `ApiController::generateFiscal()`

- Route `POST /api/fiscal/generate` (name `api#generateFiscal`).
  `@NoAdminRequired`; CSRF **required** (default — it's a POST from our own JS
  sending `requesttoken`).
- Resolves `uid = $this->gbm->currentUserId()`; `$data = $fiscal->perUser($uid)`.
- Builds the two CSV strings by calling `self::csvRow()` (the existing private
  static escaping helper — `generateFiscal()` lives in the same `ApiController`,
  so it is directly callable, no change to `csvRow` needed):
  - **`reporte-fiscal-resumen.csv`**: a `#` disclaimer comment line, then header
    `anio,dividendos,intereses,retenciones,ingreso_neto`, then one row per year
    (amounts `number_format(..,2,'.','')`).
  - **`reporte-fiscal-detalle.csv`**: a `#` disclaimer line, then header
    `fecha,anio,concepto,emisora,monto`, then one row per detail entry;
    `concepto` is the Spanish label (`dividend`→`Dividendo`, `interest`→
    `Interes`, `withholding`→`Retencion ISR`).
- Calls `FiscalFileService::writeFiles($uid, [...])`.
- Returns `JSONResponse(['status'=>'ok','folder'=>'GBM','files'=>[...]])`; on
  `\Throwable` → `JSONResponse(['status'=>'error','detail'=>...], 500)`.

### Button — Settings → "Datos"

- `templates/settings.php`: a `<button id="generate-fiscal-btn" class="primary"
  type="button">📄 Generar reporte fiscal</button>` in the `s-data` section,
  plus a short helper line ("Escribe CSVs de dividendos/intereses/retención por
  año en tu carpeta GBM/. Estimación informativa; la constancia fiscal de GBM
  es la oficial.").
- `js/settings.js`: a `generateFiscal()` handler — disable+spinner, `fetch(POST)`
  with `csrfHeaders()`, flash success ("Reporte generado en tu carpeta GBM/.")
  or error, re-enable. Wired via the existing null-safe listener pattern (CSP:
  no inline handlers).
- `lib/Controller/PageController.php`: pass the new `generate_fiscal` route into
  the settings template's `routes` (and add `data-route-generate-fiscal` on
  `#gbm-app` for the JS to read, matching how other settings routes are passed).

## Error handling / edge cases

- No fiscally-relevant transactions → the CSVs are written with just the
  disclaimer + header (empty body), and the flash still reports success. (An
  empty report is valid, not an error.)
- `getUserFolder` / write failure (quota, permissions) → caught, returns
  `status:error` with the message; the button flashes the error.
- Re-generation overwrites the two files in place (idempotent); it never
  appends or duplicates.
- Amounts are MXN (income transactions are peso-denominated).

## Testing

- **`tests/php/test_fiscal_classifier.php`**: representative categories →
  expected class (dividend, tax_withholding→withholding, repo_mature→interest,
  buy/sell/deposit→none, missing→none); `fiscalYear` for ISO datetime / date /
  empty.
- **`tests/php/test_fiscal_report.php`**: a two-year fixture → per-year
  dividends/interest/withholding/net, newest-year-first, year-0 dropped,
  withholding summed as abs.
- **Gates**: `verify_dom_ids.py` (the `generate-fiscal-btn` id referenced in
  `settings.js` exists in `settings.php`), `verify_wiring.py` (`generateFiscal`
  defined + wired), `python3 -m unittest discover -s tests`.
- **Authoritative / server**: run the PHP core suite on 7.4.3; then click the
  button on the live app and confirm `GBM/reporte-fiscal-resumen.csv` +
  `…-detalle.csv` appear in the Files app with correct per-year totals and
  itemized rows. (The `IRootFolder` writer is only exercisable on the server.)

## Global constraints (inherited)

- **PHP 7.4.3 target** — no PHP 8.x-only features. Authoritative test run on 7.4.3.
- **No schema change** — `appinfo/database.xml` untouched.
- **CSP strict**: no inline `<script>`/`on*=`; button wired via addEventListener.
- **CSS scoped** under `#gbm-app`; reuse existing settings button styles — no new CSS expected.
- **Per-user isolation**: `uid` from `IUserSession` via `GbmService`; never from
  request input. `IRootFolder->getUserFolder($uid)` scopes the write to that user.
- **Money** stored as exact-string, parsed to float at the service edge.
- **Fiscal figures are ESTIMATES** — disclaimer in every CSV; GBM's *constancia
  fiscal* is authoritative.
- UI strings Spanish; code/identifiers/comments/commits English.
- **Every change**: commit + push to GitHub AND deploy via `scripts/deploy.sh`.

## Out of scope (explicit)

Realized capital gains; ISR-on-gains estimate; a Fiscal page/nav tab; a
JSON/download API; an `occ` command; `gbm_cash_flows`; the `fiscal_class`/
`fiscal_year` DB columns; multi-currency (all income is MXN).
