# GBM Metrics M2 — ¿Dónde está mi dinero? (allocation) — Design

- **Date:** 2026-07-02
- **Status:** Approved (design); ready for writing-plans
- **Repo:** `gbm-owncloud`, branch `analytics-fiscal` (app `gbm`, v0.17.0+)
- **Milestone:** M2 in the metrics roadmap
  (`docs/superpowers/specs/2026-06-30-gbm-analytics-fiscal-roadmap-design.md`).

## Goal

Let the two app users (carlos, feli) see **where their money is** — the
portfolio's allocation — broken down three ways via a dimension toggle on the
existing Análisis donut: **by market, by economic class, by region.**

## Scope decision (why not the roadmap's four dimensions)

The roadmap listed allocation "by asset class / sector / region / currency."
Grounding that against the actual data (see *Data reality* below) collapsed it:

- **Sector** — GBM does **not** provide sector/industry per security
  (`grep sector` over `lib/`+`python/` = 0 hits). It would need an external
  enrichment source (a manual ticker→sector table the owner maintains, or an
  API blocked by CSP). **Dropped** for M2 — poor ROI for 2–3 users; can return
  as a later milestone if the owner decides to maintain a mapping.
- **Currency** — everything is stored in MXN today (`IngestService` hard-codes
  `currency = 'MXN'`, and Trading USA arrives already converted to pesos). A
  currency donut would be a single 100% slice. **Dropped.**
- **Asset class (as stored)** is a 1:1 relabel of the market section, so a raw
  "market vs class" toggle would show identical slices. **Redefined** as an
  economic class (renta variable / renta fija / efectivo) so the toggle is
  genuinely informative.

Owner confirmed (2026-07-02): **Mercado · Clase · Región**, no schema change,
no sector, no currency; economic-class grouping as defined below.

## Data reality (current state — do not rebuild)

- **Existing donut**: `js/analysis.js::renderAllocationChart()` already draws a
  Chart.js doughnut of `#allocation-chart`, grouped by GBM market section
  (`p._market_key`), computed **client-side** from `state.positionsFlat` (the
  GBM positions JSON). Badge `#alloc-badge` shows "N mercados · $total".
- **`gbm_securities`** already stores per security: `asset_class` (one of
  `equity`, `equity_sic`, `equity_foreign`, `equity_fund`, `debt_fund`),
  `region` (`MX` | `foreign`), `currency` (always `MXN`). Populated by
  `IngestService::assetClass()` / `region()` from the GBM section — a
  deterministic 1:1 map.
- **`AnalysisService::perUser`** already loads holdings + securities + accounts
  and returns `summary`, `per_stock`, `concentration`, `winners_losers`,
  `history`. Its `secById` currently carries `ext_id`, `name`, `asset_class`
  (NOT `region` yet). Cash total is available as `$cash` (sum of
  `accounts.cash_amount`).

## Architecture

Mirror the M1 pattern and the roadmap's cross-cutting rule #2 (**compute in
PHP, not in the browser**): a pure function computes the groupings; the service
exposes them as JSON; the JS only renders. This also removes the current
duplicated section→class knowledge from the browser.

### Compute core — `PortfolioAnalytics::allocation()` (pure, new)

```
allocation(array $holdings, float $cashValue): array
```

- **Input** `$holdings`: list of `['asset_class'=>string,'region'=>string,'market_value'=>float]`
  (built by the service from holdings⋈securities). `$cashValue`: total cash (MXN).
- **Output**: an array keyed by the three dimensions, each a list of
  `['key'=>string,'value'=>float]` sorted by `value` descending, cash folded in
  as its own bucket:

  ```php
  [
    'market' => [ ['key'=>'mercado_capitales','value'=>...], ... , ['key'=>'efectivo','value'=>$cashValue] ],
    'class'  => [ ['key'=>'renta_variable','value'=>...], ['key'=>'renta_fija','value'=>...], ['key'=>'efectivo','value'=>$cashValue] ],
    'region' => [ ['key'=>'mx','value'=>...], ['key'=>'foreign','value'=>...] ],
  ]
  ```

- **Bucketing rules** (pure, from `asset_class` / `region`; keys only — labels
  and colors live in the JS):

  | Dimension | Rule |
  |---|---|
  | **market** | `asset_class` → market key: `equity`→`mercado_capitales`, `equity_sic`→`mercados_globales_sic`, `equity_foreign`→`mercado_extranjero`, `equity_fund`→`sociedades_inversion_comun`, `debt_fund`→`sociedades_inversion_deuda`. Cash → `efectivo`. |
  | **class** | `debt_fund` → `renta_fija`; every other equity/*fund* class → `renta_variable`. Cash → `efectivo`. |
  | **region** | `region` field: `MX`→`mx`, `foreign`→`foreign`. Cash → `mx`. |

- **Zero handling**: buckets with `value <= 0` are omitted. Empty portfolio +
  zero cash → each dimension is an empty list.
- **No `unknown`/`otro` catch-all is invented.** An `asset_class` not in the
  map contributes to no market/class bucket (it is dropped, not bucketed as
  "otro") — matches the closed enum `IngestService` guarantees. If this ever
  fires it is a data bug, surfaced by the total not matching, not hidden.

> **Cash is peso cash only.** `accounts.cash_amount` is MXN; folding it into
> `mx`/`efectivo` is correct under the all-MXN reality. Revisit if multi-currency
> cash ever lands (out of scope).

### Service — `AnalysisService::perUser` (extend)

- Load `region` into `secById` (add to the existing securities loop).
- Build the `$holdings` allocation input (`asset_class`, `region`,
  `market_value`) alongside the existing `$rows`.
- Add one key to the returned array:
  `'allocation' => PortfolioAnalytics::allocation($holdings, $cash)`.
- Update the `@return` docblock.

### Surface — `js/analysis.js` + `templates/analysis.php` + `css/dashboard.css`

- **Template**: add a pills row (like `#history-range-pills`) above the existing
  allocation chart card: buttons `data-dim="market|class|region"`, `market`
  active by default. New IDs must exist in the template (verify_dom_ids gate).
- **JS**: read `analysisDb.allocation` into `state.allocation`. Parameterize
  `renderAllocationChart(dim)` to read `state.allocation[dim]`, map each `key`
  to `{label, color}` via a per-dimension JS map (labels Spanish; reuse the
  existing market colors, assign colors for class/region), destroy+recreate the
  doughnut, and update `#alloc-badge` per dimension
  ("N mercados|clases|regiones · $total"). Wire the pills via
  `addEventListener` (CSP: no inline handlers) through the existing null-safe
  `on()` helper; active-pill styling mirrors the history pills.
- The market grouping now comes from `state.allocation.market` (DB) instead of
  `state.positionsFlat` — values are the same GBM `market_value`, now computed
  server-side. The `positionsFlat`-based aggregation for the chart is removed;
  `positionsFlat` stays for any other current use.
- **CSS**: reuse `#gbm-app .range-pills` styling for the new pills if it already
  covers a generic pills row; otherwise add a scoped `#gbm-app.analysis-page`
  block. No new chart styling (the doughnut is unchanged).

### JS label/color maps (UI layer, Spanish)

```
market: mercado_capitales→"BMV", mercados_globales_sic→"SIC",
        mercado_extranjero→"Extranjero", sociedades_inversion_comun→"F. Común",
        sociedades_inversion_deuda→"F. Deuda", efectivo→"Efectivo"
class:  renta_variable→"Renta variable", renta_fija→"Renta fija", efectivo→"Efectivo"
region: mx→"México", foreign→"Extranjero"
```
(Reuse the existing market color palette; pick distinct colors for the 3 class
and 2 region buckets.)

## Error handling / edge cases

- Empty allocation for a dimension → show the existing `#allocation-empty`
  empty-state, hide the canvas (same idiom as today).
- A dimension the JS doesn't recognize → default to `market`.
- Percentages are derived in the tooltip from the slice values / total (as
  today); no separate pct is stored.

## Testing

- **`PortfolioAnalytics::allocation()` unit tests** (`tests/php/test_allocation.php`):
  a fixture of holdings spanning all five asset classes + two regions + a cash
  value, asserting per-dimension bucket keys, summed values, cash folded into
  `efectivo`/`mx`, descending sort, zero-value omission, and empty-input → empty
  lists. Verify the three dimensions' totals are equal (same money, grouped
  differently).
- **Gates**: `verify_dom_ids.py` (new pill IDs referenced in JS exist in the
  template) + `verify_wiring.py` (`renderAllocationChart`, pill handler defined)
  + `python3 -m unittest discover -s tests`.
- **Authoritative**: PHP core suite run on the server (7.4.3) after deploy;
  eyeball the toggle on the live Análisis page.

## Global constraints (inherited)

- **PHP 7.4.3 target** — no PHP 8.x-only features.
- **Additive schema only** — but M2 needs **no** schema change (asset_class +
  region already present).
- **CSP strict** — no inline `<script>`/`on*=`; inline `style=""` OK.
- **CSS scoped** under `#gbm-app` (analysis: `#gbm-app.analysis-page`).
- **Per-user isolation** — allocation derives from `AnalysisService::perUser`
  which is user-scoped by construction; the pure function takes only
  already-scoped arrays. No userId from request input.
- **Money** stored as exact-string, parsed to float at the service edge.
- UI strings Spanish; code/comments/commits English.
- **Every change**: commit + push to GitHub AND deploy via `scripts/deploy.sh`.

## Out of scope (explicit)

Sector/industry breakdown; currency breakdown; multi-currency cash; treemap
(donut only); any new schema/table.
