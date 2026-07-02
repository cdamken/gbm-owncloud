<?php
/**
 * AnalysisService — thin DB-backed adapter over the pure PortfolioAnalytics.
 *
 * Loads a user's holdings / securities / dividends / accounts via the
 * mappers, converts the exact-string money columns to float at this edge,
 * and delegates ALL math to OCA\Gbm\Analytics\PortfolioAnalytics (which
 * has zero framework dependency). Per-user scoped by construction.
 */

namespace OCA\Gbm\Service;

use OCA\Gbm\Analytics\PortfolioAnalytics;
use OCA\Gbm\Db\AccountMapper;
use OCA\Gbm\Db\DividendMapper;
use OCA\Gbm\Db\HoldingMapper;
use OCA\Gbm\Db\PortfolioSnapshotMapper;
use OCA\Gbm\Db\SecurityMapper;

class AnalysisService {
	/** @var HoldingMapper */
	private $holdings;
	/** @var SecurityMapper */
	private $securities;
	/** @var DividendMapper */
	private $dividends;
	/** @var AccountMapper */
	private $accounts;
	/** @var PortfolioSnapshotMapper */
	private $snapshots;

	public function __construct(
		HoldingMapper $holdings,
		SecurityMapper $securities,
		DividendMapper $dividends,
		AccountMapper $accounts,
		PortfolioSnapshotMapper $snapshots
	) {
		$this->holdings = $holdings;
		$this->securities = $securities;
		$this->dividends = $dividends;
		$this->accounts = $accounts;
		$this->snapshots = $snapshots;
	}

	/**
	 * @return array{summary:array,per_stock:array,concentration:array,winners_losers:array,allocation:array,history:array}
	 */
	public function perUser(string $uid): array {
		// security id -> [ext_id, name, asset_class, region]
		$secById = [];
		foreach ($this->securities->findByUser($uid) as $s) {
			$secById[(int) $s->getId()] = [
				'ext_id'      => (string) $s->getExtId(),
				'name'        => (string) $s->getName(),
				'asset_class' => (string) $s->getAssetClass(),
				'region'      => (string) $s->getRegion(),
			];
		}

		// dividends summed (net, falling back to gross) per security
		$divBySec = [];
		foreach ($this->dividends->findByUser($uid) as $d) {
			$sid = (int) $d->getSecurityId();
			$net = $this->f($d->getNet());
			if ($net === 0.0) {
				$net = $this->f($d->getGross());
			}
			$divBySec[$sid] = ($divBySec[$sid] ?? 0.0) + $net;
		}

		// holdings -> the shape PortfolioAnalytics expects
		$rows = [];
		foreach ($this->holdings->findByUser($uid) as $h) {
			$sid = (int) $h->getSecurityId();
			$sec = $secById[$sid] ?? ['ext_id' => (string) $sid, 'name' => '', 'asset_class' => '', 'region' => ''];
			$rows[] = [
				'securityId'   => $sid,
				'extId'        => $sec['ext_id'],
				'name'         => $sec['name'],
				'assetClass'   => $sec['asset_class'],
				'region'       => $sec['region'],
				'qty'          => $this->f($h->getQuantity()),
				'avgCost'      => $this->f($h->getAvgCost()),
				'marketValue'  => $this->f($h->getMarketValue()),
			];
		}

		$cash = 0.0;
		foreach ($this->accounts->findByUser($uid) as $a) {
			$cash += $this->f($a->getCashAmount());
		}

		// Real portfolio-value history (the DB's killer feature; grows daily).
		$history = [];
		foreach ($this->snapshots->findByUser($uid) as $s) {
			$history[] = [
				'date'  => (string) $s->getCapturedOn(),
				'value' => $this->f($s->getTotalValue()),
				'cost'  => $this->f($s->getTotalCost()),
			];
		}

		$perStock = PortfolioAnalytics::perStock($rows, $divBySec);

		// Allocation input: asset_class + region + market_value per holding.
		$allocInput = [];
		foreach ($rows as $r) {
			$allocInput[] = [
				'asset_class'  => $r['assetClass'],
				'region'       => $r['region'],
				'market_value' => $r['marketValue'],
			];
		}

		return [
			'summary'        => PortfolioAnalytics::summary($perStock),
			'per_stock'      => $perStock,
			'concentration'  => PortfolioAnalytics::concentration($perStock, $cash),
			'winners_losers' => PortfolioAnalytics::winnersLosers($perStock),
			'allocation'     => PortfolioAnalytics::allocation($allocInput, $cash),
			'history'        => $history,
		];
	}

	/** DB money is an exact decimal string (or null) — parse at this edge only. */
	private function f($v): float {
		return $v === null || $v === '' ? 0.0 : (float) $v;
	}
}
