# GBM Metrics M2 — ¿Dónde está mi dinero? (allocation) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dimension toggle (Mercado · Clase · Región) to the existing Análisis allocation donut, computed server-side from the DB, with no schema change.

**Architecture:** A new pure function `PortfolioAnalytics::allocation()` groups holdings + cash into three dimensions (market / economic class / region) from the `asset_class` + `region` already stored on `gbm_securities`. `AnalysisService::perUser()` exposes it as an `allocation` key on the existing `/api/analysis` JSON. `js/analysis.js` parameterizes `renderAllocationChart(dim)` over that data and wires three pills; the now-unused client-side positions aggregation is removed. Follows the M1 pattern (pure core + thin service + render-only JS) and the roadmap rule "compute in PHP, not the browser."

**Tech Stack:** PHP 7.4 (server), ownCloud 10.13 app framework, vanilla JS (IIFE, no framework), Chart.js (vendored), plain-PHP test harness invoked from Python `unittest`.

## Global Constraints

- **PHP 7.4.3 target** (server; local is 8.5). FORBIDDEN 8.x-only features: `match`, `enum`, constructor promotion, union/intersection types, `?->`, named args, `readonly`, `str_contains`/`str_starts_with`/`str_ends_with`. OK: `<=>`, arrow fns, typed properties, `??`, `??=`.
- **No schema change** — `asset_class` + `region` already exist on `gbm_securities`. Do NOT touch `appinfo/database.xml`.
- **CSP strict**: no inline `<script>`, no `on*=` attributes; inline `style="..."` is allowed.
- **CSS scoped** under `#gbm-app` (analysis: `#gbm-app.analysis-page`). M2 reuses the existing `.range-pills` styling — no new CSS.
- **Per-user isolation**: allocation derives from `AnalysisService::perUser` (user-scoped by construction); the pure function takes only already-scoped arrays. No userId from request input.
- **Money** stored as exact-string, parsed to float only at the service edge.
- **UI strings Spanish**; code/identifiers/comments/commits English.
- **Every task ends with a commit.** The final task pushes `analytics-fiscal` to GitHub AND deploys via `scripts/deploy.sh` (never raw rsync), then runs the authoritative PHP test pass on 7.4.3.
- **Equal-totals invariant**: the three dimensions must sum to the same grand total (same money, grouped differently). A holding dropped from one dimension (unrecognized `asset_class`) is dropped from all three.

## File Structure

| File | Responsibility | Task |
|------|----------------|------|
| `lib/Analytics/PortfolioAnalytics.php` | Add pure `allocation()` + private `bucketsToSortedList()` | 1 |
| `tests/php/test_allocation.php` | Unit tests for `allocation()` | 1 |
| `lib/Service/AnalysisService.php` | Load `region`; build alloc input; add `allocation` key | 2 |
| `templates/analysis.php` | Dimension pills above the allocation chart card | 3 |
| `js/analysis.js` | Read `allocation`; parameterize donut; wire pills; remove dead positions aggregation | 3 |

---

### Task 1: `PortfolioAnalytics::allocation()` (pure)

**Files:**
- Modify: `lib/Analytics/PortfolioAnalytics.php` (add two static methods)
- Create: `tests/php/test_allocation.php`

**Interfaces:**
- Consumes: a list of holdings `['asset_class'=>string,'region'=>string,'market_value'=>float]` and a `float $cashValue`.
- Produces: `PortfolioAnalytics::allocation(array $holdings, float $cashValue): array` → `['market'=>list, 'class'=>list, 'region'=>list]` where each list is `['key'=>string,'value'=>float]` sorted by `value` descending. Private helper `bucketsToSortedList(array $buckets): array`.

- [ ] **Step 1: Write the failing test**

Create `tests/php/test_allocation.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/PortfolioAnalytics.php';

use OCA\Gbm\Analytics\PortfolioAnalytics;

// Holdings spanning all five asset classes + both regions, plus a zero and an
// unrecognized class that must be dropped from ALL dimensions.
$holdings = [
    ['asset_class' => 'equity',         'region' => 'MX',      'market_value' => 100.0],
    ['asset_class' => 'equity_sic',     'region' => 'foreign', 'market_value' => 50.0],
    ['asset_class' => 'equity_foreign', 'region' => 'foreign', 'market_value' => 30.0],
    ['asset_class' => 'equity_fund',    'region' => 'MX',      'market_value' => 20.0],
    ['asset_class' => 'debt_fund',      'region' => 'MX',      'market_value' => 40.0],
    ['asset_class' => 'equity',         'region' => 'MX',      'market_value' => 0.0],    // zero -> skipped
    ['asset_class' => 'mystery',        'region' => 'foreign', 'market_value' => 999.0],  // unknown -> dropped everywhere
];
$out = PortfolioAnalytics::allocation($holdings, 10.0); // + 10 cash

// helper: pull a dimension into key=>value for easy asserts
$asMap = function (array $list) {
    $m = [];
    foreach ($list as $r) { $m[$r['key']] = $r['value']; }
    return $m;
};
$sum = function (array $list) {
    $t = 0.0;
    foreach ($list as $r) { $t += $r['value']; }
    return $t;
};

// market: 5 recognized buckets + efectivo = 6
assert_eq(6, count($out['market']), 'market has 6 buckets (5 classes + cash)');
$mk = $asMap($out['market']);
assert_close(100.0, $mk['mercado_capitales'], 'market capitales');
assert_close(50.0,  $mk['mercados_globales_sic'], 'market SIC');
assert_close(30.0,  $mk['mercado_extranjero'], 'market extranjero');
assert_close(20.0,  $mk['sociedades_inversion_comun'], 'market comun');
assert_close(40.0,  $mk['sociedades_inversion_deuda'], 'market deuda');
assert_close(10.0,  $mk['efectivo'], 'market efectivo = cash');

// class: renta_variable (100+50+30+20), renta_fija (40), efectivo (10)
assert_eq(3, count($out['class']), 'class has 3 buckets');
$cl = $asMap($out['class']);
assert_close(200.0, $cl['renta_variable'], 'renta_variable = equities + comun fund');
assert_close(40.0,  $cl['renta_fija'], 'renta_fija = debt fund');
assert_close(10.0,  $cl['efectivo'], 'class efectivo = cash');

// region: mx (100+20+40+10 cash), foreign (50+30)
assert_eq(2, count($out['region']), 'region has 2 buckets');
$rg = $asMap($out['region']);
assert_close(170.0, $rg['mx'], 'region mx = MX holdings + cash');
assert_close(80.0,  $rg['foreign'], 'region foreign = SIC + extranjero');

// equal totals across dimensions (unknown 'mystery' dropped everywhere)
assert_close(250.0, $sum($out['market']), 'market total = 250');
assert_close(250.0, $sum($out['class']),  'class total = 250');
assert_close(250.0, $sum($out['region']), 'region total = 250');

// descending sort within a dimension
assert_true($out['market'][0]['value'] >= $out['market'][1]['value'], 'market sorted desc');
assert_eq('renta_variable', $out['class'][0]['key'], 'class sorted desc (renta_variable first)');

// empty portfolio + zero cash -> all dimensions empty
$empty = PortfolioAnalytics::allocation([], 0.0);
assert_eq(0, count($empty['market']), 'empty market');
assert_eq(0, count($empty['class']),  'empty class');
assert_eq(0, count($empty['region']), 'empty region');

// cash-only -> efectivo/mx buckets only
$cashOnly = PortfolioAnalytics::allocation([], 5.0);
assert_eq(1, count($cashOnly['market']), 'cash-only market = 1 bucket');
assert_eq('efectivo', $cashOnly['market'][0]['key'], 'cash-only market efectivo');
assert_eq('mx', $cashOnly['region'][0]['key'], 'cash-only region mx');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/php/run_all.php`
Expected: FAIL — `Call to undefined method OCA\Gbm\Analytics\PortfolioAnalytics::allocation()` (fatal).

- [ ] **Step 3: Write minimal implementation**

In `lib/Analytics/PortfolioAnalytics.php`, add these two methods inside the class, immediately after `winnersLosers()` (before `summary()`):

```php
	/**
	 * ¿Dónde está mi dinero? — portfolio allocation grouped three ways:
	 *   - market: the GBM section (relabel of asset_class)
	 *   - class:  economic class (renta variable / renta fija / efectivo)
	 *   - region: mx / foreign
	 * Cash is folded in as its own bucket (efectivo / mx). Pure, PHP 7.4.
	 *
	 * The three dimensions always sum to the same grand total: a holding with an
	 * unrecognized asset_class is a data bug and is dropped from ALL dimensions
	 * (never bucketed as "otro"), so totals stay equal.
	 *
	 * @param array $holdings list of ['asset_class'=>string,'region'=>string,'market_value'=>float]
	 * @param float $cashValue total cash (MXN)
	 * @return array{market:array,class:array,region:array} each a list of
	 *   ['key'=>string,'value'=>float] sorted by value descending
	 */
	public static function allocation(array $holdings, float $cashValue): array {
		$marketByClass = [
			'equity'         => 'mercado_capitales',
			'equity_sic'     => 'mercados_globales_sic',
			'equity_foreign' => 'mercado_extranjero',
			'equity_fund'    => 'sociedades_inversion_comun',
			'debt_fund'      => 'sociedades_inversion_deuda',
		];
		$market = [];
		$class = [];
		$region = [];
		foreach ($holdings as $h) {
			$v = (float) $h['market_value'];
			if ($v <= 0.0) {
				continue;
			}
			$ac = (string) $h['asset_class'];
			if (!isset($marketByClass[$ac])) {
				continue; // unrecognized class -> dropped from every dimension
			}
			$mk = $marketByClass[$ac];
			$market[$mk] = ($market[$mk] ?? 0.0) + $v;
			$ck = $ac === 'debt_fund' ? 'renta_fija' : 'renta_variable';
			$class[$ck] = ($class[$ck] ?? 0.0) + $v;
			$rg = ((string) $h['region']) === 'foreign' ? 'foreign' : 'mx';
			$region[$rg] = ($region[$rg] ?? 0.0) + $v;
		}
		if ($cashValue > 0.0) {
			$market['efectivo'] = ($market['efectivo'] ?? 0.0) + $cashValue;
			$class['efectivo'] = ($class['efectivo'] ?? 0.0) + $cashValue;
			$region['mx'] = ($region['mx'] ?? 0.0) + $cashValue;
		}
		return [
			'market' => self::bucketsToSortedList($market),
			'class'  => self::bucketsToSortedList($class),
			'region' => self::bucketsToSortedList($region),
		];
	}

	/**
	 * Turn a key=>value bucket map into a list of ['key','value'] sorted by
	 * value descending. Pure helper for allocation().
	 *
	 * @param array<string,float> $buckets
	 * @return array<int,array{key:string,value:float}>
	 */
	private static function bucketsToSortedList(array $buckets): array {
		$out = [];
		foreach ($buckets as $key => $value) {
			$out[] = ['key' => (string) $key, 'value' => (float) $value];
		}
		usort($out, static function ($a, $b) {
			return $b['value'] <=> $a['value'];
		});
		return $out;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/php/run_all.php`
Expected: PASS — `PHP core tests: N passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add lib/Analytics/PortfolioAnalytics.php tests/php/test_allocation.php
git commit -m "feat(analytics): pure allocation() — market/class/region buckets"
```

---

### Task 2: Expose `allocation` from AnalysisService

**Files:**
- Modify: `lib/Service/AnalysisService.php`

**Interfaces:**
- Consumes: `PortfolioAnalytics::allocation()` (Task 1); `Security::getRegion()` (existing entity getter).
- Produces: the `/api/analysis` JSON gains a top-level `allocation` key: `{market:[{key,value}...], class:[...], region:[...]}`.

- [ ] **Step 1: Load `region` into `secById`**

In `lib/Service/AnalysisService.php`, the securities loop currently is:

```php
		foreach ($this->securities->findByUser($uid) as $s) {
			$secById[(int) $s->getId()] = [
				'ext_id'      => (string) $s->getExtId(),
				'name'        => (string) $s->getName(),
				'asset_class' => (string) $s->getAssetClass(),
			];
		}
```

Add a `region` field:

```php
		foreach ($this->securities->findByUser($uid) as $s) {
			$secById[(int) $s->getId()] = [
				'ext_id'      => (string) $s->getExtId(),
				'name'        => (string) $s->getName(),
				'asset_class' => (string) $s->getAssetClass(),
				'region'      => (string) $s->getRegion(),
			];
		}
```

- [ ] **Step 2: Add `region` to the holdings rows + build the allocation input**

In the holdings loop, the `$sec` fallback and the `$rows[]` push currently are:

```php
			$sec = $secById[$sid] ?? ['ext_id' => (string) $sid, 'name' => '', 'asset_class' => ''];
			$rows[] = [
				'securityId'   => $sid,
				'extId'        => $sec['ext_id'],
				'name'         => $sec['name'],
				'assetClass'   => $sec['asset_class'],
				'qty'          => $this->f($h->getQuantity()),
				'avgCost'      => $this->f($h->getAvgCost()),
				'marketValue'  => $this->f($h->getMarketValue()),
			];
```

Add `region` to both the fallback and the row:

```php
			$sec = $secById[$sid] ?? ['ext_id' => (string) $sid, 'name' => '', 'asset_class' => '', 'region' => ''];
			$rows[] = [
				'securityId'   => $sid,
				'extId'        => $sec['ext_id'],
				'name'         => $sec['name'],
				'assetClass'   => $sec['asset_class'],
				'region'       => $sec['region'],
				'qty'          => $this->f($h->getQuantity()),
				'avgCost'      => $this->f($h->getAvgCost()),
				'marketValue'  => $this->f($h->getMarketValue()),
			];
```

- [ ] **Step 3: Build the allocation input and add the key to the return array**

The method currently ends:

```php
		$perStock = PortfolioAnalytics::perStock($rows, $divBySec);
		return [
			'summary'        => PortfolioAnalytics::summary($perStock),
			'per_stock'      => $perStock,
			'concentration'  => PortfolioAnalytics::concentration($perStock, $cash),
			'winners_losers' => PortfolioAnalytics::winnersLosers($perStock),
			'history'        => $history,
		];
```

Replace it with (build the alloc input from `$rows`, add the `allocation` key):

```php
		$perStock = PortfolioAnalytics::perStock($rows, $divBySec);

		// Allocation input: asset_class + region + market_value per holding.
		$allocInput = [];
		foreach ($rows as $r) {
			$allocInput[] = [
				'asset_class'  => $r['assetClass'],
				'region'       => $r['region'],
				'market_value' => $r['marketValue'],
			];
		}

		return [
			'summary'        => PortfolioAnalytics::summary($perStock),
			'per_stock'      => $perStock,
			'concentration'  => PortfolioAnalytics::concentration($perStock, $cash),
			'winners_losers' => PortfolioAnalytics::winnersLosers($perStock),
			'allocation'     => PortfolioAnalytics::allocation($allocInput, $cash),
			'history'        => $history,
		];
```

- [ ] **Step 4: Update the method docblock**

Change the `@return` line above `perUser()` from:

```php
	 * @return array{summary:array,per_stock:array,concentration:array,winners_losers:array,history:array}
```

to:

```php
	 * @return array{summary:array,per_stock:array,concentration:array,winners_losers:array,allocation:array,history:array}
```

(If the current docblock differs, just ensure `allocation:array` is present in the `@return` shape.)

- [ ] **Step 5: Lint and commit**

Run: `php -l lib/Service/AnalysisService.php`
Expected: `No syntax errors detected in lib/Service/AnalysisService.php`.

```bash
git add lib/Service/AnalysisService.php
git commit -m "feat(analysis): expose allocation (market/class/region) on /api/analysis"
```

---

### Task 3: Dimension toggle on the Análisis donut

**Files:**
- Modify: `templates/analysis.php` (pills)
- Modify: `js/analysis.js` (read `allocation`; parameterize donut; wire pills; remove dead positions aggregation)

**Interfaces:**
- Consumes: `analysisDb.allocation` (Task 2); the existing `.range-pills` CSS; the existing `#allocation-chart` / `#allocation-empty` / `#alloc-badge` elements; the vendored Chart.js.
- Produces: DOM ID `alloc-dim-pills`; JS parameterized `renderAllocationChart()` reading `state.allocation[_allocDim]`.

- [ ] **Step 1: Add the dimension pills to the template**

In `templates/analysis.php`, the allocation section currently is:

```php
	<!-- ---------- Composición por mercado ---------- -->
	<div class="section">
		<span>Composición del portafolio</span>
		<span class="badge muted" id="alloc-badge">por mercado</span>
	</div>
	<div class="chart-card">
```

Insert a pills row between the `</div>` that closes the `section` and the `<div class="chart-card">`:

```php
	<!-- ---------- Composición por mercado ---------- -->
	<div class="section">
		<span>Composición del portafolio</span>
		<span class="badge muted" id="alloc-badge">por mercado</span>
	</div>
	<div class="range-pills" id="alloc-dim-pills">
		<button data-dim="market" class="active">Mercado</button>
		<button data-dim="class">Clase</button>
		<button data-dim="region">Región</button>
	</div>
	<div class="chart-card">
```

- [ ] **Step 2: Read `allocation` into state**

In `js/analysis.js`, find (in `load()`):

```javascript
			const analysisDb = await safeJson(routes.analysisData);
			state.history = (analysisDb && analysisDb.history) || [];
			state.dbSummary = (analysisDb && analysisDb.summary) || null;
			state.winnersLosers = (analysisDb && analysisDb.winners_losers) || [];
```

Add one line after `state.winnersLosers`:

```javascript
			state.allocation = (analysisDb && analysisDb.allocation) || null;
```

- [ ] **Step 3: Replace `renderAllocationChart()` body to read `state.allocation[dim]`**

In `js/analysis.js`, replace the entire block from `let _allocChart = null;` through the end of `renderAllocationChart()` (the section header comment "Allocation ring chart — composition by GBM market bucket." down to its closing `}`) with:

```javascript
	// ----------------------------------------------------------------------
	// Allocation ring chart — composition by dimension (market/class/region).
	// Data computed server-side by PortfolioAnalytics::allocation; this only
	// picks the active dimension and maps each server key -> {label, color}.
	// ----------------------------------------------------------------------
	const ALLOC_DIMS = {
		market: {
			noun: 'mercados',
			labels: {
				mercado_capitales: 'BMV', mercados_globales_sic: 'SIC',
				mercado_extranjero: 'Extranjero', sociedades_inversion_comun: 'F. Común',
				sociedades_inversion_deuda: 'F. Deuda', efectivo: 'Efectivo',
			},
			colors: {
				mercado_capitales: '#60a5fa', mercados_globales_sic: '#c084fc',
				mercado_extranjero: '#4ade80', sociedades_inversion_comun: '#fbbf24',
				sociedades_inversion_deuda: '#f59e0b', efectivo: '#7a8599',
			},
		},
		class: {
			noun: 'clases',
			labels: { renta_variable: 'Renta variable', renta_fija: 'Renta fija', efectivo: 'Efectivo' },
			colors: { renta_variable: '#60a5fa', renta_fija: '#f59e0b', efectivo: '#7a8599' },
		},
		region: {
			noun: 'regiones',
			labels: { mx: 'México', foreign: 'Extranjero' },
			colors: { mx: '#60a5fa', foreign: '#4ade80' },
		},
	};

	let _allocChart = null;
	let _allocDim = 'market';
	function renderAllocationChart() {
		if (typeof window.Chart !== 'function') return;
		const canvas = document.getElementById('allocation-chart');
		const emptyEl = document.getElementById('allocation-empty');
		if (!canvas) return;

		const dim = ALLOC_DIMS[_allocDim] ? _allocDim : 'market';
		const spec = ALLOC_DIMS[dim];
		const rows = (state.allocation && state.allocation[dim]) || [];

		const labels = [];
		const data = [];
		const colors = [];
		for (const b of rows) {
			const v = Number(b.value) || 0;
			if (v <= 0) continue;
			labels.push(spec.labels[b.key] || b.key);
			data.push(v);
			colors.push(spec.colors[b.key] || '#6b7280');
		}

		if (data.length === 0) {
			if (_allocChart) { _allocChart.destroy(); _allocChart = null; }
			canvas.style.display = 'none';
			emptyEl.style.display = 'flex';
			return;
		}
		canvas.style.display = '';
		emptyEl.style.display = 'none';

		const total = data.reduce((s, v) => s + v, 0);
		document.getElementById('alloc-badge').textContent =
			data.length + ' ' + spec.noun + ' · ' + fmtMoney(total, { currency: true, decimals: 0 });

		if (_allocChart) _allocChart.destroy();
		_allocChart = new window.Chart(canvas, {
			type: 'doughnut',
			data: {
				labels,
				datasets: [{
					data,
					backgroundColor: colors,
					borderColor: '#1a1f2e',
					borderWidth: 3,
					hoverOffset: 12,
					hoverBorderWidth: 3,
					spacing: 2,
				}],
			},
			options: {
				maintainAspectRatio: false,
				cutout: '68%',
				animation: { duration: 600, easing: 'easeOutQuart', animateRotate: true },
				plugins: {
					legend: {
						position: 'right',
						labels: {
							color: '#e8eef5',
							font: { size: 13, weight: '600' },
							padding: 16,
							usePointStyle: true,
							pointStyle: 'circle',
							boxWidth: 12,
							boxHeight: 12,
						},
					},
					tooltip: {
						backgroundColor: '#0f1419',
						titleColor: '#e8eef5',
						bodyColor: '#e8eef5',
						borderColor: '#2a3142',
						borderWidth: 1,
						padding: 10,
						callbacks: {
							label: (ctx) => {
								const t = ctx.dataset.data.reduce((s, v) => s + v, 0);
								const pct = t > 0 ? (ctx.parsed / t * 100) : 0;
								return ' ' + ctx.label + ': ' + fmtMoney(ctx.parsed, { currency: true, decimals: 0 }) +
									'  (' + pct.toFixed(1) + '%)';
							},
						},
					},
				},
			},
		});
	}
```

- [ ] **Step 4: Wire the dimension pills**

In `js/analysis.js`, find the history-range pills block inside the `DOMContentLoaded` handler:

```javascript
		// Range pill clicks: just update _histRange and re-render.
		const pills = document.getElementById('history-range-pills');
		if (pills) {
			pills.addEventListener('click', (e) => {
				const btn = e.target.closest('button[data-range]');
				if (!btn) return;
				_histRange = btn.dataset.range;
				document.querySelectorAll('#history-range-pills button').forEach(b =>
					b.classList.toggle('active', b.dataset.range === _histRange)
				);
				renderNetWorthChart();
			});
		}
```

Immediately after that `if (pills) { ... }` block, add the allocation-dimension pills handler (mirrors the same idiom):

```javascript
		// Allocation dimension pill clicks: switch dimension and re-render.
		const allocPills = document.getElementById('alloc-dim-pills');
		if (allocPills) {
			allocPills.addEventListener('click', (e) => {
				const btn = e.target.closest('button[data-dim]');
				if (!btn) return;
				_allocDim = btn.dataset.dim;
				document.querySelectorAll('#alloc-dim-pills button').forEach(b =>
					b.classList.toggle('active', b.dataset.dim === _allocDim)
				);
				renderAllocationChart();
			});
		}
```

- [ ] **Step 5: Remove the now-dead client-side positions aggregation**

The donut no longer reads `state.positionsFlat`. Remove the dead plumbing:

**(a)** In the `state` object, remove these two lines:

```javascript
		positionsByAccount: {},
		positionsFlat: [],
```

**(b)** Remove the entire `flattenPositions` function:

```javascript
	function flattenPositions(posData, account) {
		if (!posData) return [];
		const all = [];
		for (const key of INVEST_SECTIONS) {
			const section = posData[key] || [];
			for (const p of section) {
				if (p.issue_id === 'Subtotal') continue;
				all.push(Object.assign({}, p, {
					_market_key: key,
					_account_legacy_id: account ? account.legacy_contract_id : null,
					_account_name: account ? account.name : null,
				}));
			}
		}
		return all;
	}
```

**(c)** In `load()`, drop the `positions` fetch. The `Promise.all` currently is:

```javascript
			const [accounts, positionsByAccount, dividends, transactions, lastUpdate] = await Promise.all([
				safeJson(dataUrl('accounts')),
				safeJson(dataUrl('positions')),
				safeJson(dataUrl('dividends')),
				safeJson(dataUrl('transactions')),
				safeText(dataUrl('last_update')),
			]);
```

Replace with (remove `positionsByAccount` from the destructure and the `positions` fetch):

```javascript
			const [accounts, dividends, transactions, lastUpdate] = await Promise.all([
				safeJson(dataUrl('accounts')),
				safeJson(dataUrl('dividends')),
				safeJson(dataUrl('transactions')),
				safeText(dataUrl('last_update')),
			]);
```

**(d)** Further down in `load()`, remove the positionsFlat assignment block:

```javascript
			state.positionsByAccount = positionsByAccount || {};
			state.positionsFlat = [];
			for (const a of accounts) {
				const flat = flattenPositions(positionsByAccount[a.legacy_contract_id], a);
				state.positionsFlat.push.apply(state.positionsFlat, flat);
			}
```

Leave the surrounding lines (`state.accounts = accounts;` above it and the `renderHeader()`/etc. render calls below) intact.

> `INVEST_SECTIONS` may become unused after removing `flattenPositions`. If `verify_wiring.py` or a grep shows it is referenced nowhere else in `js/analysis.js`, remove its declaration too; if it is still used elsewhere, leave it. Report which you did.

- [ ] **Step 6: Run the gates**

Run: `python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: both PASS. `verify_dom_ids` finds `alloc-dim-pills` (referenced via `getElementById` in the pills handler) defined in `templates/analysis.php`. `verify_wiring` finds `renderAllocationChart` defined and called, and no reference to the removed `flattenPositions` remains.

- [ ] **Step 7: Run the full local gate and commit**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green.

```bash
git add templates/analysis.php js/analysis.js
git commit -m "feat(analysis): Mercado/Clase/Región allocation toggle (DB-backed donut)"
```

---

### Task 4: Deploy + verify on the server (7.4.3)

**Files:** none (deploy + verification gate).

- [ ] **Step 1: Run the full local gate**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green (PHP core suite included via `test_php_core`).

- [ ] **Step 2: Push the branch**

```bash
git push origin analytics-fiscal
```

- [ ] **Step 3: Deploy (new feature → minor bump)**

Run: `./scripts/deploy.sh --bump minor`
Expected: pre-deploy checks green; app synced; `occ upgrade` runs (no schema change); `app:enable gbm`; server version prints `0.18.0`. (Ignore the fake security-advisory banner.)

- [ ] **Step 4: Commit the version bump + push**

```bash
git add appinfo/info.xml
git commit -m "chore: bump to 0.18.0 (M2 — allocation toggle)"
git push
```

- [ ] **Step 5: Run the PHP core tests on the real 7.4.3**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com \
  "sudo -u www-data php /var/www/owncloud/apps/gbm/tests/php/run_all.php"
```
Expected: `PHP core tests: N passed, 0 failed` (exit 0).

- [ ] **Step 6: Confirm the page renders**

Open `https://cloud.damken.com/index.php/apps/gbm/analysis` (hard-refresh). Confirm the donut shows and the **Mercado · Clase · Región** pills switch it: Mercado = the 6 market slices (unchanged from before), Clase = Renta variable / Renta fija / Efectivo, Región = México / Extranjero. Confirm the badge text updates per dimension and the totals look identical across the three. Report the on-screen result.

- [ ] **Step 7: Merge to main**

```bash
git checkout main && git merge --ff-only analytics-fiscal && git push origin main && git checkout analytics-fiscal
```
Expected: fast-forward; `main` on GitHub includes M2.

---

## Self-Review

- **Spec coverage:** Mercado/Clase/Región toggle → Tasks 1 (pure buckets) + 3 (pills + render). Compute-in-PHP → Task 1 pure fn, Task 2 service. No schema change → confirmed (only reads existing `asset_class`/`region`). No sector/currency → not built. Equal-totals invariant → Task 1 drops unrecognized classes from all dimensions + test asserts equal totals. Empty-state → Task 3 reuses `#allocation-empty`. Authoritative 7.4.3 run + visual check → Task 4. ✓
- **Placeholder scan:** every code step has full code; exact commands + expected output; no TODO/TBD. ✓
- **Type consistency:** `allocation()` returns `{market,class,region}` each a list of `{key,value}`; JS reads `state.allocation[dim]` and each `b.key`/`b.value`; server keys (`mercado_capitales`, `renta_variable`, `mx`, `efectivo`, …) match the JS `ALLOC_DIMS[*].labels/colors` maps exactly. The `allocation` JSON key (Task 2) matches `analysisDb.allocation` (Task 3 Step 2). New DOM id `alloc-dim-pills` (template) matches `getElementById('alloc-dim-pills')` (JS). ✓
- **PHP 7.4:** only `<=>`, `??`, casts, static closures, arrow fns — no 8.x-only features. ✓
- **DRY / dead code:** the market→class knowledge lives once, in PHP; the browser no longer duplicates the section mapping (dead `flattenPositions`/`positionsFlat` removed in Task 3 Step 5). ✓
- **GitHub + server:** each task commits; Task 4 pushes, deploys, verifies on 7.4.3, merges to main. ✓
