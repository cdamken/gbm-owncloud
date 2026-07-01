# RESUME HERE — GBM ownCloud session handoff

**Last updated:** 2026-07-01 (before a laptop OS update + restart)
**Read this first** to continue exactly where we left off. Conversation memory
is lost on restart; this file + the committed spec/plan + git history are the
source of truth. Companion memory files live in
`~/.claude/projects/-Users-carlos-damkencloud-Claude-gbm-owncloud/memory/`.

---

## Where we are (one paragraph)

The GBM ownCloud cutover is **done and live** (single app `gbm`, v0.16.1 on
`cloud.damken.com`, PHP **7.4.3** server, history intact). We finished a
brainstorming → spec → plan cycle for adding **metrics + fiscal**, then the
owner **re-prioritized (2026-07-01): metrics FIRST**, and the fiscal report is
simplified to a **button that writes a CSV into the user's `files/GBM/`** (no
page, no API, no `occ` user surface). Next concrete action: **write the plan for
step M1 (Ganadores y perdedores)** — I recommended starting there; owner had not
yet given the final go on the order when we paused for the restart.

## Immediate next step

1. Confirm build order with the owner (recommended: **M1 first**).
2. Use `superpowers:writing-plans` to write a focused plan for **M1** to
   `docs/superpowers/plans/2026-07-01-gbm-metrics-m1-winners-losers.md`.
3. Execute (owner leans "paso a paso" → subagent-driven, review between tasks).

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
