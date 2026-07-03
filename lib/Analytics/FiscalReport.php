<?php
/**
 * Aggregates classified transactions into a per-calendar-year income summary
 * (dividends, interest, withholdings, net). Pure, PHP 7.4 compatible.
 *
 * Realized capital gains / ISR-on-gains are NOT computed here — deferred,
 * pending the accountant. Figures are informational; GBM's constancia fiscal
 * is authoritative.
 */

namespace OCA\Gbm\Analytics;

class FiscalReport {
	/**
	 * @param array $rows list of ['fiscal_class'=>string,'fiscal_year'=>int,'amount'=>float]
	 * @return array list (newest year first) of
	 *   ['year'=>int,'dividends'=>float,'interest'=>float,'withholding'=>float,'net'=>float]
	 */
	public static function build(array $rows) {
		$byYear = [];
		foreach ($rows as $r) {
			$year = isset($r['fiscal_year']) ? (int) $r['fiscal_year'] : 0;
			if ($year === 0) {
				continue;
			}
			if (!isset($byYear[$year])) {
				$byYear[$year] = [
					'year' => $year, 'dividends' => 0.0,
					'interest' => 0.0, 'withholding' => 0.0,
				];
			}
			$amt = isset($r['amount']) ? (float) $r['amount'] : 0.0;
			$class = isset($r['fiscal_class']) ? (string) $r['fiscal_class'] : 'none';
			if ($class === 'dividend') {
				$byYear[$year]['dividends'] += $amt;
			} elseif ($class === 'interest') {
				$byYear[$year]['interest'] += $amt;
			} elseif ($class === 'withholding') {
				$byYear[$year]['withholding'] += abs($amt);
			}
		}
		$out = [];
		foreach ($byYear as $row) {
			$row['net'] = $row['dividends'] + $row['interest'] - $row['withholding'];
			$out[] = $row;
		}
		usort($out, function ($a, $b) {
			return $b['year'] - $a['year'];
		});
		return $out;
	}
}
