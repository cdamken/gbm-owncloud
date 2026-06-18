<?php
/**
 * FifoLots — pure, framework-agnostic FIFO lot accounting for one security.
 *
 * Buys open lots; sells consume the oldest open lots first (FIFO), realising
 * P&L on the matched quantity. Fees are folded into cost (buys) and netted
 * from proceeds (sells). Leftover open lots carry the remaining cost basis.
 *
 * Honesty note for GBM: the order history is incomplete (Personal-only,
 * ~200-day window, Trading USA returns none). A sell with no matching buy in
 * the data leaves an "unmatched" quantity that we do NOT fabricate a cost for
 * — it's excluded from realised P&L and surfaced as coverage, so the number
 * is never silently wrong.
 *
 * Arrays in, arrays out — no OCP/OC dependency (the portable núcleo).
 */

namespace OCA\GbmNext\Analytics;

class FifoLots {
	private const EPS = 1e-9;

	/**
	 * @param array<int,array{side:string,qty:float,price:float,fees:float,date:string,orderId:int}> $orders
	 *        side already normalised to 'buy' | 'sell'; sorted oldest-first.
	 * @return array{open_lots:array,realized_pl:float,realized_qty:float,unmatched_sell_qty:float,buys:int,sells:int}
	 */
	public static function compute(array $orders): array {
		$lots = [];                 // FIFO queue of open lots
		$realized = 0.0;
		$realizedQty = 0.0;
		$unmatched = 0.0;
		$buys = 0;
		$sells = 0;

		foreach ($orders as $o) {
			$qty = (float) $o['qty'];
			$price = (float) $o['price'];
			$fees = (float) $o['fees'];
			if ($qty <= self::EPS) {
				continue;
			}
			if ($o['side'] === 'buy') {
				$buys++;
				$cost = $qty * $price + $fees;
				$lots[] = [
					'qty_open'  => $qty,
					'qty_orig'  => $qty,
					'unit_cost' => $cost / $qty,
					'opened_at' => (string) $o['date'],
					'order_id'  => (int) ($o['orderId'] ?? 0),
				];
			} elseif ($o['side'] === 'sell') {
				$sells++;
				$remaining = $qty;
				$costConsumed = 0.0;
				$matched = 0.0;
				while ($remaining > self::EPS && !empty($lots)) {
					$take = min($remaining, $lots[0]['qty_open']);
					$costConsumed += $take * $lots[0]['unit_cost'];
					$lots[0]['qty_open'] -= $take;
					$remaining -= $take;
					$matched += $take;
					if ($lots[0]['qty_open'] <= self::EPS) {
						array_shift($lots);
					}
				}
				if ($matched > self::EPS) {
					// Proceeds for the matched portion, fees pro-rated.
					$proceeds = $matched * $price - $fees * ($matched / $qty);
					$realized += $proceeds - $costConsumed;
					$realizedQty += $matched;
				}
				if ($remaining > self::EPS) {
					$unmatched += $remaining;   // sold more than we have buys for
				}
			}
		}

		$openLots = [];
		foreach ($lots as $l) {
			if ($l['qty_open'] > self::EPS) {
				$openLots[] = [
					'qty_orig'      => $l['qty_orig'],
					'qty_open'      => $l['qty_open'],
					'cost_basis'    => $l['qty_open'] * $l['unit_cost'],
					'opened_at'     => $l['opened_at'],
					'open_order_id' => $l['order_id'],
				];
			}
		}

		return [
			'open_lots'          => $openLots,
			'realized_pl'        => $realized,
			'realized_qty'       => $realizedQty,
			'unmatched_sell_qty' => $unmatched,
			'buys'               => $buys,
			'sells'              => $sells,
		];
	}
}
