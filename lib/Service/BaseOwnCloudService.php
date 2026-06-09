<?php
/**
 * Base class shared by every per-user Python-bridge service in our ownCloud
 * apps (GbmService here, TrService in Trade-Republic-owncloud).
 *
 * Bundles the four things every per-user wrapper needs:
 *
 *   1. DI-friendly constructor (IUserSession + IConfig + ICrypto + the
 *      ownCloud datadirectory root).
 *   2. Lazy `userId()` resolution — never accepts a userId from input
 *      (security boundary against cross-user data access).
 *   3. `userDir()` per-user data directory under {datadirectory}/<uid>/<app>/
 *      (subclass provides the <app> via `appDirName()`).
 *   4. `runProcess()` — proc_open-based subprocess wrapper with stdout/stderr
 *      capture, timeout enforcement, and fetch.log persisted into userDir().
 *
 * Subclasses ADD app-specific bits (credentials, dataPath whitelist, runFetch
 * arguments). The split exists because GbmService and TrService had ~100
 * lines of byte-identical code that drifted independently — see
 * TR-GBM-Project/TECHNICAL-PATTERNS.md, "Subprocess wrapper" + "Per-user
 * data" patterns.
 *
 * This file is INTENTIONALLY DUPLICATED between gbm-owncloud and
 * Trade-Republic-owncloud (same content, different namespace) — two ownCloud
 * apps can't share a class via composer without an extra package; the
 * vendored-twin keeps both repos self-contained.
 */

namespace OCA\Gbm\Service;

use OCP\IConfig;
use OCP\IUserSession;
use OCP\Security\ICrypto;

abstract class BaseOwnCloudService {

	// Exit codes returned by python/fetch_wrapper.py. Shared by every
	// downstream ownCloud bridge — see ApiController for the HTTP mapping.
	//
	// Code 21 has two semantically-distinct names: TR's API genuinely
	// emits HTTP 429 rate limits → `EXIT_RATE_LIMITED`; GBM and Scalable
	// hit it as a wrapper timeout → `EXIT_TIMEOUT`. Same value, two
	// names so each ApiController can use the locally-meaningful one.
	// PHP forbids self-referential `const`, so we duplicate the literal.
	const EXIT_OK            = 0;
	const EXIT_MFA_REQUIRED  = 10;
	const EXIT_MFA_INVALID   = 11;
	const EXIT_AUTH_FAILED   = 12;
	const EXIT_API_ERROR     = 20;
	const EXIT_TIMEOUT       = 21;
	const EXIT_RATE_LIMITED  = 21;
	const EXIT_CONFIG_ERROR  = 30;

	protected $userSession;
	protected $config;
	protected $crypto;
	protected $dataDirRoot;
	private $userIdCache = null;

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
	 * Subdirectory name under {datadirectory}/<uid>/ where this app's
	 * per-user state lives. Implementations: 'gbm', 'trade_republic'.
	 */
	abstract protected function appDirName(): string;

	/**
	 * The userId of the currently-logged-in ownCloud user.
	 *
	 * Resolved lazily so the service can be constructed even when no user
	 * is in session (e.g. background jobs). NEVER ACCEPTS a userId from
	 * request input — that's the security boundary.
	 */
	protected function userId(): string {
		if ($this->userIdCache === null) {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new \RuntimeException(static::class . ': no user in session');
			}
			$this->userIdCache = $user->getUID();
		}
		return $this->userIdCache;
	}

	/**
	 * Per-user data directory under {datadirectory}/<uid>/<appDirName>/.
	 * Created with 0700 on first call — only the web-server user should
	 * read these files.
	 */
	public function userDir(): string {
		$path = $this->dataDirRoot . '/' . $this->userId() . '/' . $this->appDirName();
		if (!is_dir($path)) {
			@mkdir($path, 0700, true);
		}
		return $path;
	}

	/**
	 * Runs a subprocess and returns ['exitCode' => int, 'stdout' => str, 'stderr' => str].
	 *
	 * Maps cleanly to HTTP responses in ApiController. proc_open is used with
	 * an ARRAY argv (no shell injection). $env is the COMPLETE env — `$_ENV` is
	 * not inherited, so callers must explicitly pass PATH/HOME/LANG plus any
	 * app-specific vars (credentials, etc).
	 *
	 * On timeout the child is SIGKILL'd and EXIT_CONFIG_ERROR is returned.
	 *
	 * Every invocation appends to {userDir}/fetch.log for debugging without
	 * having to re-trigger an Update from the browser.
	 */
	protected function runProcess(array $cmd, array $env, int $timeoutSec): array {
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
			$this->userDir() . '/fetch.log',
			'[' . date('c') . "] exit=$exitCode\n--- stdout ---\n$stdout\n--- stderr ---\n$stderr\n",
			LOCK_EX
		);

		return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
	}
}
