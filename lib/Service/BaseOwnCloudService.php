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

	// Human-readable names for the exit codes above — used in fetch.log and
	// owncloud.log so a glance tells you WHAT happened, not just a number.
	private static $EXIT_NAMES = [
		0  => 'OK',
		10 => 'MFA_REQUIRED',
		11 => 'MFA_INVALID',
		12 => 'AUTH_FAILED',
		20 => 'API_ERROR',
		21 => 'TIMEOUT',
		30 => 'CONFIG_ERROR',
	];

	private static function exitName(int $code): string {
		return self::$EXIT_NAMES[$code] ?? ('UNKNOWN(' . $code . ')');
	}

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
		// The argv is safe to log (secrets travel via $env, never argv — see
		// runFetch). This gives owncloud.log a "what did we actually run" line.
		$cmdline = implode(' ', array_map('strval', $cmd));
		$startedAt = microtime(true);
		$this->logInfo(sprintf('runProcess start: %s (timeout %ds)', $cmdline, $timeoutSec));

		$proc = proc_open($cmd, $descriptorSpec, $pipes, null, $env);
		if (!is_resource($proc)) {
			$this->logError('runProcess: proc_open failed for ' . $cmdline);
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

		$durationMs = (int) round((microtime(true) - $startedAt) * 1000);
		$exitName = self::exitName($exitCode);
		$lastErr = $this->lastLine($stderr);

		// fetch.log is handy for debugging from the server side without
		// having to re-trigger an Update. The header line is greppable and
		// summarises the run before the raw stdout/stderr dumps.
		@file_put_contents(
			$this->userDir() . '/fetch.log',
			'[' . date('c') . "] exit=$exitCode ($exitName) duration=${durationMs}ms\n"
				. "cmd: $cmdline\n"
				. ($lastErr !== '' ? "last stderr: $lastErr\n" : '')
				. "--- stdout ---\n$stdout\n--- stderr ---\n$stderr\n",
			LOCK_EX
		);

		// Mirror a one-line summary into owncloud.log so an admin can follow
		// every Update via `occ log` / the log viewer without shell access to
		// each user's fetch.log. Level scales with severity.
		$summary = sprintf(
			'runProcess done: exit=%d (%s) duration=%dms%s',
			$exitCode, $exitName, $durationMs,
			$lastErr !== '' ? ' | ' . $lastErr : ''
		);
		if ($exitCode === self::EXIT_OK) {
			$this->logInfo($summary);
		} elseif ($exitCode === self::EXIT_MFA_REQUIRED || $exitCode === self::EXIT_MFA_INVALID) {
			$this->logWarning($summary);
		} else {
			$this->logError($summary);
		}

		return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
	}

	// ------------------------------------------------------------------
	// Logging — to owncloud.log, tagged with this app's id. Uses the server
	// logger via the service-locator so we don't have to thread an ILogger
	// through the auto-wired constructor (which would force a DI change in
	// every repo of the vendored triplet). Logging must NEVER break a request,
	// so each call is wrapped defensively.
	// ------------------------------------------------------------------
	private function logger() {
		try {
			return \OC::$server->getLogger();
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected function logInfo(string $message): void {
		$l = $this->logger();
		if ($l !== null) { $l->info($message, ['app' => $this->appDirName()]); }
	}

	protected function logWarning(string $message): void {
		$l = $this->logger();
		if ($l !== null) { $l->warning($message, ['app' => $this->appDirName()]); }
	}

	protected function logError(string $message): void {
		$l = $this->logger();
		if ($l !== null) { $l->error($message, ['app' => $this->appDirName()]); }
	}

	/** Last non-empty line of a multi-line string, trimmed to 240 chars. */
	private function lastLine(string $text): string {
		$text = trim($text);
		if ($text === '') { return ''; }
		$lines = preg_split('/\r?\n/', $text) ?: [];
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$line = trim($lines[$i]);
			if ($line !== '') { return substr($line, 0, 240); }
		}
		return '';
	}
}
