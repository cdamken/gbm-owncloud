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

use OCA\Gbm\Service\GbmService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class ApiController extends Controller {

	private $gbm;

	public function __construct(string $appName, IRequest $request, GbmService $gbm) {
		parent::__construct($appName, $request);
		$this->gbm = $gbm;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function data(string $type): Http\Response {
		$allowed = [
			'accounts'     => ['file' => 'accounts.json',     'ct' => 'application/json'],
			'positions'    => ['file' => 'positions.json',    'ct' => 'application/json'],
			'orders'       => ['file' => 'orders.json',       'ct' => 'application/json'],
			'orders_all'   => ['file' => 'orders_all.json',   'ct' => 'application/json'],
			'dividends'    => ['file' => 'dividends.json',    'ct' => 'application/json'],
			'transactions' => ['file' => 'transactions.json', 'ct' => 'application/json'],
			'last_update'  => ['file' => 'last_update.date',  'ct' => 'text/plain'],
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
	public function update(?string $totp_code = null): JSONResponse {
		if ($totp_code !== null) {
			$totp_code = trim((string) $totp_code);
			if (!ctype_digit($totp_code) || strlen($totp_code) !== 6) {
				return new JSONResponse(
					['status' => 'bad_request', 'detail' => 'totp must be 6 digits'],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		$result = $this->gbm->runFetch($totp_code === '' ? null : $totp_code);

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
			$payload['output'] = substr($result['stdout'], -2000);
		} else {
			$stderr = trim((string) $result['stderr']);
			$lastLine = $stderr === '' ? '' : substr(strrchr("\n" . $stderr, "\n"), 1, 200);
			$payload['detail'] = $lastLine;
		}
		return new JSONResponse($payload, $httpStatus);
	}
}
