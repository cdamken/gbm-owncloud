<?php
/**
 * Per-user bridge to the gbm-mx-api Python library.
 *
 * Every public method here operates on a single ownCloud user. The userId
 * is resolved lazily from IUserSession (see BaseOwnCloudService::userId()),
 * which makes leaking another user's data structurally impossible.
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
 *
 * Shared DI plumbing (constructor, userId, userDir, runProcess, EXIT_*)
 * lives in BaseOwnCloudService — see that file for the security boundary
 * + subprocess gotchas. This class only carries GBM-specific logic.
 */

namespace OCA\GbmNext\Service;

class GbmService extends BaseOwnCloudService {

	const APPID = 'gbm_next';

	protected function appDirName(): string {
		return 'gbm_next';
	}

	// ------------------------------------------------------------------
	// Library version probe (for the Settings "About" list)
	// ------------------------------------------------------------------
	/**
	 * Best-effort installed gbm-mx-api version, via the same proc_open
	 * path as everything else (NOT shell_exec — keeps the layering
	 * contract's "subprocess only through runProcess()" rule intact).
	 * Returns '' if the venv/lib isn't reachable.
	 */
	public function libVersion(): string {
		$python = $this->config->getSystemValue('gbm.python_bin', 'python3');
		$res = $this->runProcess(
			[$python, '-c', 'import gbm_mx_api; print(gbm_mx_api.__version__)'],
			['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'LANG' => 'C.UTF-8'],
			10
		);
		return $res['exitCode'] === 0 ? trim($res['stdout']) : '';
	}

	// ------------------------------------------------------------------
	// Paths (per-user, isolated)
	// ------------------------------------------------------------------
	public function sessionPath(): string {
		return $this->userDir() . '/session.json';
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
		return $this->userDir() . '/' . $name;
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
	// Defaults: 10 years (the validated maximum, 1..3650). GBM's API
	// doesn't impose a hard ceiling on date range — the library
	// paginates transparently. Users can lower these in Configuración
	// for faster updates. Old defaults (90/365/365) made XIRR look
	// broken on accounts older than one year.
	public function getOrdersDays(): int       { return $this->getDays('orders_days',       3650); }
	public function getDividendsDays(): int    { return $this->getDays('dividends_days',    3650); }
	public function getTransactionsDays(): int { return $this->getDays('transactions_days', 3650); }
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
		$dir = $this->userDir();
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
	public function runFetch(?string $totpCode, bool $full = false): array {
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
			'--data-dir',     $this->userDir(),
		];
		if ($totpCode !== null) {
			$cmd[] = '--totp';
			$cmd[] = $totpCode;
		}
		if ($full) {
			$cmd[] = '--full';
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
}
