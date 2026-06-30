<?php
/**
 * JSON endpoints used by the dashboard JS.
 *
 *   GET  /data/{type}    → per-user JSON file (accounts/positions/orders/last_update)
 *   GET  /api/config     → { configured, email }
 *   POST /api/config     → { email, password }   (stored per-user, password encrypted)
 *   POST /api/update     → { totp_code? }        (runs fetch_wrapper.py)
 */

namespace OCA\Gbm\Controller;

use OCA\Gbm\Service\AnalysisService;
use OCA\Gbm\Service\GbmService;
use OCA\Gbm\Service\IngestService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ApiController extends Controller {

	private $gbm;
	private $ingest;
	private $analysis;

	public function __construct(string $appName, IRequest $request, GbmService $gbm, IngestService $ingest, AnalysisService $analysis) {
		parent::__construct($appName, $request);
		$this->gbm = $gbm;
		$this->ingest = $ingest;
		$this->analysis = $analysis;
	}

	/**
	 * DB-backed analytics for the Análisis page: summary, per-stock, real
	 * portfolio-value history (snapshots). Read-only, per-session user.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function analysisData(): JSONResponse {
		try {
			return new JSONResponse($this->analysis->perUser($this->gbm->currentUserId()));
		} catch (\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function data(string $type): Http\Response {
		$allowed = [
			'accounts'            => ['file' => 'accounts.json',            'ct' => 'application/json'],
			'positions'           => ['file' => 'positions.json',           'ct' => 'application/json'],
			'orders'              => ['file' => 'orders.json',              'ct' => 'application/json'],
			'orders_all'          => ['file' => 'orders_all.json',          'ct' => 'application/json'],
			'dividends'           => ['file' => 'dividends.json',           'ct' => 'application/json'],
			'transactions'        => ['file' => 'transactions.json',        'ct' => 'application/json'],
			'investments_groups'  => ['file' => 'investments_groups.json',  'ct' => 'application/json'],
			'fx'                  => ['file' => 'fx.json',                  'ct' => 'application/json'],
			'last_update'         => ['file' => 'last_update.date',         'ct' => 'text/plain'],
		];
		if (!isset($allowed[$type])) {
			return new JSONResponse(['error' => 'unknown type'], Http::STATUS_NOT_FOUND);
		}
		$path = $this->gbm->dataPath($allowed[$type]['file']);
		if (!is_file($path)) {
			// First-time empty state — the JS treats this as "no data yet".
			return new JSONResponse(['error' => 'not yet generated'], Http::STATUS_NOT_FOUND);
		}
		$body = file_get_contents($path);
		$response = new DataDisplayResponse($body, Http::STATUS_OK, ['Content-Type' => $allowed[$type]['ct']]);
		$response->addHeader('Cache-Control', 'no-store, must-revalidate');
		$response->addHeader('Pragma', 'no-cache');
		return $response;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getConfig(): JSONResponse {
		$configured = $this->gbm->isConfigured();
		return new JSONResponse([
			'configured' => $configured,
			'email'      => $configured ? $this->gbm->getEmail() : null,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function setConfig(string $email = '', string $password = ''): JSONResponse {
		$email = trim($email);
		if ($email === '' || strpos($email, '@') === false) {
			return new JSONResponse(
				['status' => 'bad_request', 'detail' => 'valid email required'],
				Http::STATUS_BAD_REQUEST
			);
		}
		if (strlen($password) < 4) {
			return new JSONResponse(
				['status' => 'bad_request', 'detail' => 'password too short'],
				Http::STATUS_BAD_REQUEST
			);
		}
		$this->gbm->setCredentials($email, $password);
		return new JSONResponse(['status' => 'ok']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function update(?string $totp_code = null, bool $full = false): JSONResponse {
		if ($totp_code !== null) {
			$totp_code = trim((string) $totp_code);
			if (!ctype_digit($totp_code) || strlen($totp_code) !== 6) {
				return new JSONResponse(
					['status' => 'bad_request', 'detail' => 'totp must be 6 digits'],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		// full=true bypasses the incremental merge and pulls the full
		// configured window. Triggered by the "Recargar todo desde cero"
		// checkbox in the TOTP modal.
		$result = $this->gbm->runFetch($totp_code === '' ? null : $totp_code, $full);

		// Same mapping as gbm-dashboard's server.py.
		static $map = [
			GbmService::EXIT_OK            => [Http::STATUS_OK,                      'ok'],
			GbmService::EXIT_MFA_REQUIRED  => [Http::STATUS_UNAUTHORIZED,            'mfa_required'],
			GbmService::EXIT_MFA_INVALID   => [Http::STATUS_UNAUTHORIZED,            'mfa_invalid'],
			GbmService::EXIT_AUTH_FAILED   => [Http::STATUS_UNAUTHORIZED,            'auth_failed'],
			GbmService::EXIT_API_ERROR     => [Http::STATUS_BAD_GATEWAY,             'api_error'],
			GbmService::EXIT_CONFIG_ERROR  => [Http::STATUS_INTERNAL_SERVER_ERROR,   'config_error'],
		];
		$exit = $result['exitCode'];
		[$httpStatus, $jsonStatus] = $map[$exit] ?? [Http::STATUS_INTERNAL_SERVER_ERROR, 'error'];

		$payload = ['status' => $jsonStatus];
		if ($httpStatus === Http::STATUS_OK) {
			// Keep the DB in sync with the freshly-written JSON, so a manual
			// "Actualizar" updates the DB-backed analytics + adds a snapshot —
			// not just the JSON files. A DB hiccup must NOT fail the fetch.
			try {
				$this->ingest->ingestForUser($this->gbm->currentUserId());
			} catch (\Throwable $e) {
				\OC::$server->getLogger()->logException($e, ['app' => 'gbm']);
			}
			$payload['output'] = substr($result['stdout'], -2000);
		} else {
			$stderr = trim((string) $result['stderr']);
			$lastLine = $stderr === '' ? '' : substr(strrchr("\n" . $stderr, "\n"), 1, 200);
			$payload['detail'] = $lastLine;
		}
		return new JSONResponse($payload, $httpStatus);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function settingsGet(): JSONResponse {
		$appInfo = \OC::$server->getAppManager()->getAppInfo('gbm');
		// Installed gbm-mx-api version via GbmService::libVersion() —
		// proc_open, not shell_exec, to honor the layering contract.
		return new JSONResponse([
			'orders_days'        => $this->gbm->getOrdersDays(),
			'dividends_days'     => $this->gbm->getDividendsDays(),
			'transactions_days'  => $this->gbm->getTransactionsDays(),
			'app_version'        => $appInfo['version'] ?? '',
			'gbm_mx_api_version' => $this->gbm->libVersion(),
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function settingsSet(int $orders_days = 0, int $dividends_days = 0, int $transactions_days = 0): JSONResponse {
		foreach ([$orders_days, $dividends_days, $transactions_days] as $v) {
			if ($v < 1 || $v > 3650) {
				return new JSONResponse(
					['status' => 'bad_request', 'detail' => 'each value must be 1..3650'],
					Http::STATUS_BAD_REQUEST
				);
			}
		}
		$this->gbm->setDays($orders_days, $dividends_days, $transactions_days);
		return new JSONResponse(['status' => 'ok']);
	}

	/**
	 * @NoAdminRequired
	 */
	public function reset(): JSONResponse {
		$r = $this->gbm->resetSession();
		return new JSONResponse([
			'status'              => 'ok',
			'signed_out_globally' => $r['signed_out_globally'],
			'signout_detail'      => $r['signout_detail'],
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function exportTransactionsCsv(): Http\Response {
		$path = $this->gbm->dataPath('transactions.json');
		if (!is_file($path)) {
			return new JSONResponse(['error' => 'no transactions data — run Update first'], Http::STATUS_NOT_FOUND);
		}
		$payload = json_decode((string) file_get_contents($path), true);
		$rows = $payload['transactions'] ?? [];

		$headers = [
			'fecha', 'hora', 'tipo', 'categoria', 'descripcion', 'ticker',
			'cuenta', 'monto', 'monto_neto', 'comision', 'iva',
			'isr_retenido_o_tax', 'transaccion_id',
		];
		$lines = [];
		$lines[] = self::csvRow($headers);
		foreach ($rows as $t) {
			$pd = (string) ($t['process_date'] ?? '');
			$date = substr($pd, 0, 10);
			$time = (string) ($t['transaction_time'] ?? (strlen($pd) >= 19 ? substr($pd, 11, 8) : ''));
			$tipo = !empty($t['is_buy']) ? 'Compra' : (!empty($t['is_sell']) ? 'Venta' : (string) ($t['transaction_type'] ?? ''));
			$lines[] = self::csvRow([
				$date, $time, $tipo,
				(string) ($t['category'] ?? ''),
				(string) ($t['description'] ?? ''),
				(string) ($t['security_id'] ?? ''),
				(string) ($t['account_name'] ?? ''),
				(string) ($t['amount'] ?? ''),
				(string) ($t['net_amount'] ?? ''),
				(string) ($t['commission'] ?? ''),
				(string) ($t['iva'] ?? ''),
				(string) ($t['tax'] ?? ''),
				(string) ($t['transaction_id'] ?? ''),
			]);
		}
		$csv = implode("\r\n", $lines) . "\r\n";
		$resp = new DataDisplayResponse($csv, Http::STATUS_OK, [
			'Content-Type' => 'text/csv; charset=utf-8',
		]);
		$resp->addHeader('Content-Disposition', 'attachment; filename="gbm_transactions.csv"');
		$resp->addHeader('Cache-Control', 'no-store, must-revalidate');
		return $resp;
	}

	private static function csvRow(array $cols): string {
		$esc = [];
		foreach ($cols as $c) {
			$s = (string) $c;
			if (preg_match('/[",\r\n]/', $s)) {
				$s = '"' . str_replace('"', '""', $s) . '"';
			}
			$esc[] = $s;
		}
		return implode(',', $esc);
	}

	/**
	 * Per-page CSV exports — focused subsets matching each dashboard
	 * page's columns. Verbatim port of gbm-dashboard/app/server.py
	 * `_handle_export_page_csv`. The whitelist below is the security
	 * boundary; {kind} comes from URL path.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function exportPageCsv(string $kind): Http\Response {
		$SPECS = [
			'ordenes' => [
				'file' => 'orders.json',
				'list_key' => 'orders',
				'columns' => ['fecha', 'side', 'ticker', 'descripcion', 'mercado', 'cantidad', 'monto', 'estado'],
				'row' => function ($o) {
					return [
						substr((string) ($o['operation_date'] ?? ''), 0, 10),
						!empty($o['is_buy']) ? 'Compra' : (!empty($o['is_sell']) ? 'Venta' : ''),
						$o['security_id'] ?? '',
						$o['description'] ?? '',
						$o['market_label'] ?? $o['market'] ?? '',
						number_format((float) ($o['quantity'] ?? 0), 4, '.', ''),
						number_format((float) ($o['amount'] ?? 0), 4, '.', ''),
						$o['status'] ?? 'filled',
					];
				},
			],
			'historico' => [
				'file' => 'orders_all.json',
				'list_key' => 'orders',
				'columns' => ['fecha', 'ticker', 'side', 'cantidad', 'monto', 'estado'],
				'row' => function ($o) {
					return [
						substr((string) ($o['operation_date'] ?? ''), 0, 10),
						$o['security_id'] ?? '',
						!empty($o['is_buy']) ? 'Compra' : (!empty($o['is_sell']) ? 'Venta' : ''),
						number_format((float) ($o['quantity'] ?? 0), 4, '.', ''),
						number_format((float) ($o['amount'] ?? 0), 4, '.', ''),
						$o['status'] ?? '',
					];
				},
			],
			'dividendos' => [
				'file' => 'dividends.json',
				'list_key' => 'dividends',
				'columns' => ['fecha', 'ticker', 'descripcion', 'monto_bruto', 'isr_retenido', 'monto_neto'],
				'row' => function ($d) {
					return [
						substr((string) ($d['payment_date'] ?? $d['ex_date'] ?? ''), 0, 10),
						$d['security_id'] ?? '',
						$d['description'] ?? '',
						number_format((float) ($d['gross_amount'] ?? $d['amount'] ?? 0), 4, '.', ''),
						number_format((float) ($d['tax_withheld'] ?? $d['tax'] ?? 0), 4, '.', ''),
						number_format((float) ($d['net_amount'] ?? 0), 4, '.', ''),
					];
				},
			],
			'transacciones' => [
				'file' => 'transactions.json',
				'list_key' => 'transactions',
				'columns' => ['fecha', 'ticker', 'descripcion', 'monto', 'categoria', 'cuenta'],
				'row' => function ($t) {
					return [
						substr((string) ($t['process_date'] ?? ''), 0, 10),
						$t['security_id'] ?? '',
						$t['description'] ?? '',
						number_format((float) ($t['amount'] ?? 0), 4, '.', ''),
						$t['category'] ?? '',
						$t['account_name'] ?? $t['account_legacy_id'] ?? '',
					];
				},
			],
			'posiciones' => [
				'file' => 'positions.json',
				'list_key' => null,  // custom flatten — accounts dict
				'columns' => ['ticker', 'cuenta', 'cantidad', 'precio_promedio', 'ultimo_precio', 'valor_mercado', 'pnl_mxn', 'pnl_pct'],
				'row' => function ($p) {
					return [
						$p['issue_id'] ?? $p['security_id'] ?? '',
						$p['_account_name'] ?? '',
						number_format((float) ($p['quantity'] ?? 0), 4, '.', ''),
						number_format((float) ($p['average_price'] ?? 0), 4, '.', ''),
						number_format((float) ($p['last_price'] ?? 0), 4, '.', ''),
						number_format((float) ($p['market_value'] ?? 0), 4, '.', ''),
						number_format((float) ($p['yield_value'] ?? 0), 4, '.', ''),
						number_format((float) ($p['historical_variation_percentage'] ?? 0), 4, '.', ''),
					];
				},
			],
		];

		if (!isset($SPECS[$kind])) {
			return new JSONResponse(['error' => 'unknown kind'], Http::STATUS_BAD_REQUEST);
		}
		$spec = $SPECS[$kind];

		$out = $this->csvRow($spec['columns']) . "\n";

		$path = $this->gbm->dataPath($spec['file']);
		if (is_file($path)) {
			$data = json_decode((string) @file_get_contents($path), true);
			if (is_array($data)) {
				if ($spec['list_key']) {
					$items = (array) ($data[$spec['list_key']] ?? []);
				} else {
					// positions.json: flatten accounts → positions w/ _account_name
					$items = [];
					$accountsMap = (array) ($data['accounts'] ?? []);
					foreach ($accountsMap as $acc) {
						if (!is_array($acc)) continue;
						$accName = $acc['name'] ?? '';
						foreach ((array) ($acc['positions'] ?? []) as $pos) {
							if (!is_array($pos)) continue;
							$pos['_account_name'] = $accName;
							$items[] = $pos;
						}
					}
					if (!$items && isset($data['positions']) && is_array($data['positions'])) {
						$items = $data['positions'];
					}
				}
				foreach ($items as $item) {
					try {
						$out .= $this->csvRow($spec['row']($item)) . "\n";
					} catch (\Throwable $_) {
						continue;
					}
				}
			}
		}

		$response = new Http\DataDisplayResponse(
			$out,
			Http::STATUS_OK,
			['Content-Type' => 'text/csv; charset=utf-8']
		);
		$response->addHeader('Content-Disposition', 'attachment; filename="gbm-' . $kind . '.csv"');
		return $response;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function benchmark(string $symbol): JSONResponse {
		// Allowlist for the symbol — comes from URL path, restrict tightly.
		if (!preg_match('/^[A-Za-z0-9.^_-]{1,40}$/', $symbol)) {
			return new JSONResponse(['error' => 'invalid symbol'], Http::STATUS_BAD_REQUEST);
		}
		$cacheDir = $this->gbm->dataPath('accounts.json'); // get user dir base
		$cacheDir = dirname($cacheDir) . '/benchmark_cache';
		if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0700, true); }
		$cacheFile = $cacheDir . '/' . $symbol . '.json';

		if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
			$body = file_get_contents($cacheFile);
			$resp = new DataDisplayResponse($body, Http::STATUS_OK, ['Content-Type' => 'application/json']);
			$resp->addHeader('Cache-Control', 'no-store');
			return new JSONResponse(json_decode($body, true));
		}

		// Yahoo Finance chart endpoint. CORS blocks the browser from hitting
		// this directly so we proxy server-side.
		// interval=1d so the benchmark replay line moves day-by-day,
		// not in monthly stair-steps. ~252 closes/year, ~5y = ~1260 points.
		$url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
			. rawurlencode($symbol) . '?interval=1d&range=5y';
		$ctx = stream_context_create(['http' => [
			'timeout' => 12,
			'header'  => "User-Agent: gbm-owncloud benchmark proxy\r\n",
		]]);
		$json = @file_get_contents($url, false, $ctx);
		if ($json === false) {
			return new JSONResponse(['error' => 'yahoo unreachable'], Http::STATUS_BAD_GATEWAY);
		}
		$decoded = json_decode($json, true);
		$result = $decoded['chart']['result'][0] ?? null;
		if ($result === null) {
			return new JSONResponse(['error' => 'unexpected yahoo response'], Http::STATUS_BAD_GATEWAY);
		}
		$timestamps = $result['timestamp'] ?? [];
		$closes = $result['indicators']['quote'][0]['close'] ?? [];
		$history = [];
		$n = min(count($timestamps), count($closes));
		for ($i = 0; $i < $n; $i++) {
			if ($closes[$i] === null) continue;
			// Emit the SAME shape as gbm-dashboard's server.py _handle_benchmark
			// ({date: 'YYYY-MM-DD', close: float}). The shared analysis.js
			// _replayBenchmark reads h.date / h.close — the previous {t, c}
			// epoch shape made benchByDay[undefined]=undefined, so every
			// replay returned {} and the overlay silently never rendered.
			$history[] = [
				'date'  => gmdate('Y-m-d', (int) $timestamps[$i]),
				'close' => round((float) $closes[$i], 4),
			];
		}
		$payload = [
			'symbol'     => $symbol,
			'fetched_at' => time(),
			'history'    => $history,
		];
		$body = json_encode($payload);
		@file_put_contents($cacheFile, $body);
		return new JSONResponse($payload);
	}
}
