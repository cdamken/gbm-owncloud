# RESUME HERE — GBM ownCloud session handoff

**Last updated:** 2026-07-01 (M1 shipped)
**Read this first** to continue exactly where we left off. Conversation memory
is lost on restart; this file + the committed spec/plan + git history are the
source of truth. Companion memory files live in
`~/.claude/projects/-Users-carlos-damkencloud-Claude-gbm-owncloud/memory/`.

---

## Where we are (one paragraph)

The GBM ownCloud cutover is **done and live**, and **M1 — Ganadores y
perdedores is DONE, deployed, and merged to `main`** (app `gbm` **v0.17.0** on
`cloud.damken.com`, PHP **7.4.3** server, history intact). M1 added a pure
`PortfolioAnalytics::winnersLosers()` (per-holding total return = unrealized
price change + dividends, ranked best→worst by %) surfaced as a table on the
Análisis page, plus a reusable plain-PHP test harness (`tests/php/`) wired into
the `unittest` gate. Built subagent-driven (plan → 4 tasks → per-task review →
final whole-branch review, all clean; server PHP tests 11/11 on 7.4.3). Plan:
`docs/superpowers/plans/2026-07-01-gbm-metrics-m1-winners-losers.md`.

## Immediate next step

1. **(Owner)** hard-refresh `https://cloud.damken.com/index.php/apps/gbm/analysis`
   and eyeball the new "Ganadores y perdedores" table (visual render was the one
   thing not machine-verified).
2. Next milestone: **M2 — ¿Dónde está mi dinero?** (allocation donut by class/
   sector/region/currency). Needs an **additive** `sector` field on
   `gbm_securities` (class/region/currency already present). Same cycle:
   spec-check → `superpowers:writing-plans` → subagent-driven build.

## Deferred M1 polish (optional, non-blocking)

- USD hint on Trading USA rows in the ranking (port `usdHint()`/`fx.json`
  treatment from `dashboard.js`) — values are correct pesos today, just no
  `(≈ $USD)` context. Future iteration.

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
