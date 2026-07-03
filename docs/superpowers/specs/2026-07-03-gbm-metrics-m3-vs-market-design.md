# GBM Metrics M3 — ¿Le gano al mercado? (numeric headline) — Design

- **Date:** 2026-07-03
- **Status:** Approved (design); ready for writing-plans
- **Repo:** `gbm-owncloud`, branch `analytics-fiscal` (app `gbm`, v0.18.0+)
- **Milestone:** M3 in the metrics roadmap
  (`docs/superpowers/specs/2026-06-30-gbm-analytics-fiscal-roadmap-design.md`),
  scoped down — see *Scope decision*.

## Goal

Answer "¿le gano al mercado?" with a **number**: a compact headline above the
existing "Valor del portafolio en el tiempo" chart showing, over the currently
shown window, the percentage return of the user's portfolio vs NAFTRAC (IPC
proxy) vs the S&P 500 Total Return — with the user's out/under-performance
against each benchmark called out in points.

## Scope decision (why only the numeric headline)

The roadmap's M3 was "TWR + period returns (1M/YTD/1Y/inception) + portfolio vs
IPC/S&P." Grounding it against the data collapsed it into a shippable half and a
data-blocked half:

- **Portfolio vs IPC/S&P already exists** as a chart: `renderNetWorthChart()`
  in `js/analysis.js` already overlays "si compraras NAFTRAC / S&P 500 en su
  lugar" (`_replayBenchmark`), rebased to a common start, reacting to the range
  pills. What's missing is turning that comparison into a **number**. That is
  this spec (M3-A). Small, immediate, no schema.
- **TWR + period returns (1M/YTD/1Y/inception)** are **blocked by data**. A real
  time-weighted return needs a market-value time series; the app's only such
  series is `gbm_portfolio_snapshots`, which starts at app cutover (~2026-06,
  ~a few weeks of rows) — NOT the user's real investment inception. So YTD and
  1Y are impossible today, and "inception" would misleadingly mean "since the
  app started snapshotting." Same constraint that put M4 (risk) last. **Deferred
  to a later pass** once snapshot history accrues; `gbm_cash_flows` +
  `CashFlowExtractor` (from the superseded fiscal plan) land then, not now
  (deposit/withdrawal rows are historical and can be backfilled anytime — no
  benefit to landing them early; only snapshots must accrue forward).

Owner confirmed (2026-07-03): ship the **numeric "vs mercado" headline** now,
framed as **% + delta**; defer TWR/period-returns.

## Data reality (current, do not rebuild)

- `renderNetWorthChart()` (`js/analysis.js:561`) already builds, over the
  visible window `filteredDates` (driven by `#history-range-pills`):
  - `values` — the portfolio's real market value per date, from
    `state.history` (DB snapshots). Requires ≥2 days or the chart shows its
    empty-state.
  - `naftracValues`, `sp500Values` — `_replayBenchmark(state.benchmarks[i],
    dailyMap)` then `alignBench()` then `rebaseToStart()`. The rebase subtracts
    a per-series offset so each benchmark **starts at the same value as
    `values` at the window's first common non-null index** (`rebaseToStart`,
    lines 606-613). Either can be `null` (benchmark fetch failed) — the chart
    just omits that dataset.
- Both benchmark replays reflect the **same capital-deployment cadence** as the
  user (money deployed on the same dates), so all three series move together on
  a contribution day. This is what makes a side-by-side % comparison honest: a
  deposit inflates all three equally, so the **relative** ranking and delta are
  genuine out/under-performance, not a contribution artifact.

## Architecture

Compute the headline **in JS**, inside `renderNetWorthChart()`, from the three
aligned series it already produces. This is a deliberate, bounded exception to
the roadmap's "compute in PHP" rule: the entire benchmark pipeline (Yahoo fetch,
replay, rebase) is already client-side, and the numbers are a pure read of the
series the chart plots. No PHP, **no schema change, no `gbm_cash_flows`**, no
new API.

### Return definition (per series, over the visible window)

For each series `s` in `{values, naftracValues, sp500Values}` aligned to
`filteredDates`:

- Let `i` = the window's first index where `values[i] != null` **and**
  `s[i] != null` (the same anchor `rebaseToStart` uses; benchmarks are rebased
  so `s[i] == values[i]` there).
- Let `j` = the last index where `s[j] != null`.
- If `i >= j` or `values[i] == 0` → the series has no computable return
  (render "—").
- `returnPct(s) = (s[j] - s[i]) / s[i] * 100`.

Because the benchmarks share the portfolio's start value at `i`, the three
percentages share a denominator and are directly comparable. Deltas:
`delta_naftrac = returnPct(values) - returnPct(naftracValues)` (points), same
for S&P. Positive delta = the portfolio beat that index over the window.

### Surface (template + JS + no new CSS)

- **Template** (`templates/analysis.php`): a new block placed **between** the
  `#history-range-pills` and the history `.chart-card`, so the reader sees the
  verdict, then the curve. A `.section` header "¿Le gano al mercado?" + a
  `.stat-row` (reusing the existing analysis stat-row grid) with three cells:
  - Tu portafolio — value `#mkt-port-pct`
  - NAFTRAC (IPC) — value `#mkt-naftrac-pct`, detail `#mkt-naftrac-delta`
  - S&P 500 TR — value `#mkt-sp-pct`, detail `#mkt-sp-delta`
  Wrapped in a container `#market-compare` for show/hide. A static caption
  "sobre el periodo mostrado · mismo ritmo de aportes que tú".
- **JS** (`js/analysis.js`): at the end of `renderNetWorthChart()` (after the
  three series exist), call a new `renderMarketCompare(values, naftracValues,
  sp500Values)` that fills the stat cells; on the chart's empty path
  (`showEmpty()`), hide `#market-compare`. The range pills already re-run
  `renderNetWorthChart()`, so the numbers update on range change for free.
- **CSS**: none. Reuse `#gbm-app.analysis-page .stat-row` /`.stat-label`
  /`.stat-value`/`.stat-detail` and the existing `.green`/`.red` value colors.

### Display rules

- Portfolio % colored green if `> 0`, red if `< 0` (reuse `.stat-value.green`
  /`.red`); zero → neutral, no `+`.
- Each benchmark cell shows its own % (neutral color) plus a delta detail:
  `▲ +X.X pts` (green) when the portfolio is ahead of that index, `▼ −X.X pts`
  (red) when behind.
- A benchmark whose series is `null`/uncomputable → that cell shows "—" and no
  delta (mirrors the chart omitting that dataset when Yahoo data is missing).
- Percentages use one decimal and an explicit sign for non-zero (matching M1's
  `+X.X%` convention, but no `+` on exactly zero).

## Error handling / edge cases

- `< 2` snapshot days (today's normal state): `renderNetWorthChart()` already
  takes its empty path — `renderMarketCompare` hides `#market-compare` so no
  misleading zeros appear. The existing history empty-state note ("el historial
  se va llenando…") continues to explain why.
- Benchmark fetch failed (`state.benchmarks[i]` null): that benchmark cell shows
  "—"; the portfolio % still renders if snapshots exist.
- Contribution within the window inflates all three series equally; the delta
  (the headline's point) stays honest. The caption "mismo ritmo de aportes"
  makes the framing explicit rather than implying a pure price return.

## Testing

- **No new pure PHP function** (this milestone is JS-only over client-side
  series), so no new `tests/php` case is required by M3-A.
- **Gates** (mandatory, run in `scripts/deploy.sh`): `verify_dom_ids.py` — the
  new IDs (`market-compare`, `mkt-port-pct`, `mkt-naftrac-pct`,
  `mkt-naftrac-delta`, `mkt-sp-pct`, `mkt-sp-delta`) referenced in the JS must
  exist in `templates/analysis.php`; `verify_wiring.py` — `renderMarketCompare`
  defined and called; `python3 -m unittest discover -s tests` — the existing
  PHP core suite still green.
- **Authoritative**: PHP core suite on the server (7.4.3) after deploy (unchanged
  by this JS-only milestone, run for consistency); eyeball the headline on the
  live Análisis page and confirm it updates with the range pills.

## Global constraints (inherited)

- **PHP 7.4.3 target** — irrelevant to M3-A (no PHP change) but the deploy still
  runs on 7.4.3.
- **No schema change** — `appinfo/database.xml` untouched.
- **CSP strict**: no inline `<script>`, no `on*=`; the stat cells are static
  markup filled via JS `textContent`; inline `style=""` allowed for show/hide.
- **CSS scoped** under `#gbm-app.analysis-page` — reuse existing classes, no new
  rules.
- **Per-user isolation**: unaffected — reads only `state.history` (already
  user-scoped via `/api/analysis`) and client-fetched public benchmark series.
- UI strings Spanish; code/comments/commits English.
- **Every change**: commit + push to GitHub AND deploy via `scripts/deploy.sh`.

## Out of scope (explicit — deferred to a later pass)

Time-weighted return (TWR); money-weighted (XIRR); period-return selector
(1M/3M/YTD/1Y/inception as discrete numeric returns); `gbm_cash_flows` table +
`CashFlowExtractor`; cumulative fees; any new schema/table/API. These wait until
snapshot history is deep enough to make them meaningful (with M4 risk).
