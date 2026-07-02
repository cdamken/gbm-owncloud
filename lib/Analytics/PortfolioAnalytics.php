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

namespace OCA\Gbm\Analytics;

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
	 * Ganadores y perdedores: total return per holding (unrealized price
	 * change + dividends received), ranked best → worst by percent return.
	 * Pure — derives entirely from perStock() output, no new data. Money is
	 * already float at this point (parsed at the service edge).
	 *
	 *   total_return     = unrealized_pl + dividends            (MXN)
	 *   total_return_pct = cost_basis>0 ? total_return/cost_basis*100 : 0
	 *
	 * @param array<int,array<string,mixed>> $perStock  output of perStock()
	 * @return array<int,array<string,mixed>> one row per holding, best return% first
	 */
	public static function winnersLosers(array $perStock): array {
		$rows = [];
		foreach ($perStock as $r) {
			$cost = (float) $r['cost_basis'];
			$upl  = (float) $r['unrealized_pl'];
			$div  = (float) $r['dividends'];
			$tr   = $upl + $div;
			$rows[] = [
				'ext_id'           => (string) $r['ext_id'],
				'name'             => (string) $r['name'],
				'cost_basis'       => $cost,
				'market_value'     => (float) $r['market_value'],
				'unrealized_pl'    => $upl,
				'dividends'        => $div,
				'total_return'     => $tr,
				'total_return_pct' => $cost > 0.0 ? $tr / $cost * 100.0 : 0.0,
			];
		}
		usort($rows, static function ($a, $b) {
			return $b['total_return_pct'] <=> $a['total_return_pct'];
		});
		return $rows;
	}

	/**
	 * ¿Dónde está mi dinero? — portfolio allocation grouped three ways:
	 *   - market: the GBM section (relabel of asset_class)
	 *   - class:  economic class (renta variable / renta fija / efectivo)
	 *   - region: mx / foreign
	 * Cash is folded in as its own bucket (efectivo / mx). Pure, PHP 7.4.
	 *
	 * The three dimensions always sum to the same grand total: a holding with an
	 * unrecognized asset_class is a data bug and is dropped from ALL dimensions
	 * (never bucketed as "otro"), so totals stay equal.
	 *
	 * @param array $holdings list of ['asset_class'=>string,'region'=>string,'market_value'=>float]
	 * @param float $cashValue total cash (MXN)
	 * @return array{market:array,class:array,region:array} each a list of
	 *   ['key'=>string,'value'=>float] sorted by value descending
	 */
	public static function allocation(array $holdings, float $cashValue): array {
		$marketByClass = [
			'equity'         => 'mercado_capitales',
			'equity_sic'     => 'mercados_globales_sic',
			'equity_foreign' => 'mercado_extranjero',
			'equity_fund'    => 'sociedades_inversion_comun',
			'debt_fund'      => 'sociedades_inversion_deuda',
		];
		$market = [];
		$class = [];
		$region = [];
		foreach ($holdings as $h) {
			$v = (float) $h['market_value'];
			if ($v <= 0.0) {
				continue;
			}
			$ac = (string) $h['asset_class'];
			if (!isset($marketByClass[$ac])) {
				continue; // unrecognized class -> dropped from every dimension
			}
			$mk = $marketByClass[$ac];
			$market[$mk] = ($market[$mk] ?? 0.0) + $v;
			$ck = $ac === 'debt_fund' ? 'renta_fija' : 'renta_variable';
			$class[$ck] = ($class[$ck] ?? 0.0) + $v;
			$rg = ((string) $h['region']) === 'foreign' ? 'foreign' : 'mx';
			$region[$rg] = ($region[$rg] ?? 0.0) + $v;
		}
		// Negative cash (legitimate during T+2 buy settlement — see
		// IngestService::accountTotals) can't be a doughnut slice, so it is
		// intentionally not folded into any bucket. Locked by test_allocation.
		if ($cashValue > 0.0) {
			$market['efectivo'] = ($market['efectivo'] ?? 0.0) + $cashValue;
			$class['efectivo'] = ($class['efectivo'] ?? 0.0) + $cashValue;
			$region['mx'] = ($region['mx'] ?? 0.0) + $cashValue;
		}
		return [
			'market' => self::bucketsToSortedList($market),
			'class'  => self::bucketsToSortedList($class),
			'region' => self::bucketsToSortedList($region),
		];
	}

	/**
	 * Turn a key=>value bucket map into a list of ['key','value'] sorted by
	 * value descending. Pure helper for allocation().
	 *
	 * @param array<string,float> $buckets
	 * @return array<int,array{key:string,value:float}>
	 */
	private static function bucketsToSortedList(array $buckets): array {
		$out = [];
		foreach ($buckets as $key => $value) {
			$out[] = ['key' => (string) $key, 'value' => (float) $value];
		}
		usort($out, static function ($a, $b) {
			return $b['value'] <=> $a['value'];
		});
		return $out;
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
