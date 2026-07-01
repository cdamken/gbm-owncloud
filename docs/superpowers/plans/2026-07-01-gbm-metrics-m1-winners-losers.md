# GBM Metrics M1 — Ganadores y perdedores — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show, on the Análisis page, a per-holding **total return** ranking (price change + dividends received), ordered best→worst, color-coded winners/losers.

**Architecture:** A new pure function `PortfolioAnalytics::winnersLosers()` derives total return from the existing `perStock()` output (no new DB reads). `AnalysisService::perUser()` adds a `winners_losers` key to the JSON it already returns via `GET /api/analysis`. `js/analysis.js` renders a new table into `templates/analysis.php`; styles are scoped under `#gbm-app.analysis-page`. The pure core is unit-tested with a new plain-PHP harness (`tests/php/`) wired into the existing `unittest` gate.

**Tech Stack:** PHP 7.4 (server), ownCloud 10.13 app framework, vanilla JS (IIFE, no framework), plain-PHP test harness invoked from Python `unittest`.

## Global Constraints

- **PHP 7.4.3 target** (the server; not upgradable). FORBIDDEN 8.x features: `enum`, `match`, constructor property promotion, union/intersection types, `?->` nullsafe, named arguments, `readonly`, `str_contains`/`str_starts_with`/`str_ends_with` (use `strpos`). Arrow functions (`fn`), typed properties, `??`, `??=`, spaceship `<=>` are OK (7.4).
- **No schema change in M1** — the data already exists in the DB (`perStock()` already carries `unrealized_pl`, `dividends`, `cost_basis`). Do NOT touch `appinfo/database.xml`.
- **Additive schema rule still holds** for any future step: only add fields/tables; never drop/recreate `oc_gbm_*`.
- **Money is stored as `text`** (exact decimal strings), parsed to float only at the service edge (`AnalysisService::f()`). All M1 math runs on floats already parsed by `perStock()`.
- **UI strings in Spanish**; code/identifiers/comments/commits in English.
- **CSP is strict:** no inline `<script>`, no `on*=` attributes. Inline `style="..."` is allowed (PageController calls `allowInlineStyle(true)`).
- **CSS scoping:** every selector must be prefixed `#gbm-app` (analysis-specific: `#gbm-app.analysis-page`). Bare selectors lose to ownCloud `core.css`.
- **Verify gates:** `scripts/verify_dom_ids.py` requires every `getElementById('x')`/`$('x')` in JS to have an `id="x"` in some `templates/*.php`. `scripts/verify_wiring.py` requires every function referenced in JS to be defined in some `js/*.js`. Both run inside `scripts/deploy.sh`.
- **Every task ends with a commit.** The final task pushes the `analytics-fiscal` branch to GitHub AND deploys to the server via `scripts/deploy.sh` (never raw rsync), then runs the authoritative PHP test pass on the real 7.4.3.
- **Test harness:** fast local iteration with `php tests/php/run_all.php` (local PHP 8.5); the authoritative pass runs on the server (7.4.3) after deploy.

## File Structure

| File | Responsibility | Task |
|------|----------------|------|
| `tests/php/assert.php` | Tiny assertion helper (pass/fail counters) | 1 |
| `tests/php/run_all.php` | Runs every `tests/php/test_*.php`, exits 1 on failure | 1 |
| `tests/php/test_smoke.php` | Harness self-check | 1 |
| `tests/test_php_core.py` | `unittest` shim that shells out to `run_all.php` | 1 |
| `lib/Analytics/PortfolioAnalytics.php` | Add pure `winnersLosers()` | 2 |
| `tests/php/test_winners_losers.php` | Unit tests for `winnersLosers()` | 2 |
| `lib/Service/AnalysisService.php` | Add `winners_losers` key to `perUser()` | 3 |
| `templates/analysis.php` | New "Ganadores y perdedores" section + table | 3 |
| `js/analysis.js` | Read `winners_losers`, render the ranked table | 3 |
| `css/dashboard.css` | Scoped styles for the ranking table | 3 |

---

### Task 1: Plain-PHP test harness

The `tests/php/` harness does not exist yet. This task creates it and hooks it into the existing `unittest` gate, so the M1 pure-core test (Task 2) — and every future one — runs both locally and on the server.

**Files:**
- Create: `tests/php/assert.php`
- Create: `tests/php/run_all.php`
- Create: `tests/php/test_smoke.php`
- Create: `tests/test_php_core.py`

**Interfaces:**
- Produces: global functions `assert_eq($expected,$actual,$label)`, `assert_true($cond,$label)`, `assert_close($expected,$actual,$label,$eps=0.001)`; runner `tests/php/run_all.php` (exit 0 all-pass, 1 on any fail).

- [ ] **Step 1: Write the assertion helper**

Create `tests/php/assert.php`:

```php
<?php
// Tiny assertion harness for pure-PHP core tests. PHP 7.4 compatible.
// Test files require_once this, call assert_*; run_all.php checks the global
// counters and exits non-zero on any failure. Idempotent under require_once.
if (!isset($GLOBALS['__t_pass'])) {
    $GLOBALS['__t_pass'] = 0;
    $GLOBALS['__t_fail'] = 0;
}

function assert_eq($expected, $actual, $label) {
    if ($expected === $actual) {
        $GLOBALS['__t_pass']++;
        return;
    }
    $GLOBALS['__t_fail']++;
    fwrite(STDERR, sprintf("FAIL: %s\n  expected: %s\n  actual:   %s\n",
        $label, var_export($expected, true), var_export($actual, true)));
}

function assert_true($cond, $label) {
    assert_eq(true, (bool) $cond, $label);
}

function assert_close($expected, $actual, $label, $eps = 0.001) {
    if (abs(((float) $expected) - ((float) $actual)) <= $eps) {
        $GLOBALS['__t_pass']++;
        return;
    }
    $GLOBALS['__t_fail']++;
    fwrite(STDERR, sprintf("FAIL: %s\n  expected ~%s\n  actual   %s\n",
        $label, var_export($expected, true), var_export($actual, true)));
}
```

- [ ] **Step 2: Write the runner and a smoke test**

Create `tests/php/run_all.php`:

```php
<?php
// Runs every tests/php/test_*.php, accumulating assert_* results, exits 1 if
// any failed. Run locally (php 8.5) for speed and — authoritatively — on the
// server (php 7.4.3) after deploy. PHP 7.4 compatible.
require_once __DIR__ . '/assert.php';
foreach (glob(__DIR__ . '/test_*.php') as $file) {
    require $file;
}
fwrite(STDOUT, sprintf("\nPHP core tests: %d passed, %d failed\n",
    $GLOBALS['__t_pass'], $GLOBALS['__t_fail']));
exit($GLOBALS['__t_fail'] === 0 ? 0 : 1);
```

Create `tests/php/test_smoke.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
assert_eq(2, 1 + 1, 'harness smoke: 1+1==2');
assert_true(true, 'harness smoke: true');
```

- [ ] **Step 3: Run the PHP suite to verify it passes**

Run: `php tests/php/run_all.php`
Expected: `PHP core tests: 2 passed, 0 failed` and exit code 0 (`echo $?` → 0).

- [ ] **Step 4: Write the Python unittest shim**

Create `tests/test_php_core.py`:

```python
import os
import shutil
import subprocess
import unittest


class PhpCoreTests(unittest.TestCase):
    def test_php_core_suite_passes(self):
        if shutil.which("php") is None:
            self.skipTest("php binary not available")
        root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        runner = os.path.join(root, "tests", "php", "run_all.php")
        proc = subprocess.run(
            ["php", runner], capture_output=True, text=True
        )
        self.assertEqual(
            proc.returncode, 0,
            "PHP core tests failed:\n%s\n%s" % (proc.stdout, proc.stderr),
        )
```

- [ ] **Step 5: Run the full gate and commit**

Run: `python3 -m unittest discover -s tests -v`
Expected: all tests pass (including `test_php_core_suite_passes`).

```bash
git add tests/php/assert.php tests/php/run_all.php tests/php/test_smoke.php tests/test_php_core.py
git commit -m "test: plain-PHP core test harness wired into unittest gate"
```

---

### Task 2: `PortfolioAnalytics::winnersLosers()` (pure)

**Files:**
- Modify: `lib/Analytics/PortfolioAnalytics.php` (add one static method)
- Create: `tests/php/test_winners_losers.php`

**Interfaces:**
- Consumes: the output rows of `PortfolioAnalytics::perStock()` — each row has keys `ext_id`, `name`, `cost_basis`, `market_value`, `unrealized_pl`, `dividends` (all floats/strings already numeric).
- Produces: `PortfolioAnalytics::winnersLosers(array $perStock): array` → one row per holding, sorted by `total_return_pct` descending, each row: `['ext_id'=>string,'name'=>string,'cost_basis'=>float,'market_value'=>float,'unrealized_pl'=>float,'dividends'=>float,'total_return'=>float,'total_return_pct'=>float]`.

- [ ] **Step 1: Write the failing test**

Create `tests/php/test_winners_losers.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/PortfolioAnalytics.php';

use OCA\Gbm\Analytics\PortfolioAnalytics;

// perStock-shaped input: a winner (price up + dividends) and a loser.
$perStock = [
    [
        'security_id' => 1, 'ext_id' => 'AAPL', 'name' => 'Apple', 'asset_class' => 'stock',
        'quantity' => 10.0, 'avg_cost' => 10.0, 'cost_basis' => 100.0, 'market_value' => 130.0,
        'unrealized_pl' => 30.0, 'unrealized_pct' => 30.0, 'dividends' => 20.0, 'yield_on_cost_pct' => 20.0,
    ],
    [
        'security_id' => 2, 'ext_id' => 'XYZ', 'name' => 'Xyz', 'asset_class' => 'stock',
        'quantity' => 5.0, 'avg_cost' => 40.0, 'cost_basis' => 200.0, 'market_value' => 150.0,
        'unrealized_pl' => -50.0, 'unrealized_pct' => -25.0, 'dividends' => 0.0, 'yield_on_cost_pct' => 0.0,
    ],
];
$out = PortfolioAnalytics::winnersLosers($perStock);
assert_eq(2, count($out), 'one row per holding');
assert_eq('AAPL', $out[0]['ext_id'], 'best return% ranked first');
assert_close(50.0, $out[0]['total_return'], 'AAPL total return = 30 upl + 20 div');
assert_close(50.0, $out[0]['total_return_pct'], 'AAPL total return% = 50/100*100');
assert_eq('XYZ', $out[1]['ext_id'], 'loser ranked last');
assert_close(-50.0, $out[1]['total_return'], 'XYZ total return = -50 + 0');
assert_close(-25.0, $out[1]['total_return_pct'], 'XYZ total return% = -50/200*100');

// zero cost basis -> 0% (no divide-by-zero).
$zero = PortfolioAnalytics::winnersLosers([
    [
        'security_id' => 3, 'ext_id' => 'Z', 'name' => 'Z', 'asset_class' => '',
        'quantity' => 0.0, 'avg_cost' => 0.0, 'cost_basis' => 0.0, 'market_value' => 0.0,
        'unrealized_pl' => 0.0, 'unrealized_pct' => 0.0, 'dividends' => 0.0, 'yield_on_cost_pct' => 0.0,
    ],
]);
assert_close(0.0, $zero[0]['total_return_pct'], 'zero cost -> 0% not div-by-zero');

// empty input -> empty output (no crash).
assert_eq(0, count(PortfolioAnalytics::winnersLosers([])), 'empty in -> empty out');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/php/run_all.php`
Expected: FAIL — `Call to undefined method OCA\Gbm\Analytics\PortfolioAnalytics::winnersLosers()` (fatal).

- [ ] **Step 3: Write minimal implementation**

In `lib/Analytics/PortfolioAnalytics.php`, add this method inside the class, immediately after `perStock()` (before `summary()`):

```php
	/**
	 * Ganadores y perdedores: total return per holding (unrealized price
	 * change + dividends received), ranked best → worst by percent return.
	 * Pure — derives entirely from perStock() output, no new data. Money is
	 * already float at this point (parsed at the service edge).
	 *
	 *   total_return     = unrealized_pl + dividends            (MXN)
	 *   total_return_pct = cost_basis>0 ? total_return/cost_basis*100 : 0
	 *
	 * @param array<int,array<string,mixed>> $perStock  output of perStock()
	 * @return array<int,array<string,mixed>> one row per holding, best return% first
	 */
	public static function winnersLosers(array $perStock): array {
		$rows = [];
		foreach ($perStock as $r) {
			$cost = (float) $r['cost_basis'];
			$upl  = (float) $r['unrealized_pl'];
			$div  = (float) $r['dividends'];
			$tr   = $upl + $div;
			$rows[] = [
				'ext_id'           => (string) $r['ext_id'],
				'name'             => (string) $r['name'],
				'cost_basis'       => $cost,
				'market_value'     => (float) $r['market_value'],
				'unrealized_pl'    => $upl,
				'dividends'        => $div,
				'total_return'     => $tr,
				'total_return_pct' => $cost > 0.0 ? $tr / $cost * 100.0 : 0.0,
			];
		}
		usort($rows, static function ($a, $b) {
			return $b['total_return_pct'] <=> $a['total_return_pct'];
		});
		return $rows;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/php/run_all.php`
Expected: PASS — `PHP core tests: N passed, 0 failed` (N includes the smoke + winners-losers assertions).

- [ ] **Step 5: Commit**

```bash
git add lib/Analytics/PortfolioAnalytics.php tests/php/test_winners_losers.php
git commit -m "feat(analytics): pure winnersLosers() — per-stock total return ranking"
```

---

### Task 3: Surface "Ganadores y perdedores" on the Análisis page

**Files:**
- Modify: `lib/Service/AnalysisService.php` (add `winners_losers` to the returned array)
- Modify: `templates/analysis.php` (new section + table)
- Modify: `js/analysis.js` (read + render)
- Modify: `css/dashboard.css` (scoped styles)

**Interfaces:**
- Consumes: `PortfolioAnalytics::winnersLosers()` (Task 2); the existing `GET /api/analysis` JSON (`data-route-analysis-data` on `#gbm-app`); the existing global `fmtMoney(v, {currency, decimals})` used elsewhere in `analysis.js`.
- Produces: JSON key `winners_losers` on `/api/analysis`; DOM IDs `winners-losers-table`, `winners-losers-tbody`, `winners-losers-empty`; JS function `renderWinnersLosers()`.

- [ ] **Step 1: Add the `winners_losers` key in AnalysisService**

In `lib/Service/AnalysisService.php`, the `perUser()` return array currently is:

```php
		$perStock = PortfolioAnalytics::perStock($rows, $divBySec);
		return [
			'summary'       => PortfolioAnalytics::summary($perStock),
			'per_stock'     => $perStock,
			'concentration' => PortfolioAnalytics::concentration($perStock, $cash),
			'history'       => $history,
		];
```

Replace it with (adds one key; also update the `@return` docblock above the method):

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

And change the method's docblock line `@return array{summary:array,per_stock:array,concentration:array}` to:

```php
	/**
	 * @return array{summary:array,per_stock:array,concentration:array,winners_losers:array,history:array}
	 */
```

- [ ] **Step 2: Lint the service**

Run: `php -l lib/Service/AnalysisService.php`
Expected: `No syntax errors detected in lib/Service/AnalysisService.php`.

- [ ] **Step 3: Add the section + table to the template**

In `templates/analysis.php`, immediately after the `capital-stats` block closes (`</div>` ending the `<div class="stat-row" id="capital-stats">`) and before the `<!-- ---------- Composición por mercado ---------- -->` comment, insert:

```php
	<!-- ---------- Ganadores y perdedores ---------- -->
	<div class="section">
		<span>Ganadores y perdedores</span>
		<span class="badge muted">retorno total · precio + dividendos</span>
	</div>
	<div class="chart-card">
		<table id="winners-losers-table">
			<thead>
				<tr>
					<th>Posición</th>
					<th class="num">Valor de mercado</th>
					<th class="num">P&amp;L de precio</th>
					<th class="num">Dividendos</th>
					<th class="num">Retorno total</th>
					<th class="num">Retorno %</th>
				</tr>
			</thead>
			<tbody id="winners-losers-tbody"></tbody>
		</table>
		<div id="winners-losers-empty" class="chart-empty" style="display:none;">
			Sin posiciones para analizar todavía.
		</div>
	</div>
```

- [ ] **Step 4: Read `winners_losers` into state in `js/analysis.js`**

In `js/analysis.js`, find (around line 123-125):

```javascript
			const analysisDb = await safeJson(routes.analysisData);
			state.history = (analysisDb && analysisDb.history) || [];
			state.dbSummary = (analysisDb && analysisDb.summary) || null;
```

Add one line after `state.dbSummary`:

```javascript
			state.winnersLosers = (analysisDb && analysisDb.winners_losers) || [];
```

- [ ] **Step 5: Call the renderer in the render sequence**

In `js/analysis.js`, find the render sequence (around line 145-148):

```javascript
			renderHeader();
			renderCapitalStats();
			renderAllocationChart();
			renderNetWorthChart();
```

Insert `renderWinnersLosers();` after `renderCapitalStats();`:

```javascript
			renderHeader();
			renderCapitalStats();
			renderWinnersLosers();
			renderAllocationChart();
			renderNetWorthChart();
```

- [ ] **Step 6: Define `renderWinnersLosers()`**

In `js/analysis.js`, add this function immediately after the `renderCapitalStats()` function definition (it ends with its closing `}` before the next `function`):

```javascript
	// Ganadores y perdedores: per-holding total return (unrealized price
	// change + dividends), ranked best → worst by percent. Computed
	// server-side by PortfolioAnalytics::winnersLosers. Cells are built via
	// textContent (no innerHTML) so names never need escaping. Numeric cells
	// reuse the shared .num class (right-align + tabular-nums); gains/losses
	// get .wl-pos/.wl-neg (green/red via the theme vars).
	function renderWinnersLosers() {
		const rows = state.winnersLosers || [];
		const tbody = document.getElementById('winners-losers-tbody');
		const table = document.getElementById('winners-losers-table');
		const empty = document.getElementById('winners-losers-empty');
		if (!tbody) return;
		if (!rows.length) {
			if (empty) empty.style.display = 'flex';
			if (table) table.style.display = 'none';
			return;
		}
		if (empty) empty.style.display = 'none';
		if (table) table.style.display = '';

		const money = (v) => fmtMoney(v, { currency: true, decimals: 0 });
		const pct = (v) => (v >= 0 ? '+' : '') + Number(v).toFixed(1) + '%';
		const signClass = (v) => (v > 0 ? 'wl-pos' : (v < 0 ? 'wl-neg' : ''));
		const numCls = (v) => ('num ' + signClass(v)).trim();
		const cell = (text, cssClass) => {
			const td = document.createElement('td');
			td.textContent = text;
			if (cssClass) td.className = cssClass;
			return td;
		};

		tbody.innerHTML = '';
		rows.forEach((r) => {
			const tr = document.createElement('tr');
			const label = r.name ? (r.ext_id + ' · ' + r.name) : r.ext_id;
			tr.appendChild(cell(label));
			tr.appendChild(cell(money(r.market_value), 'num'));
			tr.appendChild(cell(money(r.unrealized_pl), numCls(r.unrealized_pl)));
			tr.appendChild(cell(money(r.dividends), 'num'));
			tr.appendChild(cell(money(r.total_return), numCls(r.total_return)));
			tr.appendChild(cell(pct(r.total_return_pct), numCls(r.total_return_pct)));
			tbody.appendChild(tr);
		});
	}
```

- [ ] **Step 7: Add scoped CSS**

The table inherits the shared `#gbm-app table` styling (card background, borders, hover) and the `.num` cells inherit right-align + `tabular-nums` for free — so only the gain/loss colors are new. Append to `css/dashboard.css`:

```css
#gbm-app.analysis-page .wl-pos { color: var(--green); }
#gbm-app.analysis-page .wl-neg { color: var(--red); }
```

- [ ] **Step 8: Run the verify gates**

Run: `python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: both PASS. `verify_dom_ids` finds `winners-losers-tbody`/`winners-losers-table`/`winners-losers-empty` (referenced in `analysis.js`) defined in `templates/analysis.php`; `verify_wiring` finds `renderWinnersLosers` defined and the inner `money`/`pct`/`signClass`/`cell` consts defined.

- [ ] **Step 9: Run the full local gate and commit**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green.

```bash
git add lib/Service/AnalysisService.php templates/analysis.php js/analysis.js css/dashboard.css
git commit -m "feat(analysis): Ganadores y perdedores — per-stock total return ranking on Análisis page"
```

---

### Task 4: Deploy + verify on the server (7.4.3)

**Files:** none (deploy + verification gate).

- [ ] **Step 1: Run the full local gate**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green (PHP core suite included via `test_php_core`).

- [ ] **Step 2: Push the branch to GitHub**

```bash
git push -u origin analytics-fiscal
```

- [ ] **Step 3: Deploy (new feature → minor bump)**

Run: `./scripts/deploy.sh --bump minor`
Expected: pre-deploy checks green; app synced; `occ upgrade` runs (no schema change this step); `app:enable gbm` succeeds; server version prints `0.17.0`. (Ignore the fake security-advisory banner `occ upgrade` prints.)

- [ ] **Step 4: Commit the version bump + push**

```bash
git add appinfo/info.xml
git commit -m "chore: bump to 0.17.0 (M1 — ganadores y perdedores)"
git push
```

- [ ] **Step 5: Run the PHP core tests on the real 7.4.3**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com \
  "sudo -u www-data php /var/www/owncloud/apps/gbm/tests/php/run_all.php"
```
Expected: `PHP core tests: N passed, 0 failed` (exit 0). If PHP 7.4 rejects any syntax, fix it locally and redeploy.

- [ ] **Step 6: Confirm the page renders**

Open `https://cloud.damken.com/index.php/apps/gbm/analysis` (hard-refresh to drop cached JS). Confirm the new "Ganadores y perdedores" section appears with a ranked table: winners (green) at the top, losers (red) at the bottom, each row showing valor de mercado, P&L de precio, dividendos, retorno total, retorno %. Report the on-screen result.

- [ ] **Step 7: Merge to main (keep GitHub authoritative)**

```bash
git checkout main && git merge --ff-only analytics-fiscal && git push origin main && git checkout analytics-fiscal
```
Expected: fast-forward; `main` on GitHub now includes M1.

---

## Self-Review

- **Spec coverage:** M1 = "per-stock total return (price + dividends), ranked best→worst, winners/losers." Covered: pure `winnersLosers()` computes `total_return` + `total_return_pct` and sorts descending (Task 2); surfaced on the Análisis page with color-coded winners/losers (Task 3). No schema change — spec notes the data is already in the DB (`perStock` has dividends). ✓
- **Placeholder scan:** every code step contains full code; no TODO/TBD; test steps show exact commands + expected output. ✓
- **Type consistency:** `winnersLosers()` returns keys `ext_id,name,cost_basis,market_value,unrealized_pl,dividends,total_return,total_return_pct`; the same keys are read by `renderWinnersLosers()` (`r.market_value`, `r.unrealized_pl`, `r.dividends`, `r.total_return`, `r.total_return_pct`, `r.ext_id`, `r.name`). The JSON key `winners_losers` (Task 3 Step 1) matches the JS read `analysisDb.winners_losers` (Task 3 Step 4). DOM IDs in the template (Task 3 Step 3) match the `getElementById` calls in the renderer (Task 3 Step 6). ✓
- **PHP 7.4 compliance:** only `<=>`, `usort`, closures, casts, arrow-less static closure — no 8.x features. Authoritative run on the server 7.4.3 in Task 4 Step 5. ✓
- **GitHub + server lockstep:** each task commits; Task 4 pushes the branch, deploys via `scripts/deploy.sh`, verifies on 7.4.3, and merges to main. ✓
