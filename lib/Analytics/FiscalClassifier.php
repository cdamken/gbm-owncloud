<?php
/**
 * Maps a raw GBM transaction to a fiscal class for the annual income report.
 * Pure — no OCP dependency; unit-tested in tests/php/. PHP 7.4 compatible.
 *
 * Realized capital gains are NOT classified here (they need FIFO lots and are
 * deferred pending the accountant). Sells therefore return 'none'.
 */

namespace OCA\Gbm\Analytics;

class FiscalClassifier {
	/** GBM `category` → fiscal class (the stable, data-driven mapping). */
	const BY_CATEGORY = [
		'dividend'        => 'dividend',
		'tax_withholding' => 'withholding',
		'repo_mature'     => 'interest',
	];

	/**
	 * @param array $tx one transaction dict with a 'category' key
	 * @return string one of: dividend|interest|withholding|none
	 */
	public static function classify(array $tx) {
		$cat = isset($tx['category']) ? strtolower(trim((string) $tx['category'])) : '';
		if (isset(self::BY_CATEGORY[$cat])) {
			return self::BY_CATEGORY[$cat];
		}
		if (strpos($cat, 'dividend') !== false) {
			return 'dividend';
		}
		if (strpos($cat, 'withhold') !== false || strpos($cat, 'isr') !== false) {
			return 'withholding';
		}
		if (strpos($cat, 'interes') !== false || strpos($cat, 'interest') !== false) {
			return 'interest';
		}
		return 'none';
	}

	/**
	 * Calendar year from a GBM date ("YYYY-MM-DD" or "YYYY-MM-DDT...").
	 * @param mixed $processDate
	 * @return int|null null when unparseable
	 */
	public static function fiscalYear($processDate) {
		$s = trim((string) $processDate);
		if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $s, $m)) {
			return (int) $m[1];
		}
		return null;
	}
}
