# GBM Analytics & Fiscal — Staged Roadmap (Design)

- **Date:** 2026-06-30
- **Status:** Approved (roadmap); Phase 0+1 detailed below for first spec→plan cycle
- **Repo:** `gbm-owncloud` (DB-backed app `gbm`, v0.16.1+)
- **Audience/lens:** Personal-pro tool for the two app users (carlos, feli). Two
  goals: (A) richer portfolio metrics, borrowing good ideas from pro apps
  (Sharesight, Empower, Bloomberg PORT); (B) Mexican (SAT) fiscal reporting.
  Explicitly **out of scope:** multi-tenant productization, billing, public
  onboarding.

## Cross-cutting decisions (apply to every phase)

1. **Data is sacred — never lost.** All schema evolution is **additive only**:
   new tables / new columns declared in `appinfo/database.xml`, applied by
   ownCloud's `updateDbFromStructure` (a diff that adds, never drops). No
   `DROP`/recreate on any `oc_gbm_*` table. Daily snapshots only ever grow
   forward, so the foundation must land early to start accumulating history.
2. **Pure, testable compute core.** Extend the existing framework-agnostic
   pattern (`lib/Analytics/PortfolioAnalytics.php`, `FifoLots.php`): every
   metric/report is a pure function over data loaded from the DB. No OCP
   dependency in the math, no computation in the browser. Each gets unit tests.
3. **Where it lives:** these features depend on the DB layer that exists only in
   `gbm-owncloud`, so they are built here. The compute core stays
   framework-agnostic so it could be shared upstream later. UI patterns mirror
   `gbm-dashboard` where a counterpart exists.
4. **Each phase ships independently** via `scripts/deploy.sh`, with its tests
   green and the mandatory pre-deploy gates (`verify_dom_ids.py`,
   `verify_wiring.py`, `unittest`) passing before the next phase starts.
5. **Fiscal figures are ESTIMATES, not tax advice.** Realized gains/losses
   (from FIFO lots) and aggregated withholdings are exact from the data. ISR
   amounts are estimates and must be labelled as such in UI and exports; the
   authoritative document remains GBM's annual *constancia fiscal*.

## Current state (do not rebuild)

Already present: KPI cards, XIRR, per-stock unrealized P&L, concentration,
yield-on-cost, daily portfolio snapshots, FIFO lots + realized P&L (`occ
gbm:lots`), benchmark replay (Yahoo 5y), per-page + SAT CSV exports, ingest
pipeline (`IngestService` → DB; `LotsService` recompute on web update).

## Roadmap overview

| Phase | Deliverable | Depends on |
|-------|-------------|------------|
| **0** | Data foundation + fiscal classification (additive schema) | — |
| **1** | Annual Fiscal Report page + declarable export | 0 |
| **2** | Performance: TWR, period returns, portfolio-vs-benchmark, total return, fees | 0 |
| **3** | Allocation (class/sector/region/currency) + risk (drawdown, vol, beta) + FX-isolated P&L | 0, 2 |
| **4** | Forward dividends (projected income, calendar, YoC trend) + foreign-income fiscal credit | 1, 3 |

Phases 2–4 each get their own spec→plan cycle later. **This spec details Phase
0+1**, which are built together (Phase 0 is the non-visible enabler for Phase 1).

---

## Phase 0 — Data foundation + fiscal classification

**Goal:** make the data complete and durable enough to support fiscal reporting
(Phase 1) and time-weighted metrics (Phase 2), without losing or rewriting any
existing data.

### Schema additions (additive only, `appinfo/database.xml`)

- **`gbm_cash_flows`** (new table): external deposits/withdrawals extracted from
  the ledger, needed for TWR and net-contribution tracking.
  Columns: `id`, `user_id`, `account_key`, `external_id` (UNIQUE), `direction`
  (`in`/`out`), `amount`, `flowed_at`, `description`. Idempotent upsert by
  `external_id`.
- **`gbm_transactions`** (existing table): add nullable columns
  `fiscal_class` (enum-as-string: `realized_gain` | `dividend` | `interest` |
  `withholding` | `fee` | `none`) and `fiscal_year` (INT, derived from
  `process_date`). Nullable so existing rows are untouched; backfilled by a
  reclassification pass on next ingest.
- **`gbm_securities`** (existing table): add nullable `sector` (VARCHAR) for
  Phase 3 allocation. Populated best-effort; null tolerated.

### Logic

- **`IngestService`**: during ingest, (a) derive `fiscal_class` + `fiscal_year`
  for each transaction from `transaction_type`/`sub_transaction_type`/`category`
  (the GBM fields already captured — e.g. `category=dividend` →
  `dividend`; `repo_mature`/`mercado_dinero` interest → `interest`;
  `tax_withholding` → `withholding`); (b) extract deposit/withdrawal rows into
  `gbm_cash_flows`. Classification is a pure function
  (`lib/Analytics/FiscalClassifier.php`) so it is unit-tested in isolation.
- **Backfill:** classification runs over all existing transactions on the next
  ingest (idempotent, keyed by `external_id`), so historical rows get classified
  without a destructive migration.

### Tests

- `FiscalClassifier` unit tests: representative GBM transaction shapes →
  expected `fiscal_class` (dividend, interest from repo/GBMF2, withholding,
  fee, buy/sell→none).
- Cash-flow extraction: deposit/withdrawal rows produce one `gbm_cash_flows`
  row each; non-flow rows produce none; re-ingest does not duplicate.

---

## Phase 1 — Annual Fiscal Report

**Goal:** a per-calendar-year fiscal summary the user can read on-screen and
export for the annual declaration.

### Compute core — `lib/Analytics/FiscalReport.php` (pure)

Inputs (loaded by a thin service from the DB): classified transactions, FIFO
lots (realized gains with open/close dates), dividends, withholdings, cash flows.
Output, grouped by `fiscal_year`:

- **Realized gain/loss** — from closed FIFO lots whose `closed_at` falls in the
  year (gain = proceeds − cost basis, fees folded in). Sum net per year.
- **Dividends** — gross, by year, domestic vs foreign split (foreign deferred to
  Phase 4 for the credit logic; here just reported separately).
- **Interest** — sum of `interest`-classed transactions per year (repos, money
  market fund distributions).
- **Withholdings** — sum of `withholding`-classed per year.
- **Estimated ISR** (labelled estimate): 10% of net annual realized gain on BMV
  shares; 10% on domestic dividends; interest shown with withholding already
  applied. Estimation rules isolated as named constants with source comments so
  they are easy to correct.

### Surface

- New route `GET /fiscal` (PageController) → `templates/fiscal.php`, plus
  `GET /api/fiscal` (ApiController) returning the per-year JSON.
- New nav tab "Fiscal" (Spanish UI), after "Libro Diario".
- Page: year selector; cards (ganancia realizada, dividendos, intereses,
  retenciones, ISR estimado); a per-instrument realized-gain table.
- `GET /export/fiscal-{year}.csv` — declarable CSV (year, concept, instrument,
  gross, withholding, estimated ISR) with the estimate disclaimer in a header
  comment row.
- All amounts in MXN; Trading USA/SIC shown with `(≈ $USD)` via `fx.json`
  (matching existing convention).

### Error handling

- Years with no data render an empty-state, not an error.
- Unmatched FIFO sells (GBM's ~limited order window) are **excluded** from
  realized gain and surfaced as a "coverage" note, reusing the existing
  `occ gbm:lots` coverage signal — so the report never silently under/over-states.
- Missing `fx.json` → USD instruments shown in native USD with a warning chip,
  not a crash.

### Tests

- `FiscalReport` unit tests with a fixture dataset spanning two years: assert
  per-year realized gain, dividend/interest/withholding totals, and ISR
  estimates against hand-computed expectations.
- `verify_dom_ids.py` / `verify_wiring.py` cover the new page's DOM + JS wiring.

---

## Later phases (context only; separate specs)

- **Phase 2:** TWR + money-weighted (XIRR exists) side by side; period-return
  selector (1M/3M/YTD/1Y/inception); portfolio vs IPC/S&P; total return per
  holding (price + dividends); cumulative fees. Built on snapshots + cash flows
  (Phase 0) + benchmark.
- **Phase 3:** allocation treemap/donut by class/sector/region/currency; max
  drawdown + volatility + beta vs IPC from snapshots; FX-isolated P&L for USD
  holdings.
- **Phase 4:** projected annual dividend income + dividend calendar + YoC trend;
  foreign-income credit (acreditamiento) closing the fiscal loop.

## Assumptions / open items

- Exact SAT ISR rules are simplified to flat 10% estimates for v1; constants are
  isolated for later refinement. The report is explicitly an estimate.
- `sector` source: best-effort (may start mostly null until a mapping is added);
  does not block Phase 0/1.
- No background/cron fetch (MFA blocks unattended login) — snapshots accrue on
  each manual *Actualizar*, unchanged from today.
