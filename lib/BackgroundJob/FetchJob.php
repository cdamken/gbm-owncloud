<?php
/**
 * FetchJob — Fase 3: automatic background fetch + ingest + daily snapshot.
 *
 * For every user that has configured gbm_next credentials: run the same
 * Python fetch the web "Update" uses (GbmService::runFetch, session-less via
 * forUser()), then normalise the JSON into the DB (IngestService). The daily
 * portfolio_snapshot IngestService writes is what builds history over time.
 *
 * Auth ceiling: the job NEVER enters TOTP. GBM refreshes its access token
 * proactively from the refresh_token, so most runs succeed unattended; when
 * the session genuinely dies (EXIT_MFA_REQUIRED) the job logs it and skips —
 * the user re-logs in via the web app.
 *
 * ownCloud 10 instantiates legacy jobs with `new` (no DI), so the constructor
 * self-resolves its dependencies from the app container when called without
 * arguments — the documented oC10 pattern.
 */

namespace OCA\GbmNext\BackgroundJob;

use OC\BackgroundJob\TimedJob;
use OCA\GbmNext\Application;
use OCA\GbmNext\Service\GbmService;
use OCA\GbmNext\Service\IngestService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;

class FetchJob extends TimedJob {
	private const APPID = 'gbm_next';

	/** @var GbmService */
	private $gbm;
	/** @var IngestService */
	private $ingest;
	/** @var IConfig */
	private $config;
	/** @var IUserManager */
	private $userManager;
	/** @var ILogger */
	private $logger;

	public function __construct(
		?GbmService $gbm = null,
		?IngestService $ingest = null,
		?IConfig $config = null,
		?IUserManager $userManager = null,
		?ILogger $logger = null
	) {
		// 6h cadence — comfortably inside GBM's refresh-token lifetime.
		$this->setInterval(6 * 3600);

		if ($gbm === null || $ingest === null || $config === null
			|| $userManager === null || $logger === null) {
			$c = (new Application())->getContainer();
			$gbm = $gbm ?? $c->query(GbmService::class);
			$ingest = $ingest ?? $c->query(IngestService::class);
			$config = $config ?? $c->query(IConfig::class);
			$userManager = $userManager ?? $c->query(IUserManager::class);
			$logger = $logger ?? $c->query(ILogger::class);
		}
		$this->gbm = $gbm;
		$this->ingest = $ingest;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}

	protected function run($argument) {
		$this->userManager->callForAllUsers(function ($user) {
			$uid = $user->getUID();
			// Only users who configured gbm_next credentials.
			if ((string) $this->config->getUserValue($uid, self::APPID, 'password_enc', '') === '') {
				return;
			}
			$log = ['app' => self::APPID];
			try {
				$this->gbm->forUser($uid);
				$res = $this->gbm->runFetch(null, false);
				$code = (int) ($res['exitCode'] ?? -1);
				if ($code === GbmService::EXIT_OK) {
					$counts = $this->ingest->ingestForUser($uid);
					$this->logger->info(
						'FetchJob ' . $uid . ' ok: ' . json_encode($counts), $log
					);
				} elseif ($code === GbmService::EXIT_MFA_REQUIRED) {
					$this->logger->warning(
						'FetchJob ' . $uid . ': session expired, needs web re-login; skipped', $log
					);
				} else {
					$this->logger->warning(
						'FetchJob ' . $uid . ': fetch exit ' . $code
						. ' — ' . (string) ($res['stderr'] ?? ''), $log
					);
				}
			} catch (\Throwable $e) {
				$this->logger->logException($e, $log);
			}
		});
	}
}
