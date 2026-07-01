# GBM Analytics & Fiscal — Phase 0+1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a durable, additive data foundation (fiscal classification of transactions + external cash-flow capture) and ship an income-side Annual Fiscal Report (dividends / interest / withholdings per calendar year) with a page and declarable CSV export.

**Architecture:** Pure, framework-agnostic PHP classes in `lib/Analytics/` do all computation and are unit-tested with a plain-PHP harness (`tests/php/`). The ownCloud DB layer (legacy `OCP\AppFramework\Db\Mapper` + `appinfo/database.xml`) persists data; `IngestService` classifies on ingest; a thin `FiscalService` loads rows and delegates to the pure core; `ApiController`/`PageController` expose JSON + a page; `js/fiscal.js` renders.

**Tech Stack:** PHP 7.4 (server), ownCloud 10.13 app framework, vanilla JS (IIFE, no framework), MySQL via legacy Mapper, plain-PHP test harness invoked from Python `unittest`.

## Global Constraints

- **PHP 7.4.3 target** (the server; not upgradable). FORBIDDEN 8.x features: `enum`, `match`, constructor property promotion, union/intersection types, `?->` nullsafe, named arguments, `readonly`, `str_contains`/`str_starts_with`/`str_ends_with` (use `strpos`). Arrow functions (`fn`), typed properties, `??`, `??=` are OK (7.4).
- **Schema evolution is ADDITIVE ONLY.** Only add `<field>`/`<table>` in `appinfo/database.xml`; never drop/recreate `oc_gbm_*`. `<overwrite>false</overwrite>` is already set. Accumulating snapshot/transaction history must never be lost.
- **Money is stored as `text`** (exact decimal strings); no `decimal`/`date`/`timestamp` types exist in this XML dialect. Dates are `text` (ISO strings). All arithmetic happens in PHP (floats acceptable for these estimates).
- **UI strings in Spanish**; code/identifiers/comments/commits in English.
- **Every task ends with a commit.** Every phase ends with: push the `analytics-fiscal` branch to GitHub AND deploy to the server via `scripts/deploy.sh`, then verify on the server (including running the PHP core tests on the real 7.4.3).
- **Deploy only via `scripts/deploy.sh`** (never raw rsync). Schema changes take effect through the `occ upgrade` the script chains.
- **Fiscal figures are ESTIMATES, not tax advice.** Realized capital gains / ISR-on-gains are out of scope here (Phase 1b, pending accountant).
- **Test harness:** fast local iteration with `php tests/php/<file>.php` (local PHP 8.5); the authoritative pass runs on the server's 7.4.3 after deploy.

## File Structure

| File | Responsibility | Task |
|------|----------------|------|
| `tests/php/assert.php` | Tiny assertion helper (pass/fail counters) | 1 |
| `tests/php/run_all.php` | Runs every `tests/php/test_*.php`, exits 1 on failure | 1 |
| `tests/test_php_core.py` | `unittest` shim that shells out to `run_all.php` (hooks the PHP suite into the existing gate) | 1 |
| `lib/Analytics/FiscalClassifier.php` | Pure: transaction → `dividend\|interest\|withholding\|none`, + `fiscalYear()` | 2 |
| `appinfo/database.xml` | Additive: `fiscal_class`/`fiscal_year` on `gbm_transactions`; new `gbm_cash_flows`; `sector` on `gbm_securities` | 3 |
| `lib/Db/CashFlow.php`, `lib/Db/CashFlowMapper.php` | Entity + mapper for `gbm_cash_flows` | 4 |
| `lib/Analytics/CashFlowExtractor.php` | Pure: transaction → cash-flow record or null | 5 |
| `lib/Db/Transaction.php` | Add `fiscalClass`/`fiscalYear` properties | 6 |
| `lib/Service/IngestService.php` | Classify + extract cash flows on ingest | 6 |
| `lib/Analytics/FiscalReport.php` | Pure: classified rows → per-year income summary | 8 |
| `lib/Service/FiscalService.php` | Load rows from DB → `FiscalReport::build` | 9 |
| `lib/Command/Fiscal.php`, `appinfo/register_command.php` | `occ gbm:fiscal <user>` verification surface | 9 |
| `lib/Controller/ApiController.php`, `appinfo/routes.php` | `/api/fiscal` JSON + `/export/fiscal-{year}.csv` | 10 |
| `lib/Controller/PageController.php`, `templates/fiscal.php`, `js/fiscal.js`, `js/_shared.js`, `css/dashboard.css` | `/fiscal` page + nav tab | 11 |

---

# Phase 0 — Data foundation + fiscal classification

### Task 1: Plain-PHP test harness

**Files:**
- Create: `tests/php/assert.php`
- Create: `tests/php/run_all.php`
- Create: `tests/php/test_smoke.php`
- Create: `tests/test_php_core.py`

**Interfaces:**
- Produces: global functions `assert_eq($expected,$actual,$label)`, `assert_true($cond,$label)`, `assert_close($expected,$actual,$label,$eps=0.001)`; runner script `tests/php/run_all.php` (exit 0 all-pass, 1 on any fail).

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

### Task 2: FiscalClassifier (pure)

**Files:**
- Create: `lib/Analytics/FiscalClassifier.php`
- Test: `tests/php/test_fiscal_classifier.php`

**Interfaces:**
- Produces: `FiscalClassifier::classify(array $tx): string` → one of `dividend|interest|withholding|none`; `FiscalClassifier::fiscalYear($processDate): ?int`.

- [ ] **Step 1: Write the failing test**

Create `tests/php/test_fiscal_classifier.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/FiscalClassifier.php';

use OCA\Gbm\Analytics\FiscalClassifier;

assert_eq('dividend',    FiscalClassifier::classify(['category' => 'dividend']),        'dividend category');
assert_eq('withholding', FiscalClassifier::classify(['category' => 'tax_withholding']), 'tax_withholding -> withholding');
assert_eq('interest',    FiscalClassifier::classify(['category' => 'repo_mature']),     'repo maturity -> interest');
assert_eq('none',        FiscalClassifier::classify(['category' => 'buy_stock']),       'buy -> none');
assert_eq('none',        FiscalClassifier::classify(['category' => 'sell_fund']),       'sell -> none (gains are Phase 1b)');
assert_eq('none',        FiscalClassifier::classify([]),                                'missing category -> none');
assert_eq(2026, FiscalClassifier::fiscalYear('2026-04-02T10:44:18-06:00'), 'year from iso datetime');
assert_eq(2025, FiscalClassifier::fiscalYear('2025-12-31'),                'year from date');
assert_eq(null, FiscalClassifier::fiscalYear(''),                         'empty -> null');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/php/run_all.php`
Expected: FAIL — `Class "OCA\Gbm\Analytics\FiscalClassifier" not found` (fatal) or failing assertions.

- [ ] **Step 3: Write minimal implementation**

Create `lib/Analytics/FiscalClassifier.php`:

```php
<?php
/**
 * Maps a raw GBM transaction to a fiscal class for the annual income report.
 * Pure — no OCP dependency; unit-tested in tests/php/. PHP 7.4 compatible.
 *
 * Realized capital gains are NOT classified here (they need FIFO lots and are
 * Phase 1b, pending the accountant). Sells therefore return 'none'.
 */

namespace OCA\Gbm\Analytics;

class FiscalClassifier {
    /** GBM `category` → fiscal class (the stable, data-driven mapping). */
    const BY_CATEGORY = [
        'dividend'        => 'dividend',
        'tax_withholding' => 'withholding',
        'repo_mature'     => 'interest',
    ];

    /**
     * @param array $tx one transaction dict from transactions.json
     * @return string one of: dividend|interest|withholding|none
     */
    public static function classify(array $tx) {
        $cat = isset($tx['category']) ? strtolower(trim((string) $tx['category'])) : '';
        if (isset(self::BY_CATEGORY[$cat])) {
            return self::BY_CATEGORY[$cat];
        }
        if (strpos($cat, 'dividend') !== false) {
            return 'dividend';
        }
        if (strpos($cat, 'withhold') !== false || strpos($cat, 'isr') !== false) {
            return 'withholding';
        }
        if (strpos($cat, 'interes') !== false || strpos($cat, 'interest') !== false) {
            return 'interest';
        }
        return 'none';
    }

    /**
     * Calendar year from a GBM process_date ("YYYY-MM-DD" or "YYYY-MM-DDT...").
     * @param mixed $processDate
     * @return int|null null when unparseable
     */
    public static function fiscalYear($processDate) {
        $s = trim((string) $processDate);
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $s, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/php/run_all.php`
Expected: PASS (all classifier assertions pass, 0 failed).

- [ ] **Step 5: Commit**

```bash
git add lib/Analytics/FiscalClassifier.php tests/php/test_fiscal_classifier.php
git commit -m "feat(fiscal): pure transaction fiscal classifier + fiscalYear"
```

---

### Task 3: Additive schema (database.xml)

**Files:**
- Modify: `appinfo/database.xml` (add fields to `gbm_transactions` + `gbm_securities`; add `gbm_cash_flows` table)

**Interfaces:**
- Produces: DB columns `gbm_transactions.fiscal_class` (text 16), `gbm_transactions.fiscal_year` (integer), `gbm_securities.sector` (text 64); table `gbm_cash_flows(id,user_id,external_id,direction,amount,flowed_at,description)` with unique index `gbm_cf_uq(user_id,external_id)` and index `gbm_cf_uid(user_id)`.

- [ ] **Step 1: Add the two fiscal fields to `gbm_transactions`**

In `appinfo/database.xml`, inside the `gbm_transactions` `<declaration>`, immediately after the `<field><name>booked_at</name>...</field>` line, add:

```xml
		<field><name>fiscal_class</name><type>text</type><length>16</length></field>
		<field><name>fiscal_year</name><type>integer</type><unsigned>true</unsigned></field>
```

- [ ] **Step 2: Add `sector` to `gbm_securities`**

Inside the `gbm_securities` `<declaration>`, immediately after the `<field><name>currency</name>...</field>` line, add:

```xml
		<field><name>sector</name><type>text</type><length>64</length></field>
```

- [ ] **Step 3: Add the `gbm_cash_flows` table**

Immediately before the closing `</database>` tag, add:

```xml
	<table>
		<name>*dbprefix*gbm_cash_flows</name>
		<declaration>
			<field><name>id</name><type>integer</type><notnull>true</notnull><autoincrement>true</autoincrement><unsigned>true</unsigned><primary>true</primary></field>
			<field><name>user_id</name><type>text</type><length>64</length><notnull>true</notnull></field>
			<field><name>external_id</name><type>text</type><length>128</length><notnull>true</notnull></field>
			<field><name>direction</name><type>text</type><length>8</length></field>  <!-- in | out -->
			<field><name>amount</name><type>text</type><length>32</length></field>    <!-- decimal string -->
			<field><name>flowed_at</name><type>text</type><length>32</length></field> <!-- ISO date string -->
			<field><name>description</name><type>text</type><length>255</length></field>
			<index>
				<name>gbm_cf_uq</name>
				<unique>true</unique>
				<field><name>user_id</name><sorting>ascending</sorting></field>
				<field><name>external_id</name><sorting>ascending</sorting></field>
			</index>
			<index>
				<name>gbm_cf_uid</name>
				<field><name>user_id</name><sorting>ascending</sorting></field>
			</index>
		</declaration>
	</table>
```

- [ ] **Step 4: Verify the XML is well-formed**

Run: `php -r 'simplexml_load_file("appinfo/database.xml") ?: exit(1); echo "OK\n";'`
Expected: `OK` (exit 0). If it prints warnings/exits 1, fix the malformed XML.

- [ ] **Step 5: Commit**

```bash
git add appinfo/database.xml
git commit -m "feat(db): additive schema — fiscal_class/fiscal_year, gbm_cash_flows, sector"
```

---

### Task 4: CashFlow entity + mapper

**Files:**
- Create: `lib/Db/CashFlow.php`
- Create: `lib/Db/CashFlowMapper.php`

**Interfaces:**
- Consumes: `gbm_cash_flows` table (Task 3).
- Produces: `CashFlow` entity (getters/setters for userId, externalId, direction, amount, flowedAt, description); `CashFlowMapper::findByUser(string $uid): array`, `CashFlowMapper::findByExternalId(string $uid, string $ext): ?CashFlow`.

- [ ] **Step 1: Create the entity**

Create `lib/Db/CashFlow.php`:

```php
<?php
/** External cash flow (deposit/withdrawal). Deduped by (user_id, external_id). */

namespace OCA\Gbm\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getExternalId()
 * @method void setExternalId(string $externalId)
 * @method string getDirection()
 * @method void setDirection(string $direction)
 * @method string getAmount()
 * @method void setAmount(string $amount)
 * @method string getFlowedAt()
 * @method void setFlowedAt(string $flowedAt)
 * @method string getDescription()
 * @method void setDescription(string $description)
 */
class CashFlow extends Entity {
	protected $userId;
	protected $externalId;
	protected $direction;
	protected $amount;
	protected $flowedAt;
	protected $description;
}
```

- [ ] **Step 2: Create the mapper**

Create `lib/Db/CashFlowMapper.php`:

```php
<?php
/** CashFlowMapper — external flows. Scoped by user_id, deduped by external_id. */

namespace OCA\Gbm\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class CashFlowMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_cash_flows', CashFlow::class);
	}

	/** @return CashFlow[] */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_cash_flows` '
			. 'WHERE `user_id` = ? ORDER BY `flowed_at` ASC';
		return $this->findEntities($sql, [$userId]);
	}

	/** Dedup lookup for the ingest upsert. */
	public function findByExternalId(string $userId, string $externalId): ?CashFlow {
		$sql = 'SELECT * FROM `*PREFIX*gbm_cash_flows` WHERE `user_id` = ? AND `external_id` = ?';
		try {
			return $this->findEntity($sql, [$userId, $externalId]);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
```

- [ ] **Step 3: Lint both files**

Run: `php -l lib/Db/CashFlow.php && php -l lib/Db/CashFlowMapper.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add lib/Db/CashFlow.php lib/Db/CashFlowMapper.php
git commit -m "feat(db): CashFlow entity + mapper (gbm_cash_flows)"
```

---

### Task 5: CashFlowExtractor (pure)

**Files:**
- Create: `lib/Analytics/CashFlowExtractor.php`
- Test: `tests/php/test_cash_flow_extractor.php`

**Interfaces:**
- Produces: `CashFlowExtractor::extract(array $tx): ?array` → `['external_id','direction','amount','flowed_at','description']` or `null`.

- [ ] **Step 1: Write the failing test**

Create `tests/php/test_cash_flow_extractor.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/CashFlowExtractor.php';

use OCA\Gbm\Analytics\CashFlowExtractor;

$dep = CashFlowExtractor::extract([
    'transaction_id' => 825865319, 'category' => 'deposit',
    'amount' => 84702.0, 'process_date' => '2026-05-12', 'description' => 'DEPOSITO',
]);
assert_true($dep !== null, 'deposit extracted');
assert_eq('in', $dep['direction'], 'deposit -> in');
assert_eq('825865319', $dep['external_id'], 'external_id stringified');
assert_eq('2026-05-12', $dep['flowed_at'], 'flowed_at from process_date');

$wd = CashFlowExtractor::extract(['transaction_id' => 1, 'category' => 'withdrawal', 'amount' => 5]);
assert_eq('out', $wd['direction'], 'withdrawal -> out');

assert_eq(null, CashFlowExtractor::extract(['transaction_id' => 2, 'category' => 'buy_stock']), 'buy is not a cash flow');
assert_eq(null, CashFlowExtractor::extract(['category' => 'deposit']), 'no id -> null');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/php/run_all.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Create `lib/Analytics/CashFlowExtractor.php`:

```php
<?php
/**
 * Extracts an external cash-flow record (deposit/withdrawal) from a raw GBM
 * transaction, for TWR / net-contribution tracking (Phase 2). Pure, PHP 7.4.
 *
 * NOTE: GBM books internal transfers as paired deposit+withdrawal of the same
 * amount ("...POR TRASPASO"); netting those out is a Phase 2 concern. This
 * extractor captures the raw directional flow only.
 */

namespace OCA\Gbm\Analytics;

class CashFlowExtractor {
    const DIRECTION_BY_CATEGORY = [
        'deposit'    => 'in',
        'withdrawal' => 'out',
    ];

    /**
     * @param array $tx one transaction dict from transactions.json
     * @return array|null ['external_id','direction','amount','flowed_at','description'] or null
     */
    public static function extract(array $tx) {
        $cat = isset($tx['category']) ? strtolower(trim((string) $tx['category'])) : '';
        if (!isset(self::DIRECTION_BY_CATEGORY[$cat])) {
            return null;
        }
        $ext = isset($tx['transaction_id']) ? (string) $tx['transaction_id'] : '';
        if ($ext === '') {
            return null;
        }
        return [
            'external_id' => $ext,
            'direction'   => self::DIRECTION_BY_CATEGORY[$cat],
            'amount'      => isset($tx['amount']) ? (string) $tx['amount'] : '0',
            'flowed_at'   => isset($tx['process_date']) ? (string) $tx['process_date'] : '',
            'description' => isset($tx['description']) ? (string) $tx['description'] : '',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/php/run_all.php`
Expected: PASS (0 failed).

- [ ] **Step 5: Commit**

```bash
git add lib/Analytics/CashFlowExtractor.php tests/php/test_cash_flow_extractor.php
git commit -m "feat(fiscal): pure cash-flow extractor (deposit/withdrawal)"
```

---

### Task 6: Wire classification + cash-flow capture into IngestService

**Files:**
- Modify: `lib/Db/Transaction.php` (add `fiscalClass`, `fiscalYear`)
- Modify: `lib/Service/IngestService.php` (constructor + transactions loop)

**Interfaces:**
- Consumes: `FiscalClassifier` (Task 2), `CashFlowExtractor` (Task 5), `CashFlowMapper` (Task 4), `Transaction` entity.
- Produces: on ingest, each `gbm_transactions` row carries `fiscal_class`/`fiscal_year`; each deposit/withdrawal upserts a `gbm_cash_flows` row.

- [ ] **Step 1: Add the two properties to the Transaction entity**

In `lib/Db/Transaction.php`, add these `@method` lines to the class docblock (next to the existing ones):

```php
 * @method string getFiscalClass()
 * @method void setFiscalClass(string $fiscalClass)
 * @method int getFiscalYear()
 * @method void setFiscalYear(int $fiscalYear)
```

Add these protected properties (next to the existing ones, e.g. after `$bookedAt`):

```php
	protected $fiscalClass;
	protected $fiscalYear;
```

Ensure the constructor registers the integer type. If `Transaction` already has a `__construct`, add the line inside it; otherwise add the constructor:

```php
	public function __construct() {
		$this->addType('securityId', 'integer');
		$this->addType('fiscalYear', 'integer');
	}
```

(Keep any existing `addType` calls; just add the `fiscalYear` one.)

- [ ] **Step 2: Inject CashFlowMapper into IngestService**

In `lib/Service/IngestService.php`, add `use OCA\Gbm\Db\CashFlowMapper;`, `use OCA\Gbm\Analytics\FiscalClassifier;`, and `use OCA\Gbm\Analytics\CashFlowExtractor;` at the top with the other `use` lines. Add a `private $cashFlows;` property. Extend the constructor signature and body:

```php
	public function __construct(
		IConfig $config,
		SecurityMapper $securities,
		AccountMapper $accounts,
		HoldingMapper $holdings,
		OrderMapper $orders,
		TransactionMapper $transactions,
		DividendMapper $dividends,
		PortfolioSnapshotMapper $snapshots,
		CashFlowMapper $cashFlows
	) {
		$this->config = $config;
		$this->securities = $securities;
		$this->accounts = $accounts;
		$this->holdings = $holdings;
		$this->orders = $orders;
		$this->transactions = $transactions;
		$this->dividends = $dividends;
		$this->snapshots = $snapshots;
		$this->cashFlows = $cashFlows;
	}
```

- [ ] **Step 3: Classify + extract inside the transactions loop**

In the transactions ingest loop, replace the block from `$entity->setBookedAt(...)` through `$this->save($this->transactions, $entity);` with:

```php
	$entity->setBookedAt((string) ($t['process_date'] ?? ''));
	$entity->setFiscalClass(FiscalClassifier::classify($t));
	$year = FiscalClassifier::fiscalYear($t['process_date'] ?? '');
	if ($year !== null) {
		$entity->setFiscalYear($year);
	}
	$this->save($this->transactions, $entity);
	$counts['transactions']++;

	$flow = CashFlowExtractor::extract($t);
	if ($flow !== null) {
		$cf = $this->cashFlows->findByExternalId($uid, $flow['external_id']);
		if ($cf === null) {
			$cf = new \OCA\Gbm\Db\CashFlow();
			$cf->setUserId($uid);
			$cf->setExternalId($flow['external_id']);
		}
		$cf->setDirection($flow['direction']);
		$cf->setAmount($flow['amount']);
		$cf->setFlowedAt($flow['flowed_at']);
		$cf->setDescription($flow['description']);
		$this->save($this->cashFlows, $cf);
	}
```

(Remove the now-duplicated `$this->save(...)` / `$counts['transactions']++;` lines that previously closed the loop body so they are not executed twice.)

- [ ] **Step 4: Lint the modified files**

Run: `php -l lib/Db/Transaction.php && php -l lib/Service/IngestService.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Commit**

```bash
git add lib/Db/Transaction.php lib/Service/IngestService.php
git commit -m "feat(ingest): classify transactions + capture external cash flows"
```

---

### Task 7: Deploy Phase 0 + verify on the server (7.4.3)

**Files:** none (deploy + verification gate).

- [ ] **Step 1: Run the full local gate**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green (PHP core suite included via `test_php_core`).

- [ ] **Step 2: Push the branch to GitHub**

```bash
git push -u origin analytics-fiscal
```

- [ ] **Step 3: Deploy to the server (runs the additive migration)**

Run: `./scripts/deploy.sh --bump patch`
Expected: pre-deploy checks green; app synced; `occ upgrade` applies the additive schema; `app:enable gbm` succeeds; server version prints `0.16.2`.

- [ ] **Step 4: Verify the migration + run PHP tests on 7.4.3**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com "
  sudo -u www-data php /var/www/owncloud/apps/gbm/tests/php/run_all.php
  sudo mysql -N -e \"SHOW COLUMNS FROM oc_gbm_transactions LIKE 'fiscal_%'; SHOW TABLES LIKE 'oc_gbm_cash_flows';\" \$(sudo -u www-data php -r 'require\"/var/www/owncloud/config/config.php\";echo \$CONFIG[\"dbname\"];')
"
```
Expected: `PHP core tests: N passed, 0 failed`; two `fiscal_*` columns listed; `oc_gbm_cash_flows` present.

- [ ] **Step 5: Backfill classification by re-ingesting, then commit the version bump**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com "
  sudo -u www-data php /var/www/owncloud/occ gbm:ingest carlos
  sudo -u www-data php /var/www/owncloud/occ gbm:ingest feli
"
git add appinfo/info.xml
git commit -m "chore: bump to 0.16.2 (Phase 0 — data foundation deployed)"
git push
```
Expected: ingest runs without error; existing transactions now carry `fiscal_class`/`fiscal_year`; cash flows populated. (Spot-check: re-run the Step-4 SQL as a `SELECT fiscal_class, COUNT(*) ... GROUP BY fiscal_class` if desired.)

---

# Phase 1 — Annual Fiscal Report (income side)

### Task 8: FiscalReport (pure)

**Files:**
- Create: `lib/Analytics/FiscalReport.php`
- Test: `tests/php/test_fiscal_report.php`

**Interfaces:**
- Consumes: rows `['fiscal_class'=>string,'fiscal_year'=>int,'amount'=>float]`.
- Produces: `FiscalReport::build(array $rows): array` → list (newest year first) of `['year'=>int,'dividends'=>float,'interest'=>float,'withholding'=>float,'net'=>float]`.

- [ ] **Step 1: Write the failing test**

Create `tests/php/test_fiscal_report.php`:

```php
<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/FiscalReport.php';

use OCA\Gbm\Analytics\FiscalReport;

$rows = [
    ['fiscal_class' => 'dividend',    'fiscal_year' => 2025, 'amount' => 100.0],
    ['fiscal_class' => 'dividend',    'fiscal_year' => 2025, 'amount' => 50.0],
    ['fiscal_class' => 'interest',    'fiscal_year' => 2025, 'amount' => 20.0],
    ['fiscal_class' => 'withholding', 'fiscal_year' => 2025, 'amount' => 10.0],
    ['fiscal_class' => 'dividend',    'fiscal_year' => 2026, 'amount' => 200.0],
    ['fiscal_class' => 'none',        'fiscal_year' => 2026, 'amount' => 9999.0],
    ['fiscal_class' => 'dividend',    'fiscal_year' => 0,    'amount' => 5.0],
];
$out = FiscalReport::build($rows);
assert_eq(2, count($out), 'two years (year 0 dropped)');
assert_eq(2026, $out[0]['year'], 'newest year first');
assert_close(200.0, $out[0]['dividends'], '2026 dividends');
assert_close(200.0, $out[0]['net'], '2026 net (none ignored)');
assert_close(150.0, $out[1]['dividends'], '2025 dividends summed');
assert_close(20.0,  $out[1]['interest'], '2025 interest');
assert_close(10.0,  $out[1]['withholding'], '2025 withholding');
assert_close(160.0, $out[1]['net'], '2025 net = 150+20-10');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/php/run_all.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Create `lib/Analytics/FiscalReport.php`:

```php
<?php
/**
 * Aggregates classified transactions into a per-calendar-year income summary
 * (dividends, interest, withholdings, net). Pure, PHP 7.4 compatible.
 *
 * Realized capital gains / ISR-on-gains are NOT computed here — Phase 1b,
 * pending the accountant. Figures are for reference; GBM's constancia fiscal
 * is authoritative.
 */

namespace OCA\Gbm\Analytics;

class FiscalReport {
    /**
     * @param array $rows list of ['fiscal_class'=>string,'fiscal_year'=>int,'amount'=>float]
     * @return array list (newest year first) of
     *   ['year'=>int,'dividends'=>float,'interest'=>float,'withholding'=>float,'net'=>float]
     */
    public static function build(array $rows) {
        $byYear = [];
        foreach ($rows as $r) {
            $year = isset($r['fiscal_year']) ? (int) $r['fiscal_year'] : 0;
            if ($year === 0) {
                continue;
            }
            if (!isset($byYear[$year])) {
                $byYear[$year] = [
                    'year' => $year, 'dividends' => 0.0,
                    'interest' => 0.0, 'withholding' => 0.0,
                ];
            }
            $amt = isset($r['amount']) ? (float) $r['amount'] : 0.0;
            $class = isset($r['fiscal_class']) ? (string) $r['fiscal_class'] : 'none';
            if ($class === 'dividend') {
                $byYear[$year]['dividends'] += $amt;
            } elseif ($class === 'interest') {
                $byYear[$year]['interest'] += $amt;
            } elseif ($class === 'withholding') {
                $byYear[$year]['withholding'] += abs($amt);
            }
        }
        $out = [];
        foreach ($byYear as $row) {
            $row['net'] = $row['dividends'] + $row['interest'] - $row['withholding'];
            $out[] = $row;
        }
        usort($out, function ($a, $b) {
            return $b['year'] - $a['year'];
        });
        return $out;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/php/run_all.php`
Expected: PASS (0 failed).

- [ ] **Step 5: Commit**

```bash
git add lib/Analytics/FiscalReport.php tests/php/test_fiscal_report.php
git commit -m "feat(fiscal): pure per-year income report aggregator"
```

---

### Task 9: FiscalService + `occ gbm:fiscal` command

**Files:**
- Create: `lib/Service/FiscalService.php`
- Create: `lib/Command/Fiscal.php`
- Modify: `appinfo/register_command.php`

**Interfaces:**
- Consumes: `TransactionMapper::findByUser` (existing), `Transaction` getters (`getFiscalClass`, `getFiscalYear`, `getAmount`), `FiscalReport::build`.
- Produces: `FiscalService::perUser(string $uid): array` returning `['years'=>array]` (the FiscalReport list); `occ gbm:fiscal <user>`.

- [ ] **Step 1: Create FiscalService**

Create `lib/Service/FiscalService.php`:

```php
<?php
/**
 * Loads classified transactions from the DB and delegates to the pure
 * FiscalReport core. Mirrors AnalysisService's shape. Income side only.
 */

namespace OCA\Gbm\Service;

use OCA\Gbm\Analytics\FiscalReport;
use OCA\Gbm\Db\TransactionMapper;

class FiscalService {
	/** @var TransactionMapper */
	private $transactions;

	public function __construct(TransactionMapper $transactions) {
		$this->transactions = $transactions;
	}

	/**
	 * @return array{years:array}
	 */
	public function perUser(string $uid): array {
		$rows = [];
		foreach ($this->transactions->findByUser($uid) as $t) {
			$class = (string) $t->getFiscalClass();
			if ($class === '' || $class === 'none') {
				continue;
			}
			$rows[] = [
				'fiscal_class' => $class,
				'fiscal_year'  => (int) $t->getFiscalYear(),
				'amount'       => $this->f($t->getAmount()),
			];
		}
		return ['years' => FiscalReport::build($rows)];
	}

	/** Parse an exact-decimal text amount to float at the service boundary. */
	private function f($v): float {
		return (float) (is_string($v) ? str_replace(',', '', $v) : $v);
	}
}
```

> If `TransactionMapper` lacks a `findByUser`, add it mirroring `DividendMapper::findByUser` (SELECT ... WHERE user_id = ? ORDER BY booked_at ASC) in this same step and include it in the commit.

- [ ] **Step 2: Create the command**

Create `lib/Command/Fiscal.php`:

```php
<?php
/**
 * occ gbm:fiscal <user>
 *
 * Prints the per-year income fiscal summary (dividends, interest, withholding,
 * net) from the DB. Read-only verification surface; the same FiscalService
 * backs the /fiscal page.
 */

namespace OCA\Gbm\Command;

use OCA\Gbm\Service\FiscalService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Fiscal extends Command {
	/** @var FiscalService */
	private $fiscal;

	public function __construct(FiscalService $fiscal) {
		parent::__construct();
		$this->fiscal = $fiscal;
	}

	protected function configure() {
		$this->setName('gbm:fiscal')
			->setDescription('Per-year income fiscal summary (dividends/interest/withholding) from the DB')
			->addArgument('user', InputArgument::REQUIRED, 'ownCloud user id');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$uid = (string) $input->getArgument('user');
		try {
			$r = $this->fiscal->perUser($uid);
		} catch (\Throwable $e) {
			$output->writeln('<error>' . $uid . ': ' . $e->getMessage() . '</error>');
			return 1;
		}
		$output->writeln(sprintf('  %-6s %14s %14s %14s %14s', 'AÑO', 'DIVIDENDOS', 'INTERESES', 'RETENCIÓN', 'NETO'));
		foreach ($r['years'] as $y) {
			$output->writeln(sprintf('  %-6d %14.2f %14.2f %14.2f %14.2f',
				$y['year'], $y['dividends'], $y['interest'], $y['withholding'], $y['net']));
		}
		return 0;
	}
}
```

- [ ] **Step 3: Register the command**

In `appinfo/register_command.php`, after the `Lots` registration line, add:

```php
$application->add($container->query(\OCA\Gbm\Command\Fiscal::class));
```

- [ ] **Step 4: Lint**

Run: `php -l lib/Service/FiscalService.php && php -l lib/Command/Fiscal.php && php -l appinfo/register_command.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Commit**

```bash
git add lib/Service/FiscalService.php lib/Command/Fiscal.php appinfo/register_command.php
git commit -m "feat(fiscal): FiscalService + occ gbm:fiscal command"
```

---

### Task 10: API endpoints (`/api/fiscal` + `/export/fiscal-{year}.csv`)

**Files:**
- Modify: `lib/Controller/ApiController.php` (inject FiscalService; add `fiscalData()` + `exportFiscalCsv()`)
- Modify: `appinfo/routes.php`

**Interfaces:**
- Consumes: `FiscalService::perUser`, existing `GbmService::currentUserId()`, existing private `csvRow()`.
- Produces: `GET /api/fiscal` (JSON `{years:[...]}`), `GET /export/fiscal-{year}.csv`. Route names `gbm.api.fiscalData`, `gbm.api.exportFiscalCsv`.

- [ ] **Step 1: Inject FiscalService into ApiController**

In `lib/Controller/ApiController.php`, add `use OCA\Gbm\Service\FiscalService;` (with the other `use` lines), a `private $fiscal;` property, and extend the constructor:

```php
	public function __construct(string $appName, IRequest $request, GbmService $gbm, IngestService $ingest, AnalysisService $analysis, LotsService $lots, FiscalService $fiscal) {
		parent::__construct($appName, $request);
		$this->gbm = $gbm;
		$this->ingest = $ingest;
		$this->analysis = $analysis;
		$this->lots = $lots;
		$this->fiscal = $fiscal;
	}
```

- [ ] **Step 2: Add the JSON endpoint**

Add this method to `ApiController` (next to `analysisData`):

```php
	/**
	 * DB-backed per-year income fiscal summary for the Fiscal page.
	 * Income side only (dividends/interest/withholding); realized gains are
	 * Phase 1b. Read-only, per-session user.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function fiscalData(): JSONResponse {
		try {
			return new JSONResponse($this->fiscal->perUser($this->gbm->currentUserId()));
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
```

- [ ] **Step 3: Add the CSV export**

Add this method to `ApiController`:

```php
	/**
	 * Declarable CSV for one fiscal year (income side). First row is a disclaimer
	 * comment: these figures are estimates; GBM's constancia fiscal is authoritative.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function exportFiscalCsv(string $year): Http\Response {
		$y = (int) $year;
		$data = $this->fiscal->perUser($this->gbm->currentUserId());
		$row = null;
		foreach ($data['years'] as $r) {
			if ((int) $r['year'] === $y) {
				$row = $r;
				break;
			}
		}
		$out = "# Estimacion informativa — la constancia fiscal de GBM es la fuente oficial\n";
		$out .= self::csvRow(['anio', 'concepto', 'monto']) . "\n";
		if ($row !== null) {
			$out .= self::csvRow([$y, 'dividendos', number_format($row['dividends'], 2, '.', '')]) . "\n";
			$out .= self::csvRow([$y, 'intereses', number_format($row['interest'], 2, '.', '')]) . "\n";
			$out .= self::csvRow([$y, 'retenciones', number_format($row['withholding'], 2, '.', '')]) . "\n";
			$out .= self::csvRow([$y, 'ingreso_neto', number_format($row['net'], 2, '.', '')]) . "\n";
		}
		$response = new Http\DataDisplayResponse($out, Http::STATUS_OK, ['Content-Type' => 'text/csv; charset=utf-8']);
		$response->addHeader('Content-Disposition', 'attachment; filename="gbm-fiscal-' . $y . '.csv"');
		return $response;
	}
```

- [ ] **Step 4: Add the routes**

In `appinfo/routes.php`, add these entries to the `routes` array (e.g. after `api#analysisData`):

```php
		['name' => 'api#fiscalData',       'url' => '/api/fiscal',          'verb' => 'GET'],
		['name' => 'api#exportFiscalCsv',  'url' => '/export/fiscal-{year}.csv', 'verb' => 'GET'],
```

- [ ] **Step 5: Lint and commit**

Run: `php -l lib/Controller/ApiController.php && php -r 'include "appinfo/routes.php"; echo "routes ok\n";'`
Expected: no syntax errors; `routes ok`.

```bash
git add lib/Controller/ApiController.php appinfo/routes.php
git commit -m "feat(fiscal): /api/fiscal JSON + /export/fiscal-{year}.csv"
```

---

### Task 11: Fiscal page (route + template + JS + nav tab + CSS)

**Files:**
- Modify: `lib/Controller/PageController.php` (route method + scriptMap + route params)
- Modify: `appinfo/routes.php` (page route)
- Create: `templates/fiscal.php`
- Create: `js/fiscal.js`
- Modify: `js/_shared.js` (TABS)
- Modify: `css/dashboard.css` (scoped fiscal styles)

**Interfaces:**
- Consumes: `gbm.api.fiscalData` route, `_shared.js` top-bar injection.
- Produces: `GET /fiscal` page rendering the per-year table; nav tab "Fiscal".

- [ ] **Step 1: Add the page route**

In `appinfo/routes.php`, add (with the other `page#` routes, after `page#analysis`):

```php
		['name' => 'page#fiscal',       'url' => '/fiscal',       'verb' => 'GET'],
```

- [ ] **Step 2: Wire PageController**

In `lib/Controller/PageController.php`: add `'fiscal' => 'fiscal',` to the `$scriptMap` array; add `'fiscal' => $this->urlGenerator->linkToRoute('gbm.page.fiscal'),` and `'fiscal_data' => $this->urlGenerator->linkToRoute('gbm.api.fiscalData'),` and `'export_fiscal' => $this->urlGenerator->linkToRoute('gbm.api.exportFiscalCsv', ['year' => '__YEAR__']),` to the `routes` params array. Add the route method:

```php
	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function fiscal(): TemplateResponse {
		return $this->renderTemplate('fiscal');
	}
```

- [ ] **Step 3: Add the Fiscal tab to `_shared.js`**

In `js/_shared.js`, add to the `TABS` array (after the transactions/analysis entries):

```javascript
	{ tab: 'fiscal', routeAttr: 'routeFiscal', label: '📄 Fiscal' },
```

- [ ] **Step 4: Create the template**

Create `templates/fiscal.php`:

```php
<?php
/** GBM Portfolio — Fiscal (income side) page. */
?>
<div id="gbm-app" class="fiscal-page" data-tab="fiscal"
	data-route-index="<?php p($routes['index']); ?>"
	data-route-orders="<?php p($routes['orders']); ?>"
	data-route-orders-all="<?php p($routes['orders_all']); ?>"
	data-route-dividends="<?php p($routes['dividends']); ?>"
	data-route-transactions="<?php p($routes['transactions']); ?>"
	data-route-glossary="<?php p($routes['glossary']); ?>"
	data-route-settings="<?php p($routes['settings']); ?>"
	data-route-analysis="<?php p($routes['analysis']); ?>"
	data-route-fiscal="<?php p($routes['fiscal']); ?>"
	data-route-data="<?php p($routes['data']); ?>"
	data-route-config="<?php p($routes['config']); ?>"
	data-route-update="<?php p($routes['update']); ?>"
	data-route-fiscal-data="<?php p($routes['fiscal_data']); ?>"
	data-route-export-fiscal="<?php p($routes['export_fiscal']); ?>">

	<div class="fiscal-note">
		Reporte informativo del <b>lado de ingresos</b> (dividendos, intereses,
		retenciones). Las plusvalías realizadas y el ISR sobre ganancias se
		agregarán después. Cifras estimadas — la constancia fiscal de GBM es la
		fuente oficial.
	</div>

	<div id="fiscal-empty" class="fiscal-empty hidden">Sin datos fiscales todavía. Da clic en 🔄 Actualizar.</div>

	<table id="fiscal-table" class="fiscal-table">
		<thead>
			<tr>
				<th>Año</th><th>Dividendos</th><th>Intereses</th>
				<th>Retenciones</th><th>Ingreso neto</th><th></th>
			</tr>
		</thead>
		<tbody id="fiscal-tbody"></tbody>
	</table>
</div>
```

- [ ] **Step 5: Create the page JS**

Create `js/fiscal.js`:

```javascript
/** GBM Portfolio — Fiscal page logic. Reads routes from data-route-* attrs. */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var app = document.getElementById('gbm-app');
		if (!app) return;
		var fiscalDataUrl = app.dataset.routeFiscalData;
		var exportTpl = app.dataset.routeExportFiscal;
		var fmtMoney = window.fmtMoney || function (n) { return Number(n).toFixed(2); };

		fetch(fiscalDataUrl, { headers: { requesttoken: OC.requestToken } })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				var years = (data && data.years) || [];
				var tbody = document.getElementById('fiscal-tbody');
				var empty = document.getElementById('fiscal-empty');
				var table = document.getElementById('fiscal-table');
				if (!years.length) {
					if (empty) empty.classList.remove('hidden');
					if (table) table.classList.add('hidden');
					return;
				}
				tbody.innerHTML = '';
				years.forEach(function (y) {
					var tr = document.createElement('tr');
					var href = exportTpl.replace('__YEAR__', String(y.year));
					tr.innerHTML =
						'<td>' + y.year + '</td>' +
						'<td>' + fmtMoney(y.dividends) + '</td>' +
						'<td>' + fmtMoney(y.interest) + '</td>' +
						'<td>' + fmtMoney(y.withholding) + '</td>' +
						'<td>' + fmtMoney(y.net) + '</td>' +
						'<td><a href="' + href + '">CSV</a></td>';
					tbody.appendChild(tr);
				});
			})
			.catch(function () {
				var empty = document.getElementById('fiscal-empty');
				if (empty) { empty.textContent = 'Error al cargar datos fiscales.'; empty.classList.remove('hidden'); }
			});
	});
})();
```

- [ ] **Step 6: Add scoped CSS**

Append to `css/dashboard.css`:

```css
#gbm-app .fiscal-note { background: #1e2733; border-left: 3px solid #4a90d9; padding: 10px 14px; margin: 12px 0; border-radius: 6px; font-size: 13px; color: var(--muted, #9aa7b4); }
#gbm-app .fiscal-empty { padding: 24px; text-align: center; color: var(--muted, #9aa7b4); }
#gbm-app .fiscal-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
#gbm-app .fiscal-table th, #gbm-app .fiscal-table td { padding: 8px 12px; text-align: right; border-bottom: 1px solid #2a3441; }
#gbm-app .fiscal-table th:first-child, #gbm-app .fiscal-table td:first-child { text-align: left; }
#gbm-app .hidden { display: none; }
```

- [ ] **Step 7: Run wiring checks and commit**

Run: `python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: both PASS (the new `fiscal-tbody`/`fiscal-table`/`fiscal-empty` IDs referenced in `js/fiscal.js` exist in `templates/fiscal.php`).

```bash
git add lib/Controller/PageController.php appinfo/routes.php templates/fiscal.php js/fiscal.js js/_shared.js css/dashboard.css
git commit -m "feat(fiscal): /fiscal page, JS, nav tab, styles"
```

---

### Task 12: Deploy Phase 1 + verify on the server

**Files:** none (deploy + verification gate).

- [ ] **Step 1: Full local gate**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green.

- [ ] **Step 2: Deploy (new feature → minor bump) + push**

Run: `./scripts/deploy.sh --bump minor`
Expected: checks green; app synced; `occ upgrade` (no schema change this phase); `app:enable gbm`; server version `0.17.0`.

```bash
git add appinfo/info.xml
git commit -m "chore: bump to 0.17.0 (Phase 1 — fiscal report deployed)"
git push
```

- [ ] **Step 3: Verify on the server (7.4.3)**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com "
  sudo -u www-data php /var/www/owncloud/apps/gbm/tests/php/run_all.php
  sudo -u www-data php /var/www/owncloud/occ gbm:fiscal carlos
"
```
Expected: `PHP core tests: N passed, 0 failed`; a per-year table (2026 row with the dividends/interest/withholding carlos actually has).

- [ ] **Step 4: Confirm the page loads**

Open `https://cloud.damken.com/index.php/apps/gbm/fiscal` (hard-refresh), confirm the "Fiscal" tab appears and the table renders. Report the on-screen result.

- [ ] **Step 5: Merge to main (keep GitHub authoritative)**

```bash
git checkout main && git merge --ff-only analytics-fiscal && git push origin main && git checkout analytics-fiscal
```
Expected: fast-forward; `main` on GitHub now includes Phase 0+1.

---

## Self-Review

- **Spec coverage:** Phase 0 (additive schema + classifier + cash flows + ingest wiring) → Tasks 3–7. Phase 1 income report (pure aggregator + service + command + API + page + export) → Tasks 8–12. Test harness → Task 1. Realized gains/ISR explicitly deferred to Phase 1b (spec updated). No spec requirement left without a task.
- **PHP 7.4 compliance:** no `match`/enum/promotion/union-types/nullsafe used; array constants, typed properties, arrow functions, `??` only.
- **Type consistency:** `FiscalClassifier::classify` returns the same class strings consumed by `FiscalReport::build` and stored by `IngestService`; `perUser` returns `['years'=>...]` consumed identically by the command, API, and (via `fiscal_data`) `js/fiscal.js`; CSV export reads the same `year/dividends/interest/withholding/net` keys `FiscalReport` produces.
- **Additive-only schema:** Task 3 only adds fields/table; no drops.
- **GitHub + server:** each task commits; Task 7 and Task 12 push + deploy + verify on the real 7.4.3, and Task 12 merges to main.
