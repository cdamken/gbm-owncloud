<?php
/**
 * Money-weighted return (XIRR), annualized, from dated cash flows. Pure,
 * PHP 7.4. Ported from the JS solver in js/dashboard.js (Newton-Raphson with
 * a bisection fallback). Sign convention: money IN = negative, value OUT /
 * terminal = positive.
 */

namespace OCA\Gbm\Analytics;

class Xirr {
	/**
	 * @param array $flows list of ['date'=>string(ISO),'amount'=>float]
	 * @return float|null null when <2 parseable flows, all same-sign, or no convergence
	 */
	public static function compute(array $flows, float $tol = 1e-7) {
		$parsed = [];
		foreach ($flows as $f) {
			$ts = strtotime((string) ($f['date'] ?? ''));
			if ($ts === false) {
				continue;
			}
			$parsed[] = ['t' => $ts, 'a' => (float) ($f['amount'] ?? 0.0)];
		}
		if (count($parsed) < 2) {
			return null;
		}
		usort($parsed, function ($x, $y) {
			return $x['t'] <=> $y['t'];
		});
		$t0 = $parsed[0]['t'];
		$days = [];
		$amounts = [];
		foreach ($parsed as $p) {
			$days[] = (int) floor(($p['t'] - $t0) / 86400);
			$amounts[] = $p['a'];
		}
		$allPos = true;
		$allNeg = true;
		foreach ($amounts as $a) {
			if ($a < 0) { $allPos = false; }
			if ($a > 0) { $allNeg = false; }
		}
		if ($allPos || $allNeg) {
			return null;
		}
		$n = count($amounts);
		foreach ([0.10, 0.0, -0.10, 0.30, -0.30, 0.50] as $guess) {
			$rate = $guess;
			$ok = true;
			for ($it = 0; $it < 80; $it++) {
				$npv = self::npv($rate, $days, $amounts);
				$dnpv = 0.0;
				for ($i = 0; $i < $n; $i++) {
					$d = $days[$i];
					$dnpv += (-$d / 365.0) * $amounts[$i] / pow(1 + $rate, $d / 365.0 + 1);
				}
				if (!is_finite($npv) || !is_finite($dnpv) || abs($dnpv) < 1e-12) {
					$ok = false;
					break;
				}
				$newRate = $rate - $npv / $dnpv;
				if ($newRate <= -0.999) {
					$newRate = -0.99;
				}
				if (abs($newRate - $rate) < $tol) {
					return $newRate;
				}
				$rate = $newRate;
			}
			if ($ok) {
				continue;
			}
		}
		$lo = -0.95;
		$hi = 10.0;
		$fLo = self::npv($lo, $days, $amounts);
		$fHi = self::npv($hi, $days, $amounts);
		if (!is_finite($fLo) || !is_finite($fHi) || $fLo * $fHi > 0) {
			return null;
		}
		for ($it = 0; $it < 120; $it++) {
			$mid = ($lo + $hi) / 2;
			$fMid = self::npv($mid, $days, $amounts);
			if (!is_finite($fMid)) {
				return null;
			}
			if (abs($fMid) < $tol || abs($hi - $lo) < $tol) {
				return $mid;
			}
			if ($fLo * $fMid < 0) {
				$hi = $mid;
				$fHi = $fMid;
			} else {
				$lo = $mid;
				$fLo = $fMid;
			}
		}
		return ($lo + $hi) / 2;
	}

	private static function npv(float $rate, array $days, array $amounts): float {
		$s = 0.0;
		$n = count($amounts);
		for ($i = 0; $i < $n; $i++) {
			$s += $amounts[$i] / pow(1 + $rate, $days[$i] / 365.0);
		}
		return $s;
	}
}
