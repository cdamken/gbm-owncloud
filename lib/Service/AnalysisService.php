<?php
/**
 * AnalysisService — thin DB-backed adapter over the pure PortfolioAnalytics.
 *
 * Loads a user's holdings / securities / dividends / accounts via the
 * mappers, converts the exact-string money columns to float at this edge,
 * and delegates ALL math to OCA\GbmNext\Analytics\PortfolioAnalytics (which
 * has zero framework dependency). Per-user scoped by construction.
 */

namespace OCA\GbmNext\Service;

use OCA\GbmNext\Analytics\PortfolioAnalytics;
use OCA\GbmNext\Db\AccountMapper;
use OCA\GbmNext\Db\DividendMapper;
use OCA\GbmNext\Db\HoldingMapper;
use OCA\GbmNext\Db\SecurityMapper;

class AnalysisService {
	/** @var HoldingMapper */
	private $holdings;
	/** @var SecurityMapper */
	private $securities;
	/** @var DividendMapper */
	private $dividends;
	/** @var AccountMapper */
	private $accounts;

	public function __construct(
		HoldingMapper $holdings,
		SecurityMapper $securities,
		DividendMapper $dividends,
		AccountMapper $accounts
	) {
		$this->holdings = $holdings;
		$this->securities = $securities;
		$this->dividends = $dividends;
		$this->accounts = $accounts;
	}

	/**
	 * @return array{summary:array,per_stock:array,concentration:array}
	 */
	public function perUser(string $uid): array {
		// security id -> [ext_id, name, asset_class]
		$secById = [];
		foreach ($this->securities->findByUser($uid) as $s) {
			$secById[(int) $s->getId()] = [
				'ext_id'      => (string) $s->getExtId(),
				'name'        => (string) $s->getName(),
				'asset_class' => (string) $s->getAssetClass(),
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
			$sec = $secById[$sid] ?? ['ext_id' => (string) $sid, 'name' => '', 'asset_class' => ''];
			$rows[] = [
				'securityId'   => $sid,
				'extId'        => $sec['ext_id'],
				'name'         => $sec['name'],
				'assetClass'   => $sec['asset_class'],
				'qty'          => $this->f($h->getQuantity()),
				'avgCost'      => $this->f($h->getAvgCost()),
				'marketValue'  => $this->f($h->getMarketValue()),
			];
		}

		$cash = 0.0;
		foreach ($this->accounts->findByUser($uid) as $a) {
			$cash += $this->f($a->getCashAmount());
		}

		$perStock = PortfolioAnalytics::perStock($rows, $divBySec);
		return [
			'summary'       => PortfolioAnalytics::summary($perStock),
			'per_stock'     => $perStock,
			'concentration' => PortfolioAnalytics::concentration($perStock, $cash),
		];
	}

	/** DB money is an exact decimal string (or null) — parse at this edge only. */
	private function f($v): float {
		return $v === null || $v === '' ? 0.0 : (float) $v;
	}
}
