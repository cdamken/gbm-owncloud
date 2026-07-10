# RESUME HERE — GBM ownCloud session handoff

**Last updated:** 2026-07-10 (P0 — reconciling Portafolio — shipped)
**Read this first** to continue exactly where we left off. Conversation memory
is lost on restart; this file + the committed spec/plan + git history are the
source of truth. Companion memory files live in
`~/.claude/projects/-Users-carlos-damkencloud-Claude-gbm-owncloud/memory/`.

---

## Where we are (one paragraph)

App `gbm` **v0.21.1** on `cloud.damken.com`, PHP **7.4.3**. Shipped & merged to
`main`: **M1** (winnersLosers ranking), **M2** (allocation Mercado·Clase·Región
donut), **M3-A** (¿le gano al mercado? headline), **F** (fiscal report button →
CSV in `GBM/`), and **P0 — reconciling Portafolio**. P0 moved the landing's KPI +
per-account P&L math server-side into `lib/Analytics/{PortfolioReconcile,Xirr}.php`
+ `SummaryService` + `GET /api/summary`; `dashboard.js` now renders from that ONE
source, killing the 3 trust bugs by construction (**verified on real data:
header unrealized_pl $35,992.37 = exact sum of the account cards; cost basis
$860k < market $896k so value>cost>P&L+ is coherent**). XIRR is money-weighted
with an honest "faltan flujos" fallback. Also integrated + deployed the `esc()`
self-XSS fix (GitHub #16) that had been merged to main but never deployed. All
subagent-driven; server PHP tests **81/81** on 7.4.3. Epic #15.

## Immediate next step

1. **(Owner) eyeball the live Portafolio** (`.../apps/gbm/`, hard-refresh):
   confirm **P&L acumulado del header = suma de las tarjetas de cuenta** (the bug
   you caught is gone), value/cost/cash read as distinct lines, and XIRR shows a
   real % or "faltan flujos externos". (Numbers already proven to reconcile
   server-side; this is just the visual.)
2. Remaining roadmap (owner's call). **Data-gated (GitHub #13):** M4 riesgo +
   deferred M3 TWR/period-returns + `gbm_cash_flows` — need accumulated daily
   snapshots (grow per 🔄 Actualizar). **Not gated:** P1 (clarity/UX — declutter
   landing, rename nav, consolidate top-movers) and P2 capabilities (per-holding
   drill-down, three-way capital/dividend/FX return) — see the audit roadmap
   `docs/superpowers/specs/2026-07-03-gbm-app-audit-and-redesign-roadmap.md`.
   Optional polish in #14.

## P0 follow-ups noted (from the final review, non-blocking)

- `positions_count` is now holding-row count (per account×security), not distinct
  tickers — matches the per-account "N pos." sense; note if the owner expected the
  old number.
- `total_value` is derived (market + cash), so the landing total may differ by a
  little from GBM's own TPV. Inherent to the one-source mandate.
- XIRR uses only external flows in the ingested window → biased if deposits
  predate the window (P1). `ACCOUNT_TYPES` in dashboard.js is now dead code
  (trivial cleanup).

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
