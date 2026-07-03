# RESUME HERE — GBM ownCloud session handoff

**Last updated:** 2026-07-03 (M3 shipped)
**Read this first** to continue exactly where we left off. Conversation memory
is lost on restart; this file + the committed spec/plan + git history are the
source of truth. Companion memory files live in
`~/.claude/projects/-Users-carlos-damkencloud-Claude-gbm-owncloud/memory/`.

---

## Where we are (one paragraph)

The GBM ownCloud cutover is done and live; **M1, M2, and M3 are all DONE,
deployed, and merged to `main`** (app `gbm` **v0.19.0** on `cloud.damken.com`,
PHP **7.4.3**, history intact). M1 = pure `winnersLosers()` (total return
ranking) table on Análisis. M2 = pure `allocation()` (market/class/region
buckets, no schema change) driving a Mercado·Clase·Región donut toggle. M3 =
JS-only **"¿Le gano al mercado?" numeric headline** above the net-worth chart:
`renderMarketCompare()` reads the series the chart already builds and shows the
portfolio's % return vs NAFTRAC/S&P over the shown window + the delta in points,
updating with the range pills. All built subagent-driven; server PHP tests
**39/39** on 7.4.3. Plans in `docs/superpowers/plans/2026-07-0{1,2,3}-gbm-metrics-*.md`.

## Immediate next step

1. **(Owner)** hard-refresh `https://cloud.damken.com/index.php/apps/gbm/analysis`
   and eyeball: (a) the "Ganadores y perdedores" table (M1), (b) the
   Mercado·Clase·Región donut pills (M2), (c) the "¿Le gano al mercado?" headline
   above the value chart (M3) — check it updates with the range pills. Visual
   render is the one thing not machine-verified.
2. Next milestone options (owner's call — both partly data-gated):
   - **F — Fiscal** button → CSV (dividendos/intereses/retenciones por año). Has
     complete data TODAY (not gated). ISR pending Carlos's accountant. The
     superseded fiscal plan (`.../2026-07-01-gbm-analytics-fiscal-phase0-1.md`)
     has reusable `FiscalClassifier` + `FiscalReport` code.
   - **M4 — Riesgo** (drawdown, volatility) + the **deferred M3 TWR/period-returns
     (1M/YTD/1Y/inception) + `gbm_cash_flows`** — both need accumulated daily
     snapshot history to be meaningful (~a few weeks today; grows per Actualizar).
     Best revisited in a few months.

## Deferred polish (optional, non-blocking)

- **M2 negative cash**: cash < 0 (legitimate during T+2 buy settlement) is
  intentionally omitted from the donut (a doughnut can't draw a negative slice);
  behavior is locked by a test. Optional future nicety: a small UI note
  ("Efectivo: −$X no mostrado") so the donut total visibly reconciles with the
  net-worth figure during settlement windows.
- **M1 USD hint**: Trading USA rows in the ranking show correct pesos but no
  `(≈ $USD)` context (port `usdHint()`/`fx.json` from `dashboard.js`).
- **Region accuracy**: the M2 región dimension reads `gbm_securities.region`,
  populated by `IngestService` at fetch — accurate after the next 🔄 Actualizar.

## The roadmap (current priority order)

See `docs/superpowers/specs/2026-06-30-gbm-analytics-fiscal-roadmap-design.md`
(the "REVISION 2026-07-01" note is authoritative). Order:

- **M1 — Ganadores y perdedores**: per-stock total return (price + dividends),
  ranked. Mostly display enrichment; data already in DB
  (`AnalysisService::perStock` already includes dividends). **Quick win, do first.**
- **M2 — ¿Dónde está mi dinero?**: allocation by class/sector/region/currency
  (donut). Needs additive `sector` field on `gbm_securities`.
- **M3 — ¿Le gano al mercado?**: TWR + period returns + portfolio vs IPC/S&P.
  Needs additive `gbm_cash_flows` table (deposits/withdrawals) for TWR.
- **M4 — Riesgo**: max drawdown, volatility from snapshots. **Last on purpose** —
  needs accumulated daily-snapshot history (~7 rows today; grows per Actualizar).
- **F — Fiscal**: a button → CSV written to the user's `files/GBM/` (dividends /
  interest / withholding by year). Realized gains / ISR deferred to Phase 1b
  (pending Carlos's accountant). Owner explicitly rejected the page+API+CSV-route
  design as overengineering for 2–3 users once a year.

## Hard constraints (do not violate)

- **PHP 7.4.3 target** (server, not upgradable). No PHP 8 features: no `match`,
  `enum`, constructor promotion, union types, `?->`, named args, `readonly`,
  `str_contains`/`str_starts_with`. Local Mac has PHP 8.5 only — write strict 7.4
  and run the authoritative test pass on the server.
- **Additive schema ONLY** (`appinfo/database.xml`, `<overwrite>false</overwrite>`).
  Never DROP/recreate `oc_gbm_*`. Accumulating snapshot/transaction history must
  never be lost.
- **Every change: commit + push to GitHub AND deploy to the server** via
  `scripts/deploy.sh` (never raw rsync). Nothing left local-only.
- Money stored as `text` decimal strings; dates as `text`; arithmetic in PHP.
  ownCloud 10.13 uses the legacy `OCP\AppFramework\Db\Mapper` (NOT QBMapper) +
  `database.xml` (NOT IMigrationStep).
- UI strings Spanish; code/comments/commits English.
- Pure compute in `lib/Analytics/*` (framework-agnostic), unit-tested via the
  plain-PHP harness in `tests/php/` (see the superseded plan for the harness
  design — it's still the intended approach: `tests/php/run_all.php` + a
  `tests/test_php_core.py` unittest shim; authoritative run on server 7.4.3).

## Git / deploy state at handoff

- Repo `gbm-owncloud`: branch **`analytics-fiscal`** (pushed to origin at handoff).
  `main` = cutover + autocomplete fix (`afbdd37`), already on GitHub and deployed.
- Repo `gbm-dashboard`: `main` in sync with origin (`943ee4c`, autocomplete fix).
- Server `cloud.damken.com`: app `gbm` **v0.16.1** live/enabled; `gbm_old`
  disabled (rollback); `gbm_next` deleted. Data/history intact.
- The **superseded** fiscal-first plan is
  `docs/superpowers/plans/2026-07-01-gbm-analytics-fiscal-phase0-1.md` — keep for
  reference (its test-harness + fiscal-classifier code is reusable) but the
  ordering is replaced by the roadmap revision above.

## Open user-facing follow-ups

- Owner + feli must periodically hit **🔄 Actualizar** (each fetch appends a
  daily snapshot; more history makes M3/M4 meaningful and can't be backfilled).
- Fiscal ISR rules to be confirmed with Carlos's accountant before any ISR figure
  is shown as authoritative.
