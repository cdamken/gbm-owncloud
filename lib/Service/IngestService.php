<?php
/**
 * IngestService — normalise the GBM sync() JSON into the DB tables.
 *
 * Frontier (DECISIONS 2026-06-17): Python sync() produces JSON in the user's
 * data dir; PHP owns storage. This service reads those files and upserts them
 * into the gbm_* tables. It is idempotent:
 *   - STATE (accounts, holdings): replaced wholesale each run (reflects "now").
 *   - EVENTS (orders, transactions, dividends): upserted by external_id (a
 *     re-run never duplicates; history accumulates).
 *   - SECURITIES (reference): find-or-create by broker ext_id, kept.
 *   - HISTORY: one portfolio_snapshot per data date (upsert by captured_on).
 *
 * No OCP\* leaks into the mapping rules themselves — the shape knowledge is
 * plain PHP, so the same logic could back a different host later.
 *
 * Run via `occ gbm_next:ingest <user>` (lib/Command/Ingest.php), reused by the
 * Fase 3 background job.
 */

namespace OCA\GbmNext\Service;

use OCA\GbmNext\Db\Account;
use OCA\GbmNext\Db\AccountMapper;
use OCA\GbmNext\Db\Dividend;
use OCA\GbmNext\Db\DividendMapper;
use OCA\GbmNext\Db\Holding;
use OCA\GbmNext\Db\HoldingMapper;
use OCA\GbmNext\Db\Order;
use OCA\GbmNext\Db\OrderMapper;
use OCA\GbmNext\Db\PortfolioSnapshot;
use OCA\GbmNext\Db\PortfolioSnapshotMapper;
use OCA\GbmNext\Db\Security;
use OCA\GbmNext\Db\SecurityMapper;
use OCA\GbmNext\Db\Transaction;
use OCA\GbmNext\Db\TransactionMapper;
use OCP\IConfig;

class IngestService {
	/** positions.json sections that hold securities (efectivo = cash, total = summary). */
	private const INVEST_SECTIONS = [
		'mercado_capitales',
		'mercados_globales_sic',
		'mercado_extranjero',
		'sociedades_inversion_comun',
		'sociedades_inversion_deuda',
	];

	/** @var IConfig */
	private $config;
	/** @var SecurityMapper */
	private $securities;
	/** @var AccountMapper */
	private $accounts;
	/** @var HoldingMapper */
	private $holdings;
	/** @var OrderMapper */
	private $orders;
	/** @var TransactionMapper */
	private $transactions;
	/** @var DividendMapper */
	private $dividends;
	/** @var PortfolioSnapshotMapper */
	private $snapshots;

	/** ext_id => security row id, cached per run to avoid re-querying. */
	private $secCache = [];

	public function __construct(
		IConfig $config,
		SecurityMapper $securities,
		AccountMapper $accounts,
		HoldingMapper $holdings,
		OrderMapper $orders,
		TransactionMapper $transactions,
		DividendMapper $dividends,
		PortfolioSnapshotMapper $snapshots
	) {
		$this->config = $config;
		$this->securities = $securities;
		$this->accounts = $accounts;
		$this->holdings = $holdings;
		$this->orders = $orders;
		$this->transactions = $transactions;
		$this->dividends = $dividends;
		$this->snapshots = $snapshots;
	}

	public function dataDir(string $uid): string {
		$base = (string) $this->config->getSystemValue('datadirectory', '/var/www/owncloud/data');
		return rtrim($base, '/') . '/' . $uid . '/gbm_next';
	}

	/**
	 * Ingest one user's current JSON. Returns per-table counts.
	 *
	 * @return array<string,int>
	 */
	public function ingestForUser(string $uid): array {
		$dir = $this->dataDir($uid);
		if (!is_dir($dir)) {
			throw new \RuntimeException("No gbm_next data dir for '$uid': $dir");
		}
		$this->secCache = [];

		$positions    = $this->loadJson($dir, 'positions.json') ?? [];
		$accountsJson = $this->loadJson($dir, 'accounts.json') ?? [];
		$ordersJson   = $this->loadJson($dir, 'orders_all.json')
			?? $this->loadJson($dir, 'orders.json') ?? [];
		$txJson       = $this->loadJson($dir, 'transactions.json') ?? [];
		$divJson      = $this->loadJson($dir, 'dividends.json') ?? [];
		$asOf         = $this->readAsOf($dir);

		$counts = [
			'accounts' => 0, 'holdings' => 0, 'securities' => 0,
			'orders' => 0, 'transactions' => 0, 'dividends' => 0, 'snapshot' => 0,
		];

		// --- STATE: replace accounts + holdings wholesale ------------------
		$this->holdings->deleteByUser($uid);
		$this->accounts->deleteByUser($uid);

		$keyToId = [];
		$totalValue = 0.0;
		$totalCash = 0.0;
		foreach ($accountsJson as $a) {
			$key = (string) ($a['legacy_contract_id'] ?? $a['account_id'] ?? '');
			if ($key === '') {
				continue;
			}
			$pos = $positions[$key] ?? null;
			[$accTotal, $accCash] = $this->accountTotals($pos);
			$totalValue += $accTotal;
			$totalCash += $accCash;

			$acc = new Account();
			$acc->setUserId($uid);
			$acc->setAccountKey($key);
			$acc->setName((string) ($a['name'] ?? $key));
			$acc->setType((string) ($a['management_type_template'] ?? ''));
			$acc->setCurrency('MXN');
			$acc->setCashAmount($this->num($accCash));
			$acc->setTotalValue($this->num($accTotal));
			$acc->setUpdatedAt($asOf);
			$saved = $this->accounts->insert($acc);
			$keyToId[$key] = (int) $saved->getId();
			$counts['accounts']++;
		}

		// --- HOLDINGS from positions invest sections ----------------------
		// GBM can list the same instrument more than once per account (lots);
		// aggregate by (account, security): sum qty + market value, cost is the
		// quantity-weighted average. Avoids the (user,account,security) clash.
		$totalCost = 0.0;
		$agg = [];
		foreach ($positions as $key => $sections) {
			$accId = $keyToId[(string) $key] ?? null;
			if ($accId === null || !is_array($sections)) {
				continue;
			}
			foreach (self::INVEST_SECTIONS as $section) {
				$list = $sections[$section] ?? null;
				if (!is_array($list)) {
					continue;
				}
				foreach ($list as $h) {
					$extId = (string) ($h['issue_id'] ?? '');
					if ($extId === '') {
						continue;
					}
					$secId = $this->resolveSecurity($uid, $extId, (string) ($h['issue_name'] ?? ''), $section);
					$k = $accId . ':' . $secId;
					if (!isset($agg[$k])) {
						$agg[$k] = [
							'acc' => $accId, 'sec' => $secId,
							'qty' => 0.0, 'mv' => 0.0, 'costSum' => 0.0,
							'last' => $h['last_price'] ?? null, 'close' => $h['close_price'] ?? null,
						];
					}
					$qty = (float) ($h['quantity'] ?? 0);
					$avg = (float) ($h['average_cost'] ?? $h['average_price'] ?? 0);
					$agg[$k]['qty'] += $qty;
					$agg[$k]['mv'] += (float) ($h['market_value'] ?? 0);
					$agg[$k]['costSum'] += $qty * $avg;
				}
			}
		}
		foreach ($agg as $a) {
			$qty = $a['qty'];
			$avgCost = $qty != 0.0 ? $a['costSum'] / $qty : 0.0;
			$totalCost += $a['costSum'];

			$hold = new Holding();
			$hold->setUserId($uid);
			$hold->setAccountId($a['acc']);
			$hold->setSecurityId($a['sec']);
			$hold->setQuantity($this->num($qty));
			$hold->setAvgCost($this->num($avgCost));
			$hold->setLastPrice($this->num($a['last']));
			$hold->setClosePrice($this->num($a['close']));
			$hold->setMarketValue($this->num($a['mv']));
			$hold->setCurrency('MXN');
			$hold->setUpdatedAt($asOf);
			$this->holdings->insert($hold);
			$counts['holdings']++;
		}
		$counts['securities'] = count($this->secCache);

		// --- EVENTS: orders / transactions / dividends (upsert) ------------
		foreach (($ordersJson['orders'] ?? []) as $o) {
			$ext = (string) ($o['sob_id'] ?? '');
			if ($ext === '') {
				continue;
			}
			$secId = isset($o['issue_id']) && $o['issue_id'] !== ''
				? $this->resolveSecurity($uid, (string) $o['issue_id'], '', '') : null;
			$status = !empty($o['is_filled']) ? 'filled'
				: (!empty($o['is_cancelled']) ? 'cancelled' : (string) ($o['status_label'] ?? ''));
			$entity = $this->orders->findByExternalId($uid, $ext);
			if ($entity === null) {
				$entity = new Order();
				$entity->setUserId($uid);
				$entity->setExternalId($ext);
			}
			$entity->setAccountKey((string) ($o['account_legacy_id'] ?? ''));
			if ($secId !== null) {
				$entity->setSecurityId($secId);
			}
			$entity->setSide(strtolower((string) ($o['side'] ?? '')));
			$entity->setQuantity($this->num($o['quantity'] ?? $o['assigned_quantity'] ?? null));
			$entity->setPrice($this->num($o['average_price'] ?? null));
			$entity->setFees($this->num($o['commission'] ?? null));
			$entity->setCurrency('MXN');
			$entity->setExecutedAt((string) ($o['processed_at'] ?? ''));
			$entity->setStatus($status);
			$this->save($this->orders, $entity);
			$counts['orders']++;
		}

		foreach (($txJson['transactions'] ?? []) as $t) {
			$ext = (string) ($t['transaction_id'] ?? '');
			if ($ext === '') {
				continue;
			}
			$secId = isset($t['security_id']) && $t['security_id'] !== ''
				? $this->resolveSecurity($uid, (string) $t['security_id'], (string) ($t['security_name'] ?? ''), '') : null;
			$entity = $this->transactions->findByExternalId($uid, $ext);
			if ($entity === null) {
				$entity = new Transaction();
				$entity->setUserId($uid);
				$entity->setExternalId($ext);
			}
			$entity->setType((string) ($t['category'] ?? ''));
			$entity->setRawType((string) ($t['transaction_type'] ?? ''));
			$entity->setAmount($this->num($t['amount'] ?? null));
			$entity->setCurrency('MXN');
			if ($secId !== null) {
				$entity->setSecurityId($secId);
			}
			$entity->setBookedAt((string) ($t['process_date'] ?? ''));
			$this->save($this->transactions, $entity);
			$counts['transactions']++;
		}

		foreach (($divJson['dividends'] ?? []) as $d) {
			$ext = (string) ($d['transaction_id'] ?? '');
			if ($ext === '') {
				continue;
			}
			$secId = isset($d['security_id']) && $d['security_id'] !== ''
				? $this->resolveSecurity($uid, (string) $d['security_id'], (string) ($d['security_name'] ?? ''), '') : null;
			$gross = (float) ($d['amount'] ?? 0);
			$net = (float) ($d['net_amount'] ?? $gross);
			$entity = $this->dividends->findByExternalId($uid, $ext);
			if ($entity === null) {
				$entity = new Dividend();
				$entity->setUserId($uid);
				$entity->setExternalId($ext);
			}
			if ($secId !== null) {
				$entity->setSecurityId($secId);
			}
			$entity->setGross($this->num($d['amount'] ?? null));
			$entity->setNet($this->num($d['net_amount'] ?? null));
			$entity->setTax($this->num(max(0.0, $gross - $net)));
			$entity->setCurrency('MXN');
			$entity->setPaidAt((string) ($d['process_date'] ?? $d['settlement_date'] ?? ''));
			$this->save($this->dividends, $entity);
			$counts['dividends']++;
		}

		// --- HISTORY: one portfolio snapshot for the data date -------------
		$snap = $this->snapshots->findByDate($uid, $asOf);
		if ($snap === null) {
			$snap = new PortfolioSnapshot();
			$snap->setUserId($uid);
			$snap->setCapturedOn($asOf);
		}
		$snap->setTotalValue($this->num($totalValue));
		$snap->setTotalCost($this->num($totalCost));
		$snap->setCash($this->num($totalCash));
		$snap->setCurrency('MXN');
		$snap->setSource('ingest');
		$this->save($this->snapshots, $snap);
		$counts['snapshot'] = 1;

		return $counts;
	}

	// ---------------------------------------------------------------------
	// helpers
	// ---------------------------------------------------------------------

	/** Insert if the entity has no id yet, else update. */
	private function save($mapper, $entity): void {
		if ($entity->getId() === null) {
			$mapper->insert($entity);
		} else {
			$mapper->update($entity);
		}
	}

	/** Find-or-create a security by broker ext_id; returns its row id. */
	private function resolveSecurity(string $uid, string $extId, string $name, string $section): int {
		if (isset($this->secCache[$extId])) {
			return $this->secCache[$extId];
		}
		$sec = $this->securities->findByExtId($uid, $extId);
		if ($sec === null) {
			$sec = new Security();
			$sec->setUserId($uid);
			$sec->setExtId($extId);
			$sec->setName($name);
			$sec->setAssetClass($this->assetClass($section));
			$sec->setRegion($this->region($section));
			$sec->setCurrency('MXN');
			$sec = $this->securities->insert($sec);
		} elseif ($name !== '' && (string) $sec->getName() === '') {
			$sec->setName($name);
			$this->securities->update($sec);
		}
		$id = (int) $sec->getId();
		$this->secCache[$extId] = $id;
		return $id;
	}

	private function assetClass(string $section): string {
		switch ($section) {
			case 'mercado_capitales':          return 'equity';
			case 'mercados_globales_sic':       return 'equity_sic';
			case 'mercado_extranjero':          return 'equity_foreign';
			case 'sociedades_inversion_deuda':  return 'debt_fund';
			case 'sociedades_inversion_comun':  return 'equity_fund';
			default:                            return '';
		}
	}

	private function region(string $section): string {
		if ($section === 'mercados_globales_sic' || $section === 'mercado_extranjero') {
			return 'foreign';
		}
		return $section === '' ? '' : 'MX';
	}

	/** [total_portfolio_value, cash] for one account's positions block. */
	private function accountTotals($pos): array {
		if (!is_array($pos)) {
			return [0.0, 0.0];
		}
		$total = 0.0;
		$tpv = $pos['total_portfolio_value'][0]['market_value'] ?? null;
		if ($tpv !== null) {
			$total = (float) $tpv;
		} else {
			foreach (self::INVEST_SECTIONS as $s) {
				foreach (($pos[$s] ?? []) as $h) {
					$total += (float) ($h['market_value'] ?? 0);
				}
			}
		}
		$cash = 0.0;
		foreach (($pos['efectivo'] ?? []) as $h) {
			$cash += (float) ($h['market_value'] ?? 0);
		}
		$total += $cash;
		return [$total, $cash];
	}

	/** @return array|null */
	private function loadJson(string $dir, string $file) {
		$p = $dir . '/' . $file;
		if (!is_file($p)) {
			return null;
		}
		$d = json_decode((string) file_get_contents($p), true);
		return is_array($d) ? $d : null;
	}

	/** Data "as of" date (YYYY-MM-DD) from last_update.date, else today. */
	private function readAsOf(string $dir): string {
		$p = $dir . '/last_update.date';
		if (is_file($p)) {
			$raw = trim((string) file_get_contents($p));
			if ($raw !== '') {
				return substr($raw, 0, 10);
			}
		}
		return date('Y-m-d');
	}

	/** DECIMAL travels as string; format a number without scientific notation. */
	private function num($v): ?string {
		if ($v === null || $v === '') {
			return null;
		}
		if (is_int($v)) {
			return (string) $v;
		}
		if (is_string($v) && !is_numeric($v)) {
			return $v;
		}
		$f = (float) $v;
		$s = rtrim(rtrim(sprintf('%.6f', $f), '0'), '.');
		return $s === '' || $s === '-0' ? '0' : $s;
	}
}
