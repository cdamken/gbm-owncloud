<?php
/**
 * Per-user bridge to the gbm-mx-api Python library.
 *
 * Every public method here operates on a single ownCloud user. The userId
 * is bound at construction time (see Application::__construct), which makes
 * leaking another user's data structurally impossible.
 *
 * Storage layout (datadirectory is the ownCloud root data dir):
 *
 *   {datadirectory}/{uid}/gbm/
 *     ├── session.json     ← persisted by gbm-mx-api (overridden path)
 *     ├── accounts.json    ← written by the fetch wrapper
 *     ├── positions.json
 *     ├── orders.json
 *     ├── last_update.date
 *     └── fetch.log
 *
 * Credentials live in IConfig (user prefs), password encrypted with ICrypto.
 *
 * Site admins control the Python interpreter via system config:
 *
 *     occ config:system:set gbm.python_bin --value=/path/to/venv/bin/python
 *     occ config:system:set gbm.orders_days --value=90
 *
 * The venv must have gbm-mx-api installed.
 */

namespace OCA\Gbm\Service;

use OCP\IConfig;
use OCP\IUserSession;
use OCP\Security\ICrypto;

class GbmService {

	const APPID = 'gbm';

	const EXIT_OK            = 0;
	const EXIT_MFA_REQUIRED  = 10;
	const EXIT_MFA_INVALID   = 11;
	const EXIT_AUTH_FAILED   = 12;
	const EXIT_API_ERROR     = 20;
	const EXIT_CONFIG_ERROR  = 30;

	private $userSession;
	private $config;
	private $crypto;
	private $dataDirRoot;
	private $userIdCache = null;

	/**
	 * Constructor only takes interfaces the ownCloud DI container can
	 * auto-wire — string parameters confuse it (it tries to resolve them as
	 * service ids). userId is computed lazily from IUserSession.
	 */
	public function __construct(IUserSession $userSession, IConfig $config, ICrypto $crypto) {
		$this->userSession = $userSession;
		$this->config = $config;
		$this->crypto = $crypto;
		$this->dataDirRoot = rtrim(
			(string) $config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data'),
			'/'
		);
	}

	/**
	 * The userId of the currently-logged-in ownCloud user.
	 * Resolved lazily so the service can be constructed even when no user
	 * is in session (e.g. background jobs). All public methods that touch
	 * per-user state go through this.
	 */
	private function userId(): string {
		if ($this->userIdCache === null) {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new \RuntimeException('GBM app: no user in session');
			}
			$this->userIdCache = $user->getUID();
		}
		return $this->userIdCache;
	}

	// ------------------------------------------------------------------
	// Paths (per-user, isolated)
	// ------------------------------------------------------------------
	public function userGbmDir(): string {
		$path = $this->dataDirRoot . '/' . $this->userId() . '/gbm';
		if (!is_dir($path)) {
			// 0700 — only the web-server user should read these files.
			@mkdir($path, 0700, true);
		}
		return $path;
	}

	public function sessionPath(): string {
		return $this->userGbmDir() . '/session.json';
	}

	public function dataPath(string $name): string {
		// Whitelist to avoid path traversal via the api#data route.
		$allowed = [
			'accounts.json', 'positions.json',
			'orders.json', 'orders_all.json',
			'dividends.json', 'transactions.json',
			'investments_groups.json',
			'last_update.date',
		];
		if (!in_array($name, $allowed, true)) {
			throw new \InvalidArgumentException("unknown data file: $name");
		}
		return $this->userGbmDir() . '/' . $name;
	}

	// ------------------------------------------------------------------
	// Credentials (per-user, password encrypted)
	// ------------------------------------------------------------------
	public function getEmail(): string {
		return (string) $this->config->getUserValue($this->userId(), self::APPID, 'email', '');
	}

	public function isConfigured(): bool {
		$email = $this->getEmail();
		$pw = (string) $this->config->getUserValue($this->userId(), self::APPID, 'password_enc', '');
		return $email !== '' && strpos($email, '@') !== false && $pw !== '';
	}

	public function setCredentials(string $email, string $password): void {
		$prev = $this->getEmail();
		$this->config->setUserValue($this->userId(), self::APPID, 'email', $email);
		$this->config->setUserValue(
			$this->userId(), self::APPID, 'password_enc',
			$this->crypto->encrypt($password)
		);
		// Account change → wipe stale session + cached data so the next
		// fetch authenticates as the new user.
		if ($prev !== '' && $prev !== $email) {
			$this->wipeUserData();
		}
	}

	// ------------------------------------------------------------------
	// Per-user days config (orders / dividends / transactions)
	// ------------------------------------------------------------------
	private function getDays(string $key, int $default): int {
		$v = (int) $this->config->getUserValue($this->userId(), self::APPID, $key, (string) $default);
		return ($v >= 1 && $v <= 3650) ? $v : $default;
	}
	public function getOrdersDays(): int       { return $this->getDays('orders_days', 90); }
	public function getDividendsDays(): int    { return $this->getDays('dividends_days', 365); }
	public function getTransactionsDays(): int { return $this->getDays('transactions_days', 365); }
	public function setDays(int $orders, int $dividends, int $transactions): void {
		$uid = $this->userId();
		$this->config->setUserValue($uid, self::APPID, 'orders_days',       (string) $orders);
		$this->config->setUserValue($uid, self::APPID, 'dividends_days',    (string) $dividends);
		$this->config->setUserValue($uid, self::APPID, 'transactions_days', (string) $transactions);
	}

	// ------------------------------------------------------------------
	// Reset: revoke Cognito session + wipe user data
	// ------------------------------------------------------------------
	/**
	 * Best-effort Cognito GlobalSignOut + wipe of cached data files.
	 *
	 * Returns ['signed_out_globally' => bool, 'signout_detail' => string].
	 * The local wipe always runs even if Cognito is unreachable.
	 */
	public function resetSession(): array {
		$signedOut = false;
		$detail = '';
		// Try Cognito GlobalSignOut via the bridge script's --revoke flag.
		// We invoke fetch_wrapper.py with --revoke so it imports gbm-mx-api
		// 0.3.1's global_signout() in the venv that already has the lib.
		$python = $this->config->getSystemValue('gbm.python_bin', 'python3');
		$script = realpath(__DIR__ . '/../../python/fetch_wrapper.py');
		if ($script !== false && is_file($script)) {
			$res = $this->runProcess(
				[$python, $script, '--revoke', '--session-path', $this->sessionPath()],
				['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'LANG' => 'C.UTF-8'],
				30
			);
			if ($res['exitCode'] === 0) {
				$signedOut = true;
			} else {
				$detail = trim($res['stderr'] !== '' ? $res['stderr'] : ('exit ' . $res['exitCode']));
				if (strlen($detail) > 200) $detail = substr($detail, 0, 200);
			}
		}
		$this->wipeUserData();
		return ['signed_out_globally' => $signedOut, 'signout_detail' => $detail];
	}

	private function wipeUserData(): void {
		$dir = $this->userGbmDir();
		foreach (['accounts.json','positions.json','orders.json','orders_all.json',
				  'dividends.json','transactions.json','investments_groups.json',
				  'last_update.date','session.json'] as $name) {
			$p = $dir . '/' . $name;
			if (is_file($p)) { @unlink($p); }
		}
	}

	private function getDecryptedPassword(): string {
		$enc = (string) $this->config->getUserValue($this->userId(), self::APPID, 'password_enc', '');
		if ($enc === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($enc);
		} catch (\Exception $e) {
			return '';
		}
	}

	// ------------------------------------------------------------------
	// Update: invoke the Python wrapper
	// ------------------------------------------------------------------
	/**
	 * Runs the bridge script and returns ['exitCode' => int, 'stdout' => str, 'stderr' => str].
	 *
	 * Maps cleanly to HTTP responses in ApiController. $totpCode is optional;
	 * when null, the wrapper uses the saved session if still valid and exits 10
	 * (mfa_required) otherwise — that's the cue for the browser to open its
	 * TOTP modal.
	 */
	public function runFetch(?string $totpCode): array {
		if (!$this->isConfigured()) {
			return ['exitCode' => self::EXIT_CONFIG_ERROR, 'stdout' => '', 'stderr' => 'credentials not configured'];
		}

		$python = $this->config->getSystemValue('gbm.python_bin', 'python3');
		$script = realpath(__DIR__ . '/../../python/fetch_wrapper.py');
		if ($script === false || !is_file($script)) {
			return ['exitCode' => self::EXIT_CONFIG_ERROR, 'stdout' => '', 'stderr' => 'fetch_wrapper.py not found'];
		}

		$cmd = [
			$python,
			$script,
			'--session-path', $this->sessionPath(),
			'--data-dir',     $this->userGbmDir(),
		];
		if ($totpCode !== null) {
			$cmd[] = '--totp';
			$cmd[] = $totpCode;
		}

		$env = [
			'GBM_EMAIL'             => $this->getEmail(),
			'GBM_PASSWORD'          => $this->getDecryptedPassword(),
			'GBM_ORDERS_DAYS'       => (string) $this->getOrdersDays(),
			'GBM_DIVIDENDS_DAYS'    => (string) $this->getDividendsDays(),
			'GBM_TRANSACTIONS_DAYS' => (string) $this->getTransactionsDays(),
			// Keep PATH so the venv's python can still find shared system libs.
			'PATH'                  => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
			'HOME'                  => sys_get_temp_dir(),
			'LANG'                  => 'C.UTF-8',
		];

		return $this->runProcess($cmd, $env, 180);
	}

	private function runProcess(array $cmd, array $env, int $timeoutSec): array {
		$descriptorSpec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$proc = proc_open($cmd, $descriptorSpec, $pipes, null, $env);
		if (!is_resource($proc)) {
			return ['exitCode' => self::EXIT_CONFIG_ERROR, 'stdout' => '', 'stderr' => 'proc_open failed'];
		}
		fclose($pipes[0]);

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$exitCode = -1;
		$deadline = microtime(true) + $timeoutSec;
		while (true) {
			$status = proc_get_status($proc);
			$stdout .= stream_get_contents($pipes[1]);
			$stderr .= stream_get_contents($pipes[2]);
			if (!$status['running']) {
				// PHP gotcha: proc_get_status() captures the exit status the
				// first time it sees the process exited. proc_close() called
				// afterwards returns -1 because the status was already reaped.
				// So we read exitcode from the LAST non-running status here.
				$exitCode = (int) $status['exitcode'];
				break;
			}
			if (microtime(true) > $deadline) {
				proc_terminate($proc, 9);
				$stderr .= "\n[timeout after {$timeoutSec}s]";
				$exitCode = self::EXIT_CONFIG_ERROR;
				break;
			}
			usleep(100 * 1000);
		}
		// Drain anything still buffered after exit.
		$stdout .= stream_get_contents($pipes[1]);
		$stderr .= stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		// proc_close will return -1 here (status already reaped above) — we
		// don't use its return value, just close the handle.
		proc_close($proc);

		// fetch.log is handy for debugging from the server side without
		// having to re-trigger an Update.
		@file_put_contents(
			$this->userGbmDir() . '/fetch.log',
			'[' . date('c') . "] exit=$exitCode\n--- stdout ---\n$stdout\n--- stderr ---\n$stderr\n",
			LOCK_EX
		);

		return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
	}
}
