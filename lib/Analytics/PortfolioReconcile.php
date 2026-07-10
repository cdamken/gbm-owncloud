<?php
/**
 * Portafolio "money model" — the reconciling set of headline lines + a
 * per-account breakdown, computed from ONE source (current holdings + accounts).
 * Pure, PHP 7.4. Realized P&L is deferred (needs full FIFO lot coverage) and
 * returned as null. Per-account unrealized P&L comes only from holdings, so it
 * always sums back to the header (kills the header-vs-cards divergence).
 */

namespace OCA\Gbm\Analytics;

class PortfolioReconcile {
	/**
	 * @param array $holdings list of ['accountId'=>int,'qty'=>float,'avgCost'=>float,'marketValue'=>float]
	 * @param array $accounts list of ['id'=>int,'key'=>string,'name'=>string,'cash'=>float]
	 * @param float $netContrib external deposits − external withdrawals
	 * @param float $income dividends + interest − withholding (net ISR)
	 * @return array money model (see keys below)
	 */
	public static function build(array $holdings, array $accounts, float $netContrib, float $income): array {
		$byAcct = [];
		$mvTotal = 0.0;
		$costTotal = 0.0;
		foreach ($holdings as $h) {
			$aid = (int) $h['accountId'];
			$mv = (float) $h['marketValue'];
			$cost = (float) $h['avgCost'] * (float) $h['qty'];
			if (!isset($byAcct[$aid])) {
				$byAcct[$aid] = ['mv' => 0.0, 'cost' => 0.0];
			}
			$byAcct[$aid]['mv'] += $mv;
			$byAcct[$aid]['cost'] += $cost;
			$mvTotal += $mv;
			$costTotal += $cost;
		}
		$cashTotal = 0.0;
		$acctOut = [];
		foreach ($accounts as $a) {
			$aid = (int) $a['id'];
			$cash = (float) $a['cash'];
			$cashTotal += $cash;
			$agg = $byAcct[$aid] ?? ['mv' => 0.0, 'cost' => 0.0];
			$acctOut[] = [
				'key'           => (string) $a['key'],
				'name'          => (string) $a['name'],
				'value'         => $agg['mv'] + $cash,
				'unrealized_pl' => $agg['mv'] - $agg['cost'],
			];
		}
		$unrealized = $mvTotal - $costTotal;
		return [
			'market_value'      => $mvTotal,
			'cost_basis'        => $costTotal,
			'unrealized_pl'     => $unrealized,
			'unrealized_pct'    => $costTotal > 0.0 ? $unrealized / $costTotal * 100.0 : 0.0,
			'cash'              => $cashTotal,
			'total_value'       => $mvTotal + $cashTotal,
			'net_contributions' => $netContrib,
			'income_net'        => $income,
			'realized_pl'       => null,
			'positions_count'   => count($holdings),
			'accounts'          => $acctOut,
		];
	}
}
