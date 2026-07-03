# GBM Metrics M3 — ¿Le gano al mercado? (numeric headline) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a numeric "¿Le gano al mercado?" headline above the net-worth chart — the portfolio's % return vs NAFTRAC (IPC) vs S&P 500 TR over the shown window, with the out/under-performance in points.

**Architecture:** Pure client-side addition to `js/analysis.js`: a new `renderMarketCompare()` reads the three aligned/rebased series `renderNetWorthChart()` already builds (`values`, `naftracValues`, `sp500Values`) and fills a static stat-row in `templates/analysis.php`. No PHP, no schema, no new CSS (reuses the analysis `.stat-row` styling). The numbers update on range-pill change because the pill handler already re-runs `renderNetWorthChart()`.

**Tech Stack:** vanilla JS (IIFE, no framework), ownCloud 10.13 templates (PHP echo only), Chart.js (already vendored; not modified here).

## Global Constraints

- **No schema change** — `appinfo/database.xml` untouched. **No PHP change** (JS + template only).
- **CSP strict**: no inline `<script>`, no `on*=` attributes; inline `style="..."` is allowed (used for the show/hide of the stat-row).
- **CSS scoped** under `#gbm-app.analysis-page` — reuse the existing `.stat-row` / `.stat-label` / `.stat-value` (`.green`/`.red`) / `.stat-detail` classes. NO new CSS.
- **verify_dom_ids.py**: every `getElementById('x')` in JS must have `id="x"` in a template. New IDs: `market-compare`, `mkt-port-pct`, `mkt-naftrac-pct`, `mkt-naftrac-delta`, `mkt-sp-pct`, `mkt-sp-delta`.
- **verify_wiring.py**: every function referenced in JS must be defined. `renderMarketCompare` must be defined and called.
- **Compute in JS is a deliberate, bounded exception** here: the benchmark pipeline (Yahoo fetch, `_replayBenchmark`, `rebaseToStart`) is already entirely client-side, so the headline is a pure read of series the chart already computes.
- **Honesty**: benchmarks reflect the user's own capital-deployment cadence, so the delta is genuine out/under-performance; the static caption states "mismo ritmo de aportes que tú". With `<2` snapshot days the chart takes its empty path and the stat-row is hidden (no misleading zeros).
- **UI strings Spanish**; code/identifiers/comments/commits English.
- **Every task ends with a commit.** The final task pushes `analytics-fiscal` to GitHub AND deploys via `scripts/deploy.sh` (never raw rsync), then runs the authoritative PHP test pass on 7.4.3.

## File Structure

| File | Responsibility | Task |
|------|----------------|------|
| `templates/analysis.php` | Static "¿Le gano al mercado?" section + stat-row (hidden by default) | 1 |
| `js/analysis.js` | `renderMarketCompare()`; call it from `renderNetWorthChart()`; hide on empty path | 1 |

---

### Task 1: Numeric vs-market headline

**Files:**
- Modify: `templates/analysis.php` (new section + stat-row, between `#history-range-pills` and its `.chart-card`)
- Modify: `js/analysis.js` (define `renderMarketCompare()`, call it, hide on empty)

**Interfaces:**
- Consumes: the three window-aligned series inside `renderNetWorthChart()` — `values` (portfolio market value per date), `naftracValues`, `sp500Values` (rebased benchmark replays, each may be `null`); the existing `.stat-row` CSS; `#history-range-pills` re-render path.
- Produces: DOM IDs `market-compare`, `mkt-port-pct`, `mkt-naftrac-pct`, `mkt-naftrac-delta`, `mkt-sp-pct`, `mkt-sp-delta`; JS function `renderMarketCompare(values, naftracValues, sp500Values)`.

- [ ] **Step 1: Add the stat-row to the template**

In `templates/analysis.php`, the net-worth section currently is:

```php
	<!-- ---------- Patrimonio en el tiempo (cost basis trajectory) ---------- -->
	<div class="section">
		<span>Valor del portafolio en el tiempo</span>
		<span class="badge muted" id="hist-badge">valor real · crece cada día</span>
	</div>
	<div class="range-pills" id="history-range-pills">
		<button data-range="1M">1M</button>
		<button data-range="3M">3M</button>
		<button data-range="6M">6M</button>
		<button data-range="1Y">1Y</button>
		<button data-range="ALL" class="active">All</button>
	</div>
	<div class="chart-card">
```

Insert the vs-market block between the `#history-range-pills` closing `</div>` and the `<div class="chart-card">`:

```php
	<!-- ---------- Patrimonio en el tiempo (cost basis trajectory) ---------- -->
	<div class="section">
		<span>Valor del portafolio en el tiempo</span>
		<span class="badge muted" id="hist-badge">valor real · crece cada día</span>
	</div>
	<div class="range-pills" id="history-range-pills">
		<button data-range="1M">1M</button>
		<button data-range="3M">3M</button>
		<button data-range="6M">6M</button>
		<button data-range="1Y">1Y</button>
		<button data-range="ALL" class="active">All</button>
	</div>

	<div class="section">
		<span>¿Le gano al mercado?</span>
		<span class="badge muted">periodo mostrado · mismo ritmo de aportes que tú</span>
	</div>
	<div class="stat-row" id="market-compare" style="display:none;">
		<div>
			<div class="stat-label">Tu portafolio</div>
			<div class="stat-value" id="mkt-port-pct">—</div>
		</div>
		<div>
			<div class="stat-label">NAFTRAC (IPC)</div>
			<div class="stat-value" id="mkt-naftrac-pct">—</div>
			<div class="stat-detail" id="mkt-naftrac-delta"></div>
		</div>
		<div>
			<div class="stat-label">S&amp;P 500 TR</div>
			<div class="stat-value" id="mkt-sp-pct">—</div>
			<div class="stat-detail" id="mkt-sp-delta"></div>
		</div>
	</div>

	<div class="chart-card">
```

- [ ] **Step 2: Define `renderMarketCompare()` in analysis.js**

In `js/analysis.js`, add this function immediately BEFORE `function renderNetWorthChart() {` (so it sits with the net-worth helpers and verify_wiring sees it defined):

```javascript
	// ¿Le gano al mercado? — numeric headline over the shown window. Reads the
	// same window-aligned series the net-worth chart plots: `values` (portfolio
	// market value from snapshots) and the rebased benchmark replays. Because
	// each benchmark is rebased to the portfolio's value at the first common
	// index, the three %s share a denominator and the delta is genuine
	// out/under-performance (contributions move all three equally).
	function renderMarketCompare(values, naftracValues, sp500Values) {
		const box = document.getElementById('market-compare');
		if (!box) return;

		// % change of a window-aligned series from its first index where BOTH
		// the portfolio and the series are non-null, to its last non-null value.
		const ret = (series) => {
			if (!series) return null;
			let i = 0;
			while (i < series.length && (series[i] == null || values[i] == null)) i++;
			let j = series.length - 1;
			while (j >= 0 && series[j] == null) j--;
			if (i >= j) return null;
			const start = series[i];
			if (!start) return null;
			return (series[j] - start) / start * 100;
		};

		const portR = ret(values);
		const nafR = ret(naftracValues);
		const spR = ret(sp500Values);

		const fmtPct = (v) => v === null ? '—' : (v > 0 ? '+' : '') + v.toFixed(1) + '%';
		const setPct = (id, v) => {
			const el = document.getElementById(id);
			if (!el) return;
			el.textContent = fmtPct(v);
			el.classList.remove('green', 'red');
			if (v !== null && v > 0) el.classList.add('green');
			else if (v !== null && v < 0) el.classList.add('red');
		};
		const setDelta = (id, port, bench) => {
			const el = document.getElementById(id);
			if (!el) return;
			el.classList.remove('green', 'red');
			if (port === null || bench === null) { el.textContent = ''; return; }
			const d = port - bench;
			el.textContent = (d > 0 ? '▲ +' : (d < 0 ? '▼ ' : '')) + d.toFixed(1) + ' pts';
			if (d > 0) el.classList.add('green');
			else if (d < 0) el.classList.add('red');
		};

		setPct('mkt-port-pct', portR);
		setPct('mkt-naftrac-pct', nafR);
		setPct('mkt-sp-pct', spR);
		setDelta('mkt-naftrac-delta', portR, nafR);
		setDelta('mkt-sp-delta', portR, spR);
		box.style.display = '';
	}
```

- [ ] **Step 3: Hide the headline on the chart's empty path**

In `js/analysis.js`, `renderNetWorthChart()` starts with a `showEmpty` closure:

```javascript
		const showEmpty = () => {
			if (_histChart) { _histChart.destroy(); _histChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
		};
```

Add a line hiding the headline (so `<2` snapshots shows no misleading zeros):

```javascript
		const showEmpty = () => {
			if (_histChart) { _histChart.destroy(); _histChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
			const mc = document.getElementById('market-compare');
			if (mc) mc.style.display = 'none';
		};
```

- [ ] **Step 4: Call `renderMarketCompare()` after the series are built**

In `js/analysis.js`, `renderNetWorthChart()` builds the benchmark series then creates the chart:

```javascript
		const naftracValues = rebaseToStart(alignBench(_replayBenchmark(state.benchmarks[0], dailyMap)));
		const sp500Values   = rebaseToStart(alignBench(_replayBenchmark(state.benchmarks[1], dailyMap)));

		const datasets = [
			_netWorthDataset('Valor del portafolio', values, '#60a5fa', true),
		];
```

Insert the headline call right after `sp500Values` is assigned, before `const datasets`:

```javascript
		const naftracValues = rebaseToStart(alignBench(_replayBenchmark(state.benchmarks[0], dailyMap)));
		const sp500Values   = rebaseToStart(alignBench(_replayBenchmark(state.benchmarks[1], dailyMap)));

		renderMarketCompare(values, naftracValues, sp500Values);

		const datasets = [
			_netWorthDataset('Valor del portafolio', values, '#60a5fa', true),
		];
```

- [ ] **Step 5: Run the gates**

Run: `python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: both PASS. `verify_dom_ids` finds `market-compare`, `mkt-port-pct`, `mkt-naftrac-pct`, `mkt-naftrac-delta`, `mkt-sp-pct`, `mkt-sp-delta` (referenced via `getElementById` in `renderMarketCompare`) defined in `templates/analysis.php`; `verify_wiring` finds `renderMarketCompare` defined and called.

- [ ] **Step 6: Run the full local gate and commit**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green.

```bash
git add templates/analysis.php js/analysis.js
git commit -m "feat(analysis): ¿Le gano al mercado? — numeric portfolio-vs-benchmark headline"
```

---

### Task 2: Deploy + verify on the server (7.4.3)

**Files:** none (deploy + verification gate).

- [ ] **Step 1: Run the full local gate**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green.

- [ ] **Step 2: Push the branch**

```bash
git push origin analytics-fiscal
```

- [ ] **Step 3: Deploy (new feature → minor bump)**

Run: `./scripts/deploy.sh --bump minor`
Expected: pre-deploy checks green; app synced; `occ upgrade` runs (no schema change); `app:enable gbm`; server version prints `0.19.0`. (Ignore the fake security-advisory banner.)

- [ ] **Step 4: Commit the version bump + push**

```bash
git add appinfo/info.xml
git commit -m "chore: bump to 0.19.0 (M3 — ¿le gano al mercado? headline)"
git push
```

- [ ] **Step 5: Run the PHP core tests on the real 7.4.3**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com \
  "sudo -u www-data php /var/www/owncloud/apps/gbm/tests/php/run_all.php"
```
Expected: `PHP core tests: N passed, 0 failed` (exit 0) — unchanged by this JS-only milestone, run for consistency.

- [ ] **Step 6: Confirm the page renders**

Open `https://cloud.damken.com/index.php/apps/gbm/analysis` (hard-refresh). Confirm the "¿Le gano al mercado?" row appears above the net-worth chart with three percentages (Tu portafolio / NAFTRAC (IPC) / S&P 500 TR), a `▲/▼ … pts` delta under each benchmark, and that switching the range pills updates the numbers. If there are `<2` snapshot days, confirm the row is hidden (not showing zeros). Report the on-screen result.

- [ ] **Step 7: Merge to main**

```bash
git checkout main && git merge --ff-only analytics-fiscal && git push origin main && git checkout analytics-fiscal
```
Expected: fast-forward; `main` on GitHub includes M3.

---

## Self-Review

- **Spec coverage:** numeric % headline (portfolio/NAFTRAC/S&P over shown window) → Task 1 Steps 1-2. Delta in points → `setDelta` in Step 2. Updates with range pills → free via the existing pill handler re-running `renderNetWorthChart` (which now calls `renderMarketCompare`, Step 4). Hidden on `<2` snapshots → Step 3. `null` benchmark → "—" + no delta (`ret` returns null, `setDelta` early-returns). Caption "mismo ritmo de aportes" → Step 1 markup. Compute-in-JS exception, no schema, no CSS → honored. TWR/period-returns/`gbm_cash_flows` explicitly out of scope → not built. ✓
- **Placeholder scan:** every step has full code + exact commands/expected output; no TODO/TBD. ✓
- **Type consistency:** `renderMarketCompare(values, naftracValues, sp500Values)` is defined (Step 2) and called with exactly those three locals (Step 4); the six DOM IDs written in the template (Step 1) are the six read in `setPct`/`setDelta` (Step 2). The `.green`/`.red`/`.stat-detail` classes are the ones the analysis stat-row CSS already defines. ✓
- **No PHP/schema:** Task 1 touches only the template + JS; `database.xml` and `lib/**` untouched. ✓
- **GitHub + server:** Task 1 commits; Task 2 pushes, deploys, verifies on 7.4.3, merges to main. ✓
