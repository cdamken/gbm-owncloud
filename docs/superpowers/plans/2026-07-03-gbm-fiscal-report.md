# GBM F — Reporte fiscal (botón → CSV) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A "Generar reporte fiscal" button (Settings → Datos) that writes an income-side fiscal report (dividends/interest/withholding, summary + detail) as two CSVs into the user's ownCloud Files under `GBM/`.

**Architecture:** Pure compute core (`FiscalClassifier` + `FiscalReport`, ported verbatim, unit-tested) → thin DB-backed `FiscalService` (classifies transactions on the fly, builds summary + detail, no schema change) → framework-coupled `FiscalFileService` (writes to `IRootFolder->getUserFolder`) → `ApiController::generateFiscal()` (builds the CSV strings, POST endpoint) → a settings button. Closes issue #12.

**Tech Stack:** PHP 7.4 (server), ownCloud 10.13 app framework (legacy `Mapper`, auto-wired DI, `OCP\Files\IRootFolder`), vanilla JS (settings.js), plain-PHP test harness via Python `unittest`.

## Global Constraints

- **PHP 7.4.3 target** (server; local 8.5). FORBIDDEN 8.x-only: `match`, `enum`, constructor promotion, union/intersection types, `?->`, named args, `readonly`, `str_contains`/`str_starts_with`/`str_ends_with` (use `strpos`). OK: `??`, arrow fns, typed properties, casts.
- **No schema change** — classification is on the fly from `gbm_transactions.type` + `booked_at`. `appinfo/database.xml` untouched.
- **Single source, no double-count** — dividends/interest/withholding all from `gbm_transactions` via one classifier pass. Do NOT also sum `gbm_dividends`.
- **Income side only** — no realized gains, no ISR-on-gains estimate. Every CSV carries a `#` disclaimer: informational estimate; GBM's *constancia fiscal* is authoritative.
- **CSP strict** — no inline `<script>`/`on*=`; button wired via `addEventListener`.
- **No new page / nav tab / download route / `occ`** — just the button + endpoint + file writer.
- **Per-user isolation** — `uid` from `GbmService::currentUserId()` (session), never from request input; `IRootFolder->getUserFolder($uid)` scopes the write.
- **DI is auto-wired** — new services resolve by constructor type hints; no `Application.php` registration.
- **Money** stored as exact-string, parsed to float at the service edge.
- UI strings Spanish; code/identifiers/comments/commits English.
- **Every task ends with a commit.** The final task pushes `analytics-fiscal` to GitHub AND deploys via `scripts/deploy.sh`, then runs the authoritative PHP test pass on 7.4.3.

## File Structure

| File | Responsibility | Task |
|------|----------------|------|
| `lib/Analytics/FiscalClassifier.php` | Pure: GBM category → dividend/interest/withholding/none; fiscalYear | 1 |
| `lib/Analytics/FiscalReport.php` | Pure: classified rows → per-year income summary | 1 |
| `tests/php/test_fiscal_classifier.php`, `tests/php/test_fiscal_report.php` | Unit tests for the pure core | 1 |
| `lib/Service/FiscalService.php` | DB-backed: classify transactions on the fly → `{summary, detail}` | 2 |
| `lib/Service/FiscalFileService.php` | Write CSVs into `GBM/` via `IRootFolder` | 2 |
| `lib/Controller/ApiController.php` | `generateFiscal()` — build CSV strings + write; DI the two services | 3 |
| `appinfo/routes.php` | `POST /api/fiscal/generate` | 3 |
| `lib/Controller/PageController.php` | pass `generate_fiscal` route to templates | 4 |
| `templates/settings.php` | button + `data-route-generate-fiscal` | 4 |
| `js/settings.js` | `generateFiscal()` handler + wiring + route read | 4 |

---

### Task 1: Pure fiscal core (classifier + report) + tests

**Files:**
- Create: `lib/Analytics/FiscalClassifier.php`
- Create: `lib/Analytics/FiscalReport.php`
- Create: `tests/php/test_fiscal_classifier.php`
- Create: `tests/php/test_fiscal_report.php`

**Interfaces:**
- Produces: `FiscalClassifier::classify(array $tx): string` (`dividend|interest|withholding|none`, keyed on `$tx['category']`); `FiscalClassifier::fiscalYear($processDate): ?int`; `FiscalReport::build(array $rows): array` where each row is `['fiscal_class'=>string,'fiscal_year'=>int,'amount'=>float]`, returning a list (newest year first) of `['year'=>int,'dividends'=>float,'interest'=>float,'withholding'=>float,'net'=>float]`.

- [ ] **Step 1: Write the failing tests**

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
assert_eq('none',        FiscalClassifier::classify(['category' => 'deposit']),         'deposit -> none');
assert_eq('none',        FiscalClassifier::classify([]),                                'missing category -> none');
assert_eq(2026, FiscalClassifier::fiscalYear('2026-04-02T10:44:18-06:00'), 'year from iso datetime');
assert_eq(2025, FiscalClassifier::fiscalYear('2025-12-31'),                'year from date');
assert_eq(null, FiscalClassifier::fiscalYear(''),                         'empty -> null');
```

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

- [ ] **Step 2: Run tests to verify they fail**

Run: `php tests/php/run_all.php`
Expected: FAIL — `Class "OCA\Gbm\Analytics\FiscalClassifier" not found` (fatal).

- [ ] **Step 3: Write `FiscalClassifier`**

Create `lib/Analytics/FiscalClassifier.php`:

```php
<?php
/**
 * Maps a raw GBM transaction to a fiscal class for the annual income report.
 * Pure — no OCP dependency; unit-tested in tests/php/. PHP 7.4 compatible.
 *
 * Realized capital gains are NOT classified here (they need FIFO lots and are
 * deferred pending the accountant). Sells therefore return 'none'.
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
	 * @param array $tx one transaction dict with a 'category' key
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
	 * Calendar year from a GBM date ("YYYY-MM-DD" or "YYYY-MM-DDT...").
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

- [ ] **Step 4: Write `FiscalReport`**

Create `lib/Analytics/FiscalReport.php`:

```php
<?php
/**
 * Aggregates classified transactions into a per-calendar-year income summary
 * (dividends, interest, withholdings, net). Pure, PHP 7.4 compatible.
 *
 * Realized capital gains / ISR-on-gains are NOT computed here — deferred,
 * pending the accountant. Figures are informational; GBM's constancia fiscal
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

- [ ] **Step 5: Run tests to verify they pass**

Run: `php tests/php/run_all.php`
Expected: PASS — `PHP core tests: N passed, 0 failed`.

- [ ] **Step 6: Commit**

```bash
git add lib/Analytics/FiscalClassifier.php lib/Analytics/FiscalReport.php tests/php/test_fiscal_classifier.php tests/php/test_fiscal_report.php
git commit -m "feat(fiscal): pure fiscal classifier + per-year income report (refs #12)"
```

---

### Task 2: FiscalService + FiscalFileService

**Files:**
- Create: `lib/Service/FiscalService.php`
- Create: `lib/Service/FiscalFileService.php`

**Interfaces:**
- Consumes: `FiscalClassifier`, `FiscalReport` (Task 1); `TransactionMapper::findByUser(string): Transaction[]` and `Transaction::getType()/getAmount()/getBookedAt()/getSecurityId()`; `SecurityMapper::findByUser(string): Security[]` and `Security::getId()/getName()`; `OCP\Files\IRootFolder`.
- Produces: `FiscalService::perUser(string $uid): array` → `['summary'=>array, 'detail'=>array]` where `summary` is `FiscalReport::build()` output and `detail` is a list of `['date'=>string,'year'=>int,'class'=>string,'security'=>string,'amount'=>float]` sorted by date ascending. `FiscalFileService::writeFiles(string $uid, array $files): string[]` (`$files` = `filename=>content`; returns written relative paths like `GBM/<name>`).

- [ ] **Step 1: Create `FiscalService`**

Create `lib/Service/FiscalService.php`:

```php
<?php
/**
 * Income-side fiscal report, DB-backed. Loads transactions, classifies them on
 * the fly (FiscalClassifier — NO schema dependency), and returns a per-year
 * summary (FiscalReport) plus an itemized detail list. Per-user scoped.
 *
 * Single source: dividends/interest/withholding all come from gbm_transactions,
 * so withholding is never double-counted against gbm_dividends.
 */

namespace OCA\Gbm\Service;

use OCA\Gbm\Analytics\FiscalClassifier;
use OCA\Gbm\Analytics\FiscalReport;
use OCA\Gbm\Db\SecurityMapper;
use OCA\Gbm\Db\TransactionMapper;

class FiscalService {
	/** @var TransactionMapper */
	private $transactions;
	/** @var SecurityMapper */
	private $securities;

	public function __construct(TransactionMapper $transactions, SecurityMapper $securities) {
		$this->transactions = $transactions;
		$this->securities = $securities;
	}

	/**
	 * @return array{summary:array,detail:array}
	 */
	public function perUser(string $uid): array {
		// security DB id -> issuer name (Transaction::getSecurityId() is that int FK).
		$nameById = [];
		foreach ($this->securities->findByUser($uid) as $s) {
			$nameById[(int) $s->getId()] = (string) $s->getName();
		}

		$rows = [];
		$detail = [];
		foreach ($this->transactions->findByUser($uid) as $t) {
			$class = FiscalClassifier::classify(['category' => (string) $t->getType()]);
			if ($class === 'none') {
				continue;
			}
			$year = FiscalClassifier::fiscalYear($t->getBookedAt());
			if ($year === null) {
				continue;
			}
			$amount = $this->f($t->getAmount());
			$rows[] = [
				'fiscal_class' => $class,
				'fiscal_year'  => $year,
				'amount'       => $amount,
			];
			$detail[] = [
				'date'     => substr((string) $t->getBookedAt(), 0, 10),
				'year'     => $year,
				'class'    => $class,
				'security' => $nameById[(int) $t->getSecurityId()] ?? '',
				'amount'   => $amount,
			];
		}
		return [
			'summary' => FiscalReport::build($rows),
			'detail'  => $detail,
		];
	}

	/** Parse an exact-decimal text amount to float at the service boundary. */
	private function f($v): float {
		return (float) (is_string($v) ? str_replace(',', '', $v) : $v);
	}
}
```

- [ ] **Step 2: Create `FiscalFileService`**

Create `lib/Service/FiscalFileService.php`:

```php
<?php
/**
 * Writes generated report files into the user's ownCloud Files area under a
 * GBM/ folder (created if missing), overwriting in place. Thin wrapper over
 * OCP\Files\IRootFolder — the only framework-coupled unit of the fiscal feature.
 *
 * getUserFolder($uid) already returns the user's Files root, so the target is
 * "GBM/<name>" — do NOT prepend "files/".
 */

namespace OCA\Gbm\Service;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class FiscalFileService {
	/** @var IRootFolder */
	private $rootFolder;

	public function __construct(IRootFolder $rootFolder) {
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @param array<string,string> $files  filename => CSV content
	 * @return string[] relative paths written (e.g. "GBM/reporte-fiscal-resumen.csv")
	 */
	public function writeFiles(string $uid, array $files): array {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		try {
			$folder = $userFolder->get('GBM');
		} catch (NotFoundException $e) {
			$folder = $userFolder->newFolder('GBM');
		}
		$written = [];
		foreach ($files as $name => $content) {
			try {
				$folder->get($name)->putContent($content);
			} catch (NotFoundException $e) {
				$folder->newFile($name)->putContent($content);
			}
			$written[] = 'GBM/' . $name;
		}
		return $written;
	}
}
```

- [ ] **Step 3: Lint both files**

Run: `php -l lib/Service/FiscalService.php && php -l lib/Service/FiscalFileService.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add lib/Service/FiscalService.php lib/Service/FiscalFileService.php
git commit -m "feat(fiscal): FiscalService (classify on the fly) + FiscalFileService (IRootFolder writer) (refs #12)"
```

---

### Task 3: `/api/fiscal/generate` endpoint + route

**Files:**
- Modify: `lib/Controller/ApiController.php` (use lines; constructor; new `generateFiscal()`)
- Modify: `appinfo/routes.php`

**Interfaces:**
- Consumes: `FiscalService::perUser`, `FiscalFileService::writeFiles` (Task 2); existing `GbmService::currentUserId()`, private `self::csvRow()`, `JSONResponse`, `Http`.
- Produces: `POST /api/fiscal/generate` (route name `api#generateFiscal`) → JSON `{status:'ok',folder:'GBM',files:[...]}` or `{status:'error',detail:...}`.

- [ ] **Step 1: Add the two `use` statements**

In `lib/Controller/ApiController.php`, the `use` block (lines 13-16) currently is:

```php
use OCA\Gbm\Service\AnalysisService;
use OCA\Gbm\Service\GbmService;
use OCA\Gbm\Service\IngestService;
use OCA\Gbm\Service\LotsService;
```

Add the two fiscal services (keep alphabetical-ish grouping):

```php
use OCA\Gbm\Service\AnalysisService;
use OCA\Gbm\Service\FiscalFileService;
use OCA\Gbm\Service\FiscalService;
use OCA\Gbm\Service\GbmService;
use OCA\Gbm\Service\IngestService;
use OCA\Gbm\Service\LotsService;
```

- [ ] **Step 2: Add the two constructor params + properties**

In `lib/Controller/ApiController.php`, the constructor (lines 30-36) currently is:

```php
	public function __construct(string $appName, IRequest $request, GbmService $gbm, IngestService $ingest, AnalysisService $analysis, LotsService $lots) {
		parent::__construct($appName, $request);
		$this->gbm = $gbm;
		$this->ingest = $ingest;
		$this->analysis = $analysis;
		$this->lots = $lots;
	}
```

Replace with (auto-wired — no `Application.php` change needed):

```php
	/** @var FiscalService */
	private $fiscal;
	/** @var FiscalFileService */
	private $fiscalFile;

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

> If the class declares its other injected properties (`$gbm`, `$ingest`, …) somewhere above the constructor, add `$fiscal`/`$fiscalFile` there in the same style instead of the inline `@var` block shown here; if it does not declare them (dynamic properties), the inline declaration above is correct. Either way, do not remove existing declarations.

- [ ] **Step 3: Add the `generateFiscal()` method**

In `lib/Controller/ApiController.php`, add this method next to `analysisData()` (e.g. right after it):

```php
	/**
	 * Generate the income-side fiscal report and write it as CSV files into the
	 * user's Files area (GBM/). Overwrites in place. Per-session user; POST,
	 * CSRF-protected (our settings JS sends requesttoken).
	 *
	 * @NoAdminRequired
	 */
	public function generateFiscal(): JSONResponse {
		try {
			$uid = $this->gbm->currentUserId();
			$data = $this->fiscal->perUser($uid);

			$disc = '# Estimacion informativa (solo ingresos) - la constancia fiscal de GBM es la fuente oficial';
			$labels = ['dividend' => 'Dividendo', 'interest' => 'Interes', 'withholding' => 'Retencion ISR'];

			$resumen = $disc . "\n" . self::csvRow(['anio', 'dividendos', 'intereses', 'retenciones', 'ingreso_neto']) . "\n";
			foreach ($data['summary'] as $y) {
				$resumen .= self::csvRow([
					$y['year'],
					number_format($y['dividends'], 2, '.', ''),
					number_format($y['interest'], 2, '.', ''),
					number_format($y['withholding'], 2, '.', ''),
					number_format($y['net'], 2, '.', ''),
				]) . "\n";
			}

			$detalle = $disc . "\n" . self::csvRow(['fecha', 'anio', 'concepto', 'emisora', 'monto']) . "\n";
			foreach ($data['detail'] as $d) {
				$detalle .= self::csvRow([
					$d['date'],
					$d['year'],
					isset($labels[$d['class']]) ? $labels[$d['class']] : $d['class'],
					$d['security'],
					number_format($d['amount'], 2, '.', ''),
				]) . "\n";
			}

			$written = $this->fiscalFile->writeFiles($uid, [
				'reporte-fiscal-resumen.csv' => $resumen,
				'reporte-fiscal-detalle.csv' => $detalle,
			]);
			return new JSONResponse(['status' => 'ok', 'folder' => 'GBM', 'files' => $written]);
		} catch (\Throwable $e) {
			return new JSONResponse(['status' => 'error', 'detail' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
```

- [ ] **Step 4: Add the route**

In `appinfo/routes.php`, add this entry to the `routes` array (e.g. after the `api#update` line, before the closing `]`):

```php
		['name' => 'api#generateFiscal', 'url' => '/api/fiscal/generate', 'verb' => 'POST'],
```

- [ ] **Step 5: Lint and commit**

Run: `php -l lib/Controller/ApiController.php && php -r 'include "appinfo/routes.php"; echo "routes ok\n";'`
Expected: `No syntax errors detected …`; `routes ok`.

```bash
git add lib/Controller/ApiController.php appinfo/routes.php
git commit -m "feat(fiscal): POST /api/fiscal/generate — build CSVs + write to GBM/ (refs #12)"
```

---

### Task 4: "Generar reporte fiscal" button (Settings → Datos)

**Files:**
- Modify: `lib/Controller/PageController.php` (add `generate_fiscal` route param)
- Modify: `templates/settings.php` (button + `data-route-generate-fiscal`)
- Modify: `js/settings.js` (route read + `generateFiscal()` handler + wiring)

**Interfaces:**
- Consumes: `POST /api/fiscal/generate` (route `gbm.api.generateFiscal`, Task 3); the existing `csrfHeaders()`, `flash()`, `$()` helpers and `#data-flash` element in settings.js/settings.php.
- Produces: DOM id `generate-fiscal-btn`; `data-route-generate-fiscal` on `#gbm-app`; JS function `generateFiscal`.

- [ ] **Step 1: Pass the route from PageController**

In `lib/Controller/PageController.php`, the `$params['routes']` array (ends around line 141 with `analysis_data`). Add this entry inside that array:

```php
			'generate_fiscal' => $this->urlGenerator->linkToRoute('gbm.api.generateFiscal'),
```

- [ ] **Step 2: Add the route attr + button to the template**

In `templates/settings.php`, the `#gbm-app` opening tag ends (line 19) with:

```php
	data-route-update="<?php p($routes['update']); ?>">
```

Add the fiscal route attr before the closing `>`:

```php
	data-route-update="<?php p($routes['update']); ?>"
	data-route-generate-fiscal="<?php p($routes['generate_fiscal']); ?>">
```

Then, in the `s-data` section's `settings-actions` block, which currently is:

```php
	<div class="settings-actions">
		<button class="primary" id="save-data-btn" type="button">Guardar rangos</button>
		<a class="settings-actions-link"
		   id="export-csv-link"
		   href="<?php p($routes['export_csv']); ?>"
		   download
		   title="Descarga un CSV en español listo para pasar al contador o importar a Excel.">
			📥 Exportar CSV para SAT
		</a>
	</div>
```

add the fiscal button after the `</a>` (still inside `settings-actions`):

```php
	<div class="settings-actions">
		<button class="primary" id="save-data-btn" type="button">Guardar rangos</button>
		<a class="settings-actions-link"
		   id="export-csv-link"
		   href="<?php p($routes['export_csv']); ?>"
		   download
		   title="Descarga un CSV en español listo para pasar al contador o importar a Excel.">
			📥 Exportar CSV para SAT
		</a>
		<button class="primary" id="generate-fiscal-btn" type="button"
			title="Genera CSVs de dividendos/intereses/retención por año en tu carpeta GBM/. Estimación informativa; la constancia fiscal de GBM es la oficial.">
			📄 Generar reporte fiscal
		</button>
	</div>
```

- [ ] **Step 3: Read the route in settings.js**

In `js/settings.js`, the `routes` object (lines 198-202) currently is:

```javascript
	routes = {
		config:      root.dataset.routeConfig,
		settingsApi: root.dataset.routeSettingsApi,
		reset:       root.dataset.routeReset,
	};
```

Add the fiscal route:

```javascript
	routes = {
		config:         root.dataset.routeConfig,
		settingsApi:    root.dataset.routeSettingsApi,
		reset:          root.dataset.routeReset,
		generateFiscal: root.dataset.routeGenerateFiscal,
	};
```

- [ ] **Step 4: Define the `generateFiscal()` handler**

In `js/settings.js`, add this function next to the other action handlers (e.g. after `saveData()`):

```javascript
async function generateFiscal() {
	const flashEl = $('data-flash');
	const btn = $('generate-fiscal-btn');
	btn.disabled = true; btn.textContent = 'Generando…';
	try {
		const r = await fetch(routes.generateFiscal, {
			method: 'POST',
			headers: csrfHeaders(),
		});
		const p = await r.json();
		if (r.ok && p.status === 'ok') {
			flash(flashEl, true, 'Reporte generado en tu carpeta ' + (p.folder || 'GBM') + '/.');
		} else {
			flash(flashEl, false, (p && p.detail) || 'No se pudo generar el reporte.');
		}
	} catch (e) {
		flash(flashEl, false, 'Error de conexión: ' + e.message);
	} finally {
		btn.disabled = false; btn.textContent = '📄 Generar reporte fiscal';
	}
}
```

- [ ] **Step 5: Wire the button**

In `js/settings.js`, the wiring block (lines 204-207) currently is:

```javascript
	$('save-account-btn').addEventListener('click', saveAccount);
	$('switch-account-btn').addEventListener('click', switchAccount);
	$('save-data-btn').addEventListener('click', saveData);
	$('revoke-session-btn').addEventListener('click', revokeSession);
```

Add the fiscal button wiring:

```javascript
	$('save-account-btn').addEventListener('click', saveAccount);
	$('switch-account-btn').addEventListener('click', switchAccount);
	$('save-data-btn').addEventListener('click', saveData);
	$('revoke-session-btn').addEventListener('click', revokeSession);
	$('generate-fiscal-btn').addEventListener('click', generateFiscal);
```

- [ ] **Step 6: Run the gates**

Run: `python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: both PASS. `verify_dom_ids` finds `generate-fiscal-btn` (referenced in settings.js) defined in `settings.php`; `verify_wiring` finds `generateFiscal` defined and wired.

- [ ] **Step 7: Run the full local gate and commit**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green.

```bash
git add lib/Controller/PageController.php templates/settings.php js/settings.js
git commit -m "feat(fiscal): Generar reporte fiscal button in Settings (refs #12)"
```

---

### Task 5: Deploy + verify on the server (7.4.3)

**Files:** none (deploy + verification gate).

- [ ] **Step 1: Run the full local gate**

Run: `python3 -m unittest discover -s tests -v && python3 scripts/verify_dom_ids.py && python3 scripts/verify_wiring.py`
Expected: all green (PHP core suite includes the new fiscal tests).

- [ ] **Step 2: Push the branch**

```bash
git push origin analytics-fiscal
```

- [ ] **Step 3: Deploy (new feature → minor bump)**

Run: `./scripts/deploy.sh --bump minor`
Expected: pre-deploy checks green; app synced; `occ upgrade` (no schema change); `app:enable gbm`; server version prints `0.20.0`. (Ignore the fake security-advisory banner.)

- [ ] **Step 4: Commit the version bump + push**

```bash
git add appinfo/info.xml
git commit -m "chore: bump to 0.20.0 (F — fiscal report button) (closes #12)"
git push
```

- [ ] **Step 5: Run the PHP core tests on the real 7.4.3**

Run:
```bash
ssh -A -i ~/.ssh/id_ed25519 -p 2222 carlos@cloud.damken.com \
  "sudo -u www-data php /var/www/owncloud/apps/gbm/tests/php/run_all.php"
```
Expected: `PHP core tests: N passed, 0 failed` (includes fiscal classifier + report).

- [ ] **Step 6: Confirm end-to-end on the live app**

Open `https://cloud.damken.com/index.php/apps/gbm/settings` (hard-refresh) → sección "Rangos de datos" → click **📄 Generar reporte fiscal**. Confirm the success flash. Then open the ownCloud **Files** app and confirm `GBM/reporte-fiscal-resumen.csv` and `GBM/reporte-fiscal-detalle.csv` exist, with the disclaimer header, per-year summary totals, and itemized detail rows. Report the on-screen result.

- [ ] **Step 7: Merge to main (closes #12)**

```bash
git checkout main && git merge --ff-only analytics-fiscal && git push origin main && git checkout analytics-fiscal
```
Expected: fast-forward; `main` on GitHub includes F; issue #12 auto-closes from the bump commit message.

---

## Self-Review

- **Spec coverage:** pure classifier+report → Task 1; classify-on-the-fly service (no schema) + summary+detail → Task 2 (`FiscalService`); IRootFolder writer to `GBM/` → Task 2 (`FiscalFileService`); 2 CSVs (resumen+detalle) with disclaimer, single-source no-double-count → Task 3 (`generateFiscal`); button in Settings→Datos, CSRF POST → Task 4; deploy + server verify + files check + close #12 → Task 5. Income-side only / no gains / no schema / no page → honored throughout. ✓
- **Placeholder scan:** every step has full code + exact commands/expected output; no TODO/TBD. ✓
- **Type consistency:** `FiscalService::perUser` returns `{summary, detail}`; `generateFiscal` reads `$data['summary']` (year/dividends/interest/withholding/net keys from `FiscalReport::build`) and `$data['detail']` (date/year/class/security/amount keys). `FiscalFileService::writeFiles($uid, [name=>content])` matches the call in Step 3.3. The `securityId→name` map is keyed by `Security::getId()` (int), matching `Transaction::getSecurityId()` (int) — NOT `getExtId()`. Route name `gbm.api.generateFiscal` matches the `api#generateFiscal` entry (Task 3.4) and the `linkToRoute` (Task 4.1); DOM id `generate-fiscal-btn` matches template↔JS↔wiring; `data-route-generate-fiscal` → `root.dataset.routeGenerateFiscal`. ✓
- **PHP 7.4:** classifier/report use only `strpos`/`preg_match`/`usort`/casts; services use typed properties + `??`; no 8.x-only features. ✓
- **DI:** auto-wired — `FiscalService(TransactionMapper, SecurityMapper)` and `FiscalFileService(IRootFolder)` resolve by type hint; `ApiController` gets both added to its constructor; no `Application.php` change. ✓
- **GitHub + server:** each task commits (refs #12); Task 5 pushes, deploys, verifies on 7.4.3, checks the Files output, merges to main (closes #12). ✓
