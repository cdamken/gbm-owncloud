# GBM P0 — Portafolio que reconcilia — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compute the Portafolio landing's money model server-side in PHP (one source) via `GET /api/summary`, so the headline KPIs and per-account cards reconcile; `js/dashboard.js` renders those values instead of computing them client-side.

**Architecture:** Two pure, unit-tested PHP classes (`Xirr`, `PortfolioReconcile`) + a thin DB-backed `SummaryService` (holdings/accounts/transactions) + a read-only `/api/summary` endpoint. `dashboard.js` fetches it and fills the existing KPI + account cards; the positions table stays client-side. No schema change.

**Tech Stack:** PHP 7.4 (server), ownCloud 10.13 app framework (legacy `Mapper`, auto-wired DI), vanilla JS, plain-PHP test harness via Python `unittest`.

## Global Constraints

- **PHP 7.4.3 target** (server; local 8.5). FORBIDDEN 8.x-only: `match`, `enum`, constructor promotion, union/intersection types, `?->`, named args, `readonly`, `str_contains`/`str_starts_with`/`str_ends_with` (use `strpos`). OK: `??`, arrow fns, typed properties, `<=>`, casts.
- **No schema change** — all data exists (`gbm_holdings`, `gbm_accounts`, `gbm_transactions`). `appinfo/database.xml` untouched.
- **One source:** the landing's headline totals AND per-account cards come from `/api/summary` — never a second client-side computation or the GBM API `plus_minus`.
- **Net contributions = external only:** `external_deposit` − `external_withdrawal` (internal `deposit`/`withdrawal` are traspasos between the user's own sub-accounts; they net to zero and are NOT contributions).
- **Label returns by method:** XIRR is "Rendimiento personal (money-weighted)"; unrealized % is labeled as unrealized. XIRR fallback when it can't converge: show "—" / "faltan flujos" (never "no converge").
- **CSP strict** (no inline `<script>`/`on*=`); **per-user isolation** (`$uid` from `IUserSession` via `GbmService::currentUserId()`, never from request input); money exact-string → float at the service edge; UI Spanish / code English.
- **Compute in PHP** — this migrates the KPI/XIRR math off `js/dashboard.js`. Trio dissolved: built here, no upstream.
- **Every task ends with a commit.** Final task pushes `analytics-fiscal`, deploys via `scripts/deploy.sh`, runs the authoritative PHP test pass on 7.4.3, verifies the page, merges to `main`.

## File Structure

| File | Responsibility | Task |
|------|----------------|------|
| `lib/Analytics/Xirr.php` | Pure: money-weighted rate from dated flows (PHP port of the JS solver) | 1 |
| `tests/php/test_xirr.php` | Unit tests for `Xirr` | 1 |
| `lib/Analytics/PortfolioReconcile.php` | Pure: money model + per-account breakdown from arrays | 2 |
| `tests/php/test_portfolio_reconcile.php` | Unit tests for `PortfolioReconcile` | 2 |
| `lib/Service/SummaryService.php` | DB-backed: load holdings/accounts/transactions → call pure core → `/api/summary` shape | 3 |
| `lib/Controller/ApiController.php` | `summary()` endpoint; DI `SummaryService` | 3 |
| `appinfo/routes.php` | `GET /api/summary` | 3 |
| `lib/Controller/PageController.php` | `'summary'` route param | 3 |
| `templates/main.php` | `data-route-summary` on `#gbm-app` | 3 |
| `js/dashboard.js` | fetch `/api/summary`; render KPI + account cards from it; drop client-side KPI/XIRR math | 4 |

---

### Task 1: `Xirr` (pure, PHP port) + tests

**Files:**
- Create: `lib/Analytics/Xirr.php`
- Create: `tests/php/test_xirr.php`

**Interfaces:**
- Produces: `Xirr::compute(array $flows, float $tol = 1e-7): ?float` where each flow is `['date'=>string(ISO), 'amount'=>float]` (sign convention: money IN negative, value OUT/terminal positive). Returns `null` on <2 parseable flows, all-same-sign, or non-convergence.

- [ ] **Step 1: Write the failing test**

Create `tests/php/test_xirr.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/Xirr.php';

use OCA\Gbm\Analytics\Xirr;

// -1000 in at t0, +1100 out one year later → ~10%
$r = Xirr::compute([
    ['date' => '2025-01-01', 'amount' => -1000.0],
    ['date' => '2026-01-01', 'amount' => 1100.0],
]);
assert_true($r !== null, 'xirr converges for a simple 2-flow case');
assert_close(0.10, $r, 'xirr ≈ 10%', 0.01);

// two contributions then a terminal value → converges, positive
$r2 = Xirr::compute([
    ['date' => '2025-01-01', 'amount' => -1000.0],
    ['date' => '2025-07-01', 'amount' => -500.0],
    ['date' => '2026-01-01', 'amount' => 1700.0],
]);
assert_true($r2 !== null && $r2 > 0.0, 'xirr converges positive with mid contribution');

// all same sign → null
assert_eq(null, Xirr::compute([
    ['date' => '2025-01-01', 'amount' => -100.0],
    ['date' => '2026-01-01', 'amount' => -50.0],
]), 'all-negative flows -> null');

// fewer than 2 flows → null
assert_eq(null, Xirr::compute([['date' => '2025-01-01', 'amount' => -100.0]]), '<2 flows -> null');

// unparseable dates drop below 2 → null
assert_eq(null, Xirr::compute([
    ['date' => '', 'amount' => -100.0],
    ['date' => 'nope', 'amount' => 100.0],
]), 'unparseable dates -> null');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/php/run_all.php`
Expected: FAIL — `Class "OCA\Gbm\Analytics\Xirr" not found` (fatal).

- [ ] **Step 3: Write the implementation**

Create `lib/Analytics/Xirr.php` (PHP port of `js/dashboard.js:211-256`, PHP 7.4):

```php
<?php
/**
 * Money-weighted return (XIRR), annualized, from dated cash flows. Pure,
 * PHP 7.4. Ported from the JS solver in js/dashboard.js (Newton-Raphson with
 * a bisection fallback). Sign convention: money IN = negative, value OUT /
 * terminal = positive.
 */

namespace OCA\Gbm\Analytics;

class Xirr {
	/**
	 * @param array $flows list of ['date'=>string(ISO),'amount'=>float]
	 * @return float|null null when <2 parseable flows, all same-sign, or no convergence
	 */
	public static function compute(array $flows, float $tol = 1e-7) {
		$parsed = [];
		foreach ($flows as $f) {
			$ts = strtotime((string) ($f['date'] ?? ''));
			if ($ts === false) {
				continue;
			}
			$parsed[] = ['t' => $ts, 'a' => (float) ($f['amount'] ?? 0.0)];
		}
		if (count($parsed) < 2) {
			return null;
		}
		usort($parsed, function ($x, $y) {
			return $x['t'] <=> $y['t'];
		});
		$t0 = $parsed[0]['t'];
		$days = [];
		$amounts = [];
		foreach ($parsed as $p) {
			$days[] = (int) floor(($p['t'] - $t0) / 86400);
			$amounts[] = $p['a'];
		}
		$allPos = true;
		$allNeg = true;
		foreach ($amounts as $a) {
			if ($a < 0) { $allPos = false; }
			if ($a > 0) { $allNeg = false; }
		}
		if ($allPos || $allNeg) {
			return null;
		}
		$n = count($amounts);
		foreach ([0.10, 0.0, -0.10, 0.30, -0.30, 0.50] as $guess) {
			$rate = $guess;
			$ok = true;
			for ($it = 0; $it < 80; $it++) {
				$npv = self::npv($rate, $days, $amounts);
				$dnpv = 0.0;
				for ($i = 0; $i < $n; $i++) {
					$d = $days[$i];
					$dnpv += (-$d / 365.0) * $amounts[$i] / pow(1 + $rate, $d / 365.0 + 1);
				}
				if (!is_finite($npv) || !is_finite($dnpv) || abs($dnpv) < 1e-12) {
					$ok = false;
					break;
				}
				$newRate = $rate - $npv / $dnpv;
				if ($newRate <= -0.999) {
					$newRate = -0.99;
				}
				if (abs($newRate - $rate) < $tol) {
					return $newRate;
				}
				$rate = $newRate;
			}
			if ($ok) {
				continue;
			}
		}
		$lo = -0.95;
		$hi = 10.0;
		$fLo = self::npv($lo, $days, $amounts);
		$fHi = self::npv($hi, $days, $amounts);
		if (!is_finite($fLo) || !is_finite($fHi) || $fLo * $fHi > 0) {
			return null;
		}
		for ($it = 0; $it < 120; $it++) {
			$mid = ($lo + $hi) / 2;
			$fMid = self::npv($mid, $days, $amounts);
			if (!is_finite($fMid)) {
				return null;
			}
			if (abs($fMid) < $tol || abs($hi - $lo) < $tol) {
				return $mid;
			}
			if ($fLo * $fMid < 0) {
				$hi = $mid;
				$fHi = $fMid;
			} else {
				$lo = $mid;
				$fLo = $fMid;
			}
		}
		return ($lo + $hi) / 2;
	}

	private static function npv(float $rate, array $days, array $amounts): float {
		$s = 0.0;
		$n = count($amounts);
		for ($i = 0; $i < $n; $i++) {
			$s += $amounts[$i] / pow(1 + $rate, $days[$i] / 365.0);
		}
		return $s;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/php/run_all.php`
Expected: PASS — `PHP core tests: N passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add lib/Analytics/Xirr.php tests/php/test_xirr.php
git commit -m "feat(analytics): pure PHP Xirr (money-weighted return) ported from JS"
```

---

### Task 2: `PortfolioReconcile` (pure) + tests

**Files:**
- Create: `lib/Analytics/PortfolioReconcile.php`
- Create: `tests/php/test_portfolio_reconcile.php`

**Interfaces:**
- Consumes: `$holdings` = list of `['accountId'=>int,'qty'=>float,'avgCost'=>float,'marketValue'=>float]`; `$accounts` = list of `['id'=>int,'key'=>string,'name'=>string,'cash'=>float]`; `$netContrib` float; `$income` float.
- Produces: `PortfolioReconcile::build(array $holdings, array $accounts, float $netContrib, float $income): array` → the money model (keys below). `xirr`/`xirr_status` are added by the service, not here.

- [ ] **Step 1: Write the failing test**

Create `tests/php/test_portfolio_reconcile.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/PortfolioReconcile.php';

use OCA\Gbm\Analytics\PortfolioReconcile;

// Account 1 (id 1): 2 holdings; Account 2 (id 2): 1 holding; Account 3 (id 3): no holdings, cash only.
$holdings = [
    ['accountId' => 1, 'qty' => 10.0, 'avgCost' => 10.0, 'marketValue' => 130.0], // cost 100, mv 130
    ['accountId' => 1, 'qty' => 5.0,  'avgCost' => 20.0, 'marketValue' => 90.0],   // cost 100, mv 90
    ['accountId' => 2, 'qty' => 4.0,  'avgCost' => 50.0, 'marketValue' => 250.0],  // cost 200, mv 250
];
$accounts = [
    ['id' => 1, 'key' => 'EP01', 'name' => 'Personal', 'cash' => 0.0],
    ['id' => 2, 'key' => 'EP02', 'name' => 'Trading',  'cash' => 0.0],
    ['id' => 3, 'key' => 'EP03', 'name' => 'Smart Cash', 'cash' => 500.0],
];
$m = PortfolioReconcile::build($holdings, $accounts, 400.0, 25.0);

assert_close(470.0, $m['market_value'], 'market_value = 130+90+250');
assert_close(400.0, $m['cost_basis'], 'cost_basis = 100+100+200');
assert_close(70.0,  $m['unrealized_pl'], 'unrealized = 470-400');
assert_close(17.5,  $m['unrealized_pct'], 'unrealized% = 70/400*100');
assert_close(500.0, $m['cash'], 'cash = 500');
assert_close(970.0, $m['total_value'], 'total = market 470 + cash 500');
assert_close(400.0, $m['net_contributions'], 'net contributions passthrough');
assert_close(25.0,  $m['income_net'], 'income passthrough');
assert_eq(null, $m['realized_pl'], 'realized deferred -> null');
assert_eq(3, $m['positions_count'], 'positions = holdings rows');

// per-account: unrealized sums back to the header
$acc = [];
foreach ($m['accounts'] as $a) { $acc[$a['key']] = $a; }
assert_eq(3, count($m['accounts']), 'all 3 accounts present (incl. cash-only)');
assert_close(20.0,  $acc['EP01']['unrealized_pl'], 'EP01 unrealized = (130-100)+(90-100)=20');
assert_close(50.0,  $acc['EP02']['unrealized_pl'], 'EP02 unrealized = 250-200');
assert_close(0.0,   $acc['EP03']['unrealized_pl'], 'cash-only account unrealized = 0 (not API plus_minus)');
assert_close(500.0, $acc['EP03']['value'], 'cash-only account value = its cash');
assert_close(220.0, $acc['EP01']['value'], 'EP01 value = holdings mv 220 + cash 0');
$sum = $acc['EP01']['unrealized_pl'] + $acc['EP02']['unrealized_pl'] + $acc['EP03']['unrealized_pl'];
assert_close($m['unrealized_pl'], $sum, 'sum of per-account unrealized == header unrealized');

// empty portfolio -> zeros, no div-by-zero
$e = PortfolioReconcile::build([], [], 0.0, 0.0);
assert_close(0.0, $e['unrealized_pct'], 'empty -> 0% not div-by-zero');
assert_eq(0, $e['positions_count'], 'empty -> 0 positions');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/php/run_all.php`
Expected: FAIL — `Class "OCA\Gbm\Analytics\PortfolioReconcile" not found`.

- [ ] **Step 3: Write the implementation**

Create `lib/Analytics/PortfolioReconcile.php`:

```php
<?php
/**
 * Portafolio "money model" — the reconciling set of headline lines + a
 * per-account breakdown, computed from ONE source (current holdings + accounts).
 * Pure, PHP 7.4. Realized P&L is deferred (needs full FIFO lot coverage) and
 * returned as null. Per-account unrealized P&L comes only from holdings, so it
 * always sums back to the header (kills the header-vs-cards divergence).
 */

namespace OCA\Gbm\Analytics;

class PortfolioReconcile {
	/**
	 * @param array $holdings list of ['accountId'=>int,'qty'=>float,'avgCost'=>float,'marketValue'=>float]
	 * @param array $accounts list of ['id'=>int,'key'=>string,'name'=>string,'cash'=>float]
	 * @param float $netContrib external deposits − external withdrawals
	 * @param float $income dividends + interest − withholding (net ISR)
	 * @return array money model (see keys below)
	 */
	public static function build(array $holdings, array $accounts, float $netContrib, float $income): array {
		$byAcct = [];
		$mvTotal = 0.0;
		$costTotal = 0.0;
		foreach ($holdings as $h) {
			$aid = (int) $h['accountId'];
			$mv = (float) $h['marketValue'];
			$cost = (float) $h['avgCost'] * (float) $h['qty'];
			if (!isset($byAcct[$aid])) {
				$byAcct[$aid] = ['mv' => 0.0, 'cost' => 0.0];
			}
			$byAcct[$aid]['mv'] += $mv;
			$byAcct[$aid]['cost'] += $cost;
			$mvTotal += $mv;
			$costTotal += $cost;
		}
		$cashTotal = 0.0;
		$acctOut = [];
		foreach ($accounts as $a) {
			$aid = (int) $a['id'];
			$cash = (float) $a['cash'];
			$cashTotal += $cash;
			$agg = $byAcct[$aid] ?? ['mv' => 0.0, 'cost' => 0.0];
			$acctOut[] = [
				'key'           => (string) $a['key'],
				'name'          => (string) $a['name'],
				'value'         => $agg['mv'] + $cash,
				'unrealized_pl' => $agg['mv'] - $agg['cost'],
			];
		}
		$unrealized = $mvTotal - $costTotal;
		return [
			'market_value'      => $mvTotal,
			'cost_basis'        => $costTotal,
			'unrealized_pl'     => $unrealized,
			'unrealized_pct'    => $costTotal > 0.0 ? $unrealized / $costTotal * 100.0 : 0.0,
			'cash'              => $cashTotal,
			'total_value'       => $mvTotal + $cashTotal,
			'net_contributions' => $netContrib,
			'income_net'        => $income,
			'realized_pl'       => null,
			'positions_count'   => count($holdings),
			'accounts'          => $acctOut,
		];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/php/run_all.php`
Expected: PASS (0 failed).

- [ ] **Step 5: Commit**

```bash
git add lib/Analytics/PortfolioReconcile.php tests/php/test_portfolio_reconcile.php
git commit -m "feat(analytics): pure PortfolioReconcile — reconciling money model + per-account"
```

---

### Task 3: `SummaryService` + `/api/summary` endpoint

**Files:**
- Create: `lib/Service/SummaryService.php`
- Modify: `lib/Controller/ApiController.php`
- Modify: `appinfo/routes.php`
- Modify: `lib/Controller/PageController.php`
- Modify: `templates/main.php`

**Interfaces:**
- Consumes: `PortfolioReconcile::build`, `Xirr::compute`, `FiscalClassifier::classify` (all pure); `HoldingMapper::findByUser` (`getAccountId/getSecurityId/getQuantity/getAvgCost/getMarketValue`), `AccountMapper::findByUser` (`getId/getAccountKey/getName/getCashAmount`), `TransactionMapper::findByUser` (`getType/getAmount/getBookedAt`); `GbmService::currentUserId()`.
- Produces: `SummaryService::perUser(string $uid): array` = `PortfolioReconcile::build(...)` output plus `'xirr'=>float|null` and `'xirr_status'=>'ok'|'insufficient_flows'`. `GET /api/summary` (route `api#summary`) returns it as JSON.

- [ ] **Step 1: Create `SummaryService`**

Create `lib/Service/SummaryService.php`:

```php
<?php
/**
 * Portafolio landing money model, DB-backed. Loads current holdings, accounts,
 * and transactions (per-user), derives net contributions (external flows only)
 * and net income (dividends+interest−withholding via FiscalClassifier), and
 * delegates the math to the pure PortfolioReconcile + Xirr. Single source for
 * the landing's headline + account cards.
 */

namespace OCA\Gbm\Service;

use OCA\Gbm\Analytics\FiscalClassifier;
use OCA\Gbm\Analytics\PortfolioReconcile;
use OCA\Gbm\Analytics\Xirr;
use OCA\Gbm\Db\AccountMapper;
use OCA\Gbm\Db\HoldingMapper;
use OCA\Gbm\Db\TransactionMapper;

class SummaryService {
	/** @var HoldingMapper */
	private $holdings;
	/** @var AccountMapper */
	private $accounts;
	/** @var TransactionMapper */
	private $transactions;

	public function __construct(HoldingMapper $holdings, AccountMapper $accounts, TransactionMapper $transactions) {
		$this->holdings = $holdings;
		$this->accounts = $accounts;
		$this->transactions = $transactions;
	}

	public function perUser(string $uid): array {
		$holdingRows = [];
		foreach ($this->holdings->findByUser($uid) as $h) {
			$holdingRows[] = [
				'accountId'   => (int) $h->getAccountId(),
				'qty'         => $this->f($h->getQuantity()),
				'avgCost'     => $this->f($h->getAvgCost()),
				'marketValue' => $this->f($h->getMarketValue()),
			];
		}
		$accountRows = [];
		foreach ($this->accounts->findByUser($uid) as $a) {
			$accountRows[] = [
				'id'   => (int) $a->getId(),
				'key'  => (string) $a->getAccountKey(),
				'name' => (string) $a->getName(),
				'cash' => $this->f($a->getCashAmount()),
			];
		}

		// External cash flows (net contributions + XIRR) and net income, in one pass.
		$netContrib = 0.0;
		$income = 0.0;
		$flows = [];
		foreach ($this->transactions->findByUser($uid) as $t) {
			$type = (string) $t->getType();
			$amt = $this->f($t->getAmount());
			if ($type === 'external_deposit') {
				$netContrib += abs($amt);
				$flows[] = ['date' => (string) $t->getBookedAt(), 'amount' => -abs($amt)];
			} elseif ($type === 'external_withdrawal') {
				$netContrib -= abs($amt);
				$flows[] = ['date' => (string) $t->getBookedAt(), 'amount' => abs($amt)];
			}
			$class = FiscalClassifier::classify(['category' => $type]);
			if ($class === 'dividend' || $class === 'interest') {
				$income += $amt;
			} elseif ($class === 'withholding') {
				$income -= abs($amt);
			}
		}

		$model = PortfolioReconcile::build($holdingRows, $accountRows, $netContrib, $income);

		// XIRR: external flows + current total value as the terminal positive flow.
		$xirr = null;
		$status = 'insufficient_flows';
		if (!empty($flows) && $model['total_value'] > 0.0) {
			$flows[] = ['date' => date('Y-m-d'), 'amount' => (float) $model['total_value']];
			$xirr = Xirr::compute($flows);
			if ($xirr !== null) {
				$status = 'ok';
			}
		}
		$model['xirr'] = $xirr;
		$model['xirr_status'] = $status;
		return $model;
	}

	/** DB money is an exact decimal string (or null) — parse at this edge only. */
	private function f($v): float {
		return $v === null || $v === '' ? 0.0 : (float) $v;
	}
}
```

- [ ] **Step 2: Wire `SummaryService` into `ApiController` + add the endpoint**

In `lib/Controller/ApiController.php`, add `use OCA\Gbm\Service\SummaryService;` with the other `use` lines. Add a `private $summary;` property (with the existing property declarations) and extend the constructor — it currently is:

```php
	public function __construct(string $appName, IRequest $request, GbmService $gbm, IngestService $ingest, AnalysisService $analysis, LotsService $lots, FiscalService $fiscal, FiscalFileService $fiscalFile) {
		parent::__construct($appName, $request);
		$this->gbm = $gbm;
		$this->ingest = $ingest;
		$this->analysis = $analysis;
		$this->lots = $lots;
		$this->fiscal = $fiscal;
		$this->fiscalFile = $fiscalFile;
	}
```

Replace with (add `SummaryService $summary` param + assignment; auto-wired):

```php
	public function __construct(string $appName, IRequest $request, GbmService $gbm, IngestService $ingest, AnalysisService $analysis, LotsService $lots, FiscalService $fiscal, FiscalFileService $fiscalFile, SummaryService $summary) {
		parent::__construct($appName, $request);
		$this->gbm = $gbm;
		$this->ingest = $ingest;
		$this->analysis = $analysis;
		$this->lots = $lots;
		$this->fiscal = $fiscal;
		$this->fiscalFile = $fiscalFile;
		$this->summary = $summary;
	}
```

(Add `private $summary;` next to the other `private $...;` property declarations.)

Add the endpoint (next to `analysisData()`):

```php
	/**
	 * DB-backed reconciling money model for the Portafolio landing: headline
	 * totals + per-account breakdown from ONE source. Read-only, per-session user.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function summary(): JSONResponse {
		try {
			return new JSONResponse($this->summary->perUser($this->gbm->currentUserId()));
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
```

- [ ] **Step 3: Add the route**

In `appinfo/routes.php`, after the `api#analysisData` entry, add:

```php
		['name' => 'api#summary',       'url' => '/api/summary',   'verb' => 'GET'],
```

- [ ] **Step 4: Pass the route to the template**

In `lib/Controller/PageController.php`, in the `$params['routes']` array (after `'analysis_data' => ...`), add:

```php
			'summary'       => $this->urlGenerator->linkToRoute('gbm.api.summary'),
```

- [ ] **Step 5: Add the data attr to `main.php`**

In `templates/main.php`, the `#gbm-app` tag ends with `data-route-update="<?php p($routes['update']); ?>">`. Insert the summary attr before that closing line:

```php
	data-route-summary="<?php p($routes['summary']); ?>"
	data-route-update="<?php p($routes['update']); ?>">
```

- [ ] **Step 6: Lint and commit**

Run: `php -l lib/Service/SummaryService.php && php -l lib/Controller/ApiController.php && php -l lib/Controller/PageController.php && php -r 'include "appinfo/routes.php"; echo "routes ok\n";'`
Expected: no syntax errors; `routes ok`.

```bash
git add lib/Service/SummaryService.php lib/Controller/ApiController.php appinfo/routes.php lib/Controller/PageController.php templates/main.php
git commit -m "feat(summary): /api/summary — server-side reconciling money model"
```

---

### Task 4: Render the landing from `/api/summary`

**Files:**
- Modify: `js/dashboard.js`

**Interfaces:**
- Consumes: `GET /api/summary` (route attr `data-route-summary` → `root.dataset.routeSummary`); the response shape from Task 3 (`market_value, cost_basis, unrealized_pl, unrealized_pct, cash, total_value, net_contributions, income_net, realized_pl, positions_count, xirr, xirr_status, accounts[]`).
- Produces: KPI cards + account cards rendered from `state.summary`. The client-side KPI/XIRR math is removed; the positions table + filters keep using `state.positionsFlat`.

- [ ] **Step 1: Read the summary route**

In `js/dashboard.js`, the `routes` object in `DOMContentLoaded` (≈ lines 772-779) currently is:

```javascript
	routes = {
		index:      root.dataset.routeIndex,
		orders:     root.dataset.routeOrders,
		ordersAll:  root.dataset.routeOrdersAll,
		data:       root.dataset.routeData,
		config:     root.dataset.routeConfig,
		update:     root.dataset.routeUpdate,
	};
```

Add the summary route:

```javascript
	routes = {
		index:      root.dataset.routeIndex,
		orders:     root.dataset.routeOrders,
		ordersAll:  root.dataset.routeOrdersAll,
		data:       root.dataset.routeData,
		config:     root.dataset.routeConfig,
		update:     root.dataset.routeUpdate,
		summary:    root.dataset.routeSummary,
	};
```

- [ ] **Step 2: Fetch `/api/summary` in `load()`**

In `js/dashboard.js` `load()` (≈ lines 98-137), the current `Promise.all` fetches accounts/positions/investments_groups/transactions/fx/last_update. Replace the fetch + assignment block so it also fetches `summary` and drops the two now-server-side sources (`investments_groups`, `transactions`). Current:

```javascript
		const [accountsRes, positionsRes, igRes, txRes, fxRes, lastUpdateRes] = await Promise.all([
			fetch(dataUrl('accounts'), opts),
			fetch(dataUrl('positions'), opts),
			fetch(dataUrl('investments_groups'), opts),
			fetch(dataUrl('transactions'), opts),
			fetch(dataUrl('fx'), opts),
			fetch(dataUrl('last_update'), opts),
		]);
		const accounts = accountsRes.ok ? await accountsRes.json() : [];
		const positionsByAccount = positionsRes.ok ? await positionsRes.json() : {};
		const investmentsGroups = igRes.ok ? await igRes.json() : null;
		const transactions = txRes.ok ? await txRes.json() : null;
		const fx = fxRes.ok ? await fxRes.json() : null;
		usdMxnRate = fx && Number(fx.usdmxn) > 0 ? Number(fx.usdmxn) : null;
		const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';

		state.accounts = sortAccounts(accounts);
		state.positionsByAccount = positionsByAccount;
		state.investmentsGroups = investmentsGroups;
		state.transactions = transactions;
		state.lastUpdate = lastUpdate.trim();
```

Replace with:

```javascript
		const [summaryRes, accountsRes, positionsRes, fxRes, lastUpdateRes] = await Promise.all([
			fetch(routes.summary, opts),
			fetch(dataUrl('accounts'), opts),
			fetch(dataUrl('positions'), opts),
			fetch(dataUrl('fx'), opts),
			fetch(dataUrl('last_update'), opts),
		]);
		const summary = summaryRes.ok ? await summaryRes.json() : null;
		const accounts = accountsRes.ok ? await accountsRes.json() : [];
		const positionsByAccount = positionsRes.ok ? await positionsRes.json() : {};
		const fx = fxRes.ok ? await fxRes.json() : null;
		usdMxnRate = fx && Number(fx.usdmxn) > 0 ? Number(fx.usdmxn) : null;
		const lastUpdate = lastUpdateRes.ok ? await lastUpdateRes.text() : '';

		state.summary = summary;
		state.accounts = sortAccounts(accounts);
		state.positionsByAccount = positionsByAccount;
		state.lastUpdate = lastUpdate.trim();
```

(Also add `summary: null,` to the `state` object literal near the top of the IIFE, alongside `accounts`/`positionsByAccount`.)

- [ ] **Step 3: Render KPI cards from `state.summary`**

In `js/dashboard.js`, replace the entire `renderCards()` function (≈ lines 408-454) with a version that reads `state.summary`:

```javascript
	function renderCards() {
		const s = state.summary;
		if (!s) return;
		$('total-value').textContent = fmtMoney(s.total_value, { currency: true });
		$('investment-cost').textContent = fmtMoney(s.cost_basis, { currency: true });
		const pnlEl = $('total-pnl');
		pnlEl.textContent = fmtMoney(s.unrealized_pl, { sign: true, currency: true });
		pnlEl.className = 'value ' + pnlClass(s.unrealized_pl);
		const pctEl = $('total-pnl-pct');
		pctEl.textContent = fmtPct((Number(s.unrealized_pct) || 0) / 100);
		pctEl.className = 'delta ' + pnlClass(s.unrealized_pl);
		$('available-cash').textContent = fmtMoney(s.cash, { currency: true });
		$('num-positions').textContent = s.positions_count;
		$('num-accounts').textContent = (s.accounts || []).length;
		// XIRR — money-weighted; honest fallback when it can't converge.
		const xEl = $('xirr-value');
		const xDetail = $('xirr-detail');
		if (s.xirr_status === 'ok' && s.xirr != null) {
			xEl.textContent = fmtPct(s.xirr);
			xEl.className = 'value ' + pnlClass(s.xirr);
			if (xDetail) xDetail.textContent = 'personal · money-weighted';
		} else {
			xEl.textContent = '—';
			xEl.className = 'value muted';
			if (xDetail) xDetail.textContent = 'faltan flujos externos';
		}
	}
```

> Confirmed: `fmtPct(n)` (in `js/_shared.js:48`) returns `(n*100).toFixed(2)+'%'` — it takes a **fraction**. So `unrealized_pct` (a percentage like 17.5) is passed as `s.unrealized_pct / 100`, and `xirr` (a rate like 0.10) is passed as-is. The call sites above are already correct — do not change them.

- [ ] **Step 4: Render account cards from `state.summary.accounts`**

Replace the entire `renderAccounts()` function (≈ lines 456-476) with:

```javascript
	function renderAccounts() {
		const s = state.summary;
		const list = (s && s.accounts) || [];
		const grid = $('accounts-grid');
		$('accounts-count').textContent = list.length;
		grid.innerHTML = list.map(a => {
			return '<div class="account-chip">' +
				'<div class="acc-name">' + (a.name || '—') + '</div>' +
				'<div class="acc-id">' + (a.key || '') + '</div>' +
				'<div class="acc-value">' + fmtMoney(a.value, { currency: true }) + '</div>' +
				'<div class="acc-pnl ' + pnlClass(a.unrealized_pl) + '">' +
					fmtMoney(a.unrealized_pl, { sign: true, currency: true }) +
				'</div>' +
			'</div>';
		}).join('');
	}
```

> This drops the per-account "N pos." flag and the account-type color pill (they depended on the old client-side account objects). Keeping the account name, key, value, and the now-reconciling P&L is the P0 goal; richer chips are a P1 concern. Note this simplification in the report.

- [ ] **Step 5: Remove the now-dead client-side KPI/XIRR math**

The following functions are no longer used (KPIs + XIRR are server-side now). Remove them from `js/dashboard.js`: `aggregates()` (≈ 331-361), `xirr()` (≈ 218-256), `_xirrNpv()` (≈ 211-217), `buildXirrCashflows()` (≈ 257-275). For `accountValue()` (≈ 165-184) and `accountPnL()` (≈ 189-203): grep the file for remaining references — if their only callers were `renderCards`/`renderAccounts`/`aggregates` (now changed/removed), delete them too; if the positions table or any filter still references them, leave them. Report which you removed vs kept.

- [ ] **Step 6: Run the gates**

Run: `python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: both PASS. `verify_dom_ids`: the KPI/account IDs referenced (`total-value`, `investment-cost`, `total-pnl`, `total-pnl-pct`, `xirr-value`, `xirr-detail`, `available-cash`, `num-positions`, `num-accounts`, `accounts-grid`, `accounts-count`) all exist in `templates/main.php`. `verify_wiring`: no reference to a removed function (`aggregates`/`xirr`/`_xirrNpv`/`buildXirrCashflows`/possibly `accountValue`/`accountPnL`) remains.

- [ ] **Step 7: Run the full local gate and commit**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green.

```bash
git add js/dashboard.js
git commit -m "feat(portfolio): render landing KPIs + account cards from /api/summary (one source)"
```

---

### Task 5: Deploy + verify on the server (7.4.3)

**Files:** none (deploy + verification gate).

- [ ] **Step 1: Full local gate**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green (PHP core suite includes `test_xirr` + `test_portfolio_reconcile`).

- [ ] **Step 2: Push the branch**

```bash
git push origin analytics-fiscal
```

- [ ] **Step 3: Deploy (new feature → minor bump)**

Run: `./scripts/deploy.sh --bump minor`
Expected: pre-deploy checks green; app synced; `occ upgrade` (no schema change); `app:enable gbm`; server version `0.21.0`. (Ignore the fake security-advisory banner.)

- [ ] **Step 4: Commit the version bump + push**

```bash
git add appinfo/info.xml
git commit -m "chore: bump to 0.21.0 (P0 — reconciling Portafolio via /api/summary)"
git push
```

- [ ] **Step 5: PHP core tests on the real 7.4.3**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com \
  "sudo -u www-data php /var/www/owncloud/apps/gbm/tests/php/run_all.php"
```
Expected: `PHP core tests: N passed, 0 failed` (includes xirr + reconcile).

- [ ] **Step 6: Verify the reconciliation on the live page**

Open `https://cloud.damken.com/index.php/apps/gbm/` (hard-refresh). Confirm: **header P&L acumulado = the sum of the per-account P&L on the cards** (the bug is gone); Valor total = market value + cash and reads as distinct labeled lines (no "value < cost yet P&L positive" contradiction); XIRR shows a real % OR "—/faltan flujos externos" (not "no converge"); Posiciones count matches. Report the on-screen numbers. (If `net_contributions`/XIRR are empty because the data has no `external_*` rows, that is expected and honest — note it.)

- [ ] **Step 7: Merge to main**

```bash
git checkout main && git merge --ff-only analytics-fiscal && git push origin main && git checkout analytics-fiscal
```
Expected: fast-forward; `main` includes P0.

---

## Self-Review

- **Spec coverage:** money model (7 lines + total) → `PortfolioReconcile` (Task 2) + `SummaryService` (Task 3); net contributions = external only → Task 3 loop; income net ISR → Task 3 via `FiscalClassifier`; realized deferred (null) → Task 2; XIRR money-weighted + honest fallback → `Xirr` (Task 1) + Task 3 status + Task 4 render; one source kills bugs #1/#2/#3 → server-side model + per-account from holdings (Task 2/3), rendered in Task 4; compute-in-PHP migration → Task 4 removes client math; no schema change → confirmed. ✓
- **Placeholder scan:** every step has full code + exact commands/expected output. The two `>` notes (fmtPct semantics; dropped chip extras) are explicit implementer instructions with a reported outcome, not TBDs. ✓
- **Type consistency:** `SummaryService::perUser` returns exactly `PortfolioReconcile::build` keys + `xirr`/`xirr_status`; Task 4 reads those keys (`total_value`, `cost_basis`, `unrealized_pl`, `unrealized_pct`, `cash`, `positions_count`, `accounts[].{name,key,value,unrealized_pl}`, `xirr`, `xirr_status`). `Xirr::compute` flow shape `['date','amount']` matches what Task 3 builds. Holding/Account getters match the audited entity methods (`getAccountId/getAvgCost/getMarketValue`; `getId/getAccountKey/getName/getCashAmount`). Route `gbm.api.summary` ↔ `api#summary` ↔ `data-route-summary` ↔ `root.dataset.routeSummary`. ✓
- **PHP 7.4:** `Xirr`/`PortfolioReconcile`/`SummaryService` use only `<=>`, `??`, casts, `strtotime`, `date`, `pow`, closures — no 8.x-only features. ✓
- **DI:** `SummaryService(HoldingMapper, AccountMapper, TransactionMapper)` and its `ApiController` injection are auto-wired; no `Application.php` change. ✓
- **GitHub + server:** each task commits; Task 5 pushes, deploys, verifies on 7.4.3 + live page, merges to main. ✓
