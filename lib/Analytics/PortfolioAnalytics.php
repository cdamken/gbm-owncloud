<?php
/**
 * PortfolioAnalytics — pure, framework-agnostic portfolio math.
 *
 * No OCP/OC dependency: arrays in, arrays out. This is the "núcleo" that
 * the DECISIONS 2026-06-17 staging ADR calls for — it serves the ownCloud
 * app today via a thin mapper-backed service, and is portable verbatim if
 * the apps are ever re-hosted (e.g. punkscloud). Money arrives as float
 * (already parsed from the DB's exact-string columns at the service edge).
 */

namespace OCA\GbmNext\Analytics;

class PortfolioAnalytics {
	/**
	 * Per-stock analysis from current holdings + dividends received.
	 *
	 * @param array<int,array{securityId:int,extId:string,name:string,assetClass:string,qty:float,avgCost:float,marketValue:float}> $holdings
	 *        avgCost is per-unit; cost basis = avgCost * qty.
	 * @param array<int,float> $dividendsBySecurity  securityId => total net received
	 * @return array<int,array<string,mixed>> one row per holding, richest first
	 */
	public static function perStock(array $holdings, array $dividendsBySecurity): array {
		$rows = [];
		foreach ($holdings as $h) {
			$qty = (float) $h['qty'];
			$avg = (float) $h['avgCost'];
			$mv = (float) $h['marketValue'];
			$cost = $avg * $qty;
			$div = (float) ($dividendsBySecurity[$h['securityId']] ?? 0.0);
			$upl = $mv - $cost;
			$rows[] = [
				'security_id'       => (int) $h['securityId'],
				'ext_id'            => (string) $h['extId'],
				'name'              => (string) $h['name'],
				'asset_class'       => (string) ($h['assetClass'] ?? ''),
				'quantity'          => $qty,
				'avg_cost'          => $avg,
				'cost_basis'        => $cost,
				'market_value'      => $mv,
				'unrealized_pl'     => $upl,
				'unrealized_pct'    => $cost > 0.0 ? $upl / $cost * 100.0 : 0.0,
				'dividends'         => $div,
				'yield_on_cost_pct' => $cost > 0.0 ? $div / $cost * 100.0 : 0.0,
			];
		}
		usort($rows, static function ($a, $b) {
			return $b['market_value'] <=> $a['market_value'];
		});
		return $rows;
	}

	/**
	 * Portfolio-level totals + each row's weight in the total market value.
	 *
	 * @param array<int,array<string,mixed>> $perStock  output of perStock()
	 * @return array<string,mixed>
	 */
	public static function summary(array $perStock): array {
		$cost = 0.0;
		$mv = 0.0;
		$div = 0.0;
		foreach ($perStock as $r) {
			$cost += (float) $r['cost_basis'];
			$mv += (float) $r['market_value'];
			$div += (float) $r['dividends'];
		}
		$upl = $mv - $cost;
		return [
			'positions'        => count($perStock),
			'cost_basis'       => $cost,
			'market_value'     => $mv,
			'unrealized_pl'    => $upl,
			'unrealized_pct'   => $cost > 0.0 ? $upl / $cost * 100.0 : 0.0,
			'dividends'        => $div,
			'yield_on_cost_pct' => $cost > 0.0 ? $div / $cost * 100.0 : 0.0,
		];
	}

	/**
	 * Concentration: each holding's share of total market value, biggest
	 * first, plus the top-N aggregate share. The denominator is total
	 * holdings market value (cash is added by the caller if it wants the
	 * full-portfolio view — see the GBM concentration fix).
	 *
	 * @param array<int,array<string,mixed>> $perStock
	 * @return array<string,mixed>
	 */
	public static function concentration(array $perStock, float $cashValue = 0.0, int $topN = 5): array {
		$total = $cashValue;
		foreach ($perStock as $r) {
			$total += (float) $r['market_value'];
		}
		$weights = [];
		foreach ($perStock as $r) {
			$weights[] = [
				'ext_id' => $r['ext_id'],
				'name'   => $r['name'],
				'pct'    => $total > 0.0 ? (float) $r['market_value'] / $total * 100.0 : 0.0,
			];
		}
		$topShare = 0.0;
		foreach (array_slice($weights, 0, $topN) as $w) {
			$topShare += $w['pct'];
		}
		return [
			'total_value'      => $total,
			'weights'          => $weights,
			'top_n'            => $topN,
			'top_n_pct'        => $topShare,
			'largest_pct'      => $weights[0]['pct'] ?? 0.0,
			'largest_ext_id'   => $weights[0]['ext_id'] ?? '',
		];
	}
}
