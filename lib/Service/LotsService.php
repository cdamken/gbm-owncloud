<?php
/**
 * LotsService — thin DB adapter over the pure FifoLots calculator.
 *
 * Loads a user's FILLED orders, groups by security, runs FIFO per security,
 * persists the resulting open lots to gbm_lots (replaced wholesale), and
 * returns realised P&L + an honest coverage report (GBM's order history is
 * incomplete — see FifoLots). All math lives in the pure layer.
 */

namespace OCA\GbmNext\Service;

use OCA\GbmNext\Analytics\FifoLots;
use OCA\GbmNext\Db\Lot;
use OCA\GbmNext\Db\LotMapper;
use OCA\GbmNext\Db\OrderMapper;
use OCA\GbmNext\Db\SecurityMapper;

class LotsService {
	/** @var OrderMapper */
	private $orders;
	/** @var LotMapper */
	private $lots;
	/** @var SecurityMapper */
	private $securities;

	public function __construct(OrderMapper $orders, LotMapper $lots, SecurityMapper $securities) {
		$this->orders = $orders;
		$this->lots = $lots;
		$this->securities = $securities;
	}

	/**
	 * Recompute + persist FIFO lots for a user.
	 *
	 * @return array{realized_total:float,per_security:array,securities_with_orders:int,open_lots:int,unmatched_qty:float}
	 */
	public function recompute(string $uid): array {
		// security id -> ext_id for labelling
		$label = [];
		foreach ($this->securities->findByUser($uid) as $s) {
			$label[(int) $s->getId()] = (string) $s->getExtId();
		}

		// group filled orders by security, preserving date order (mapper sorts asc)
		$bySec = [];
		foreach ($this->orders->findByUser($uid) as $o) {
			if (strtolower((string) $o->getStatus()) !== 'filled') {
				continue;
			}
			$sid = (int) $o->getSecurityId();
			if ($sid === 0) {
				continue;
			}
			$side = $this->normalizeSide((string) $o->getSide());
			if ($side === '') {
				continue;
			}
			$bySec[$sid][] = [
				'side'    => $side,
				'qty'     => (float) $o->getQuantity(),
				'price'   => (float) $o->getPrice(),
				'fees'    => (float) $o->getFees(),
				'date'    => (string) $o->getExecutedAt(),
				'orderId' => (int) $o->getId(),
				'account' => (string) $o->getAccountKey(),
			];
		}

		$this->lots->deleteByUser($uid);

		$realizedTotal = 0.0;
		$openCount = 0;
		$unmatched = 0.0;
		$perSecurity = [];
		foreach ($bySec as $sid => $orders) {
			$accountKey = $orders[0]['account'] ?? '';
			$r = FifoLots::compute($orders);
			$realizedTotal += $r['realized_pl'];
			$unmatched += $r['unmatched_sell_qty'];
			foreach ($r['open_lots'] as $ol) {
				$lot = new Lot();
				$lot->setUserId($uid);
				$lot->setSecurityId($sid);
				$lot->setAccountKey($accountKey);
				$lot->setOpenOrderId((int) $ol['open_order_id']);
				$lot->setQuantityOrig($this->num($ol['qty_orig']));
				$lot->setQuantityOpen($this->num($ol['qty_open']));
				$lot->setCostBasis($this->num($ol['cost_basis']));
				$lot->setOpenedAt((string) $ol['opened_at']);
				$this->lots->insert($lot);
				$openCount++;
			}
			if (abs($r['realized_pl']) > 0.005 || $r['sells'] > 0) {
				$perSecurity[] = [
					'ext_id'      => $label[$sid] ?? (string) $sid,
					'realized_pl' => $r['realized_pl'],
					'buys'        => $r['buys'],
					'sells'       => $r['sells'],
					'unmatched'   => $r['unmatched_sell_qty'],
				];
			}
		}
		usort($perSecurity, static function ($a, $b) {
			return abs($b['realized_pl']) <=> abs($a['realized_pl']);
		});

		return [
			'realized_total'          => $realizedTotal,
			'per_security'            => $perSecurity,
			'securities_with_orders'  => count($bySec),
			'open_lots'               => $openCount,
			'unmatched_qty'           => $unmatched,
		];
	}

	private function normalizeSide(string $side): string {
		$s = strtolower(trim($side));
		if ($s === '') {
			return '';
		}
		if (strpos($s, 'sell') !== false || strpos($s, 'venta') !== false || $s[0] === 'v') {
			return 'sell';
		}
		if (strpos($s, 'buy') !== false || strpos($s, 'compra') !== false || $s[0] === 'b' || $s[0] === 'c') {
			return 'buy';
		}
		return '';
	}

	private function num($v): string {
		$s = rtrim(rtrim(sprintf('%.6f', (float) $v), '0'), '.');
		return $s === '' || $s === '-0' ? '0' : $s;
	}
}
