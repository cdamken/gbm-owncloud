<?php
/**
 * Income-side fiscal report, DB-backed. Loads transactions, classifies them on
 * the fly (FiscalClassifier — NO schema dependency), and returns a per-year
 * summary (FiscalReport) plus an itemized detail list. Per-user scoped.
 *
 * Single source: dividends/interest/withholding all come from gbm_transactions,
 * so withholding is never double-counted against gbm_dividends.
 */

namespace OCA\Gbm\Service;

use OCA\Gbm\Analytics\FiscalClassifier;
use OCA\Gbm\Analytics\FiscalReport;
use OCA\Gbm\Db\SecurityMapper;
use OCA\Gbm\Db\TransactionMapper;

class FiscalService {
	/** @var TransactionMapper */
	private $transactions;
	/** @var SecurityMapper */
	private $securities;

	public function __construct(TransactionMapper $transactions, SecurityMapper $securities) {
		$this->transactions = $transactions;
		$this->securities = $securities;
	}

	/**
	 * @return array{summary:array,detail:array}
	 */
	public function perUser(string $uid): array {
		// security DB id -> issuer name (Transaction::getSecurityId() is that int FK).
		$nameById = [];
		foreach ($this->securities->findByUser($uid) as $s) {
			$nameById[(int) $s->getId()] = (string) $s->getName();
		}

		$rows = [];
		$detail = [];
		foreach ($this->transactions->findByUser($uid) as $t) {
			$class = FiscalClassifier::classify(['category' => (string) $t->getType()]);
			if ($class === 'none') {
				continue;
			}
			$year = FiscalClassifier::fiscalYear($t->getBookedAt());
			if ($year === null) {
				continue;
			}
			$amount = $this->f($t->getAmount());
			$rows[] = [
				'fiscal_class' => $class,
				'fiscal_year'  => $year,
				'amount'       => $amount,
			];
			$detail[] = [
				'date'     => substr((string) $t->getBookedAt(), 0, 10),
				'year'     => $year,
				'class'    => $class,
				'security' => $nameById[(int) $t->getSecurityId()] ?? '',
				'amount'   => $amount,
			];
		}
		return [
			'summary' => FiscalReport::build($rows),
			'detail'  => $detail,
		];
	}

	/** Parse an exact-decimal text amount to float at the service boundary. */
	private function f($v): float {
		return (float) (is_string($v) ? str_replace(',', '', $v) : $v);
	}
}
