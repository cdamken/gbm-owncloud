<?php
/**
 * Portafolio landing money model, DB-backed. Loads current holdings, accounts,
 * and transactions (per-user), derives net contributions (external flows only)
 * and net income (dividends+interest−withholding via FiscalClassifier), and
 * delegates the math to the pure PortfolioReconcile + Xirr. Single source for
 * the landing's headline + account cards.
 */

namespace OCA\Gbm\Service;

use OCA\Gbm\Analytics\FiscalClassifier;
use OCA\Gbm\Analytics\PortfolioReconcile;
use OCA\Gbm\Analytics\Xirr;
use OCA\Gbm\Db\AccountMapper;
use OCA\Gbm\Db\HoldingMapper;
use OCA\Gbm\Db\TransactionMapper;

class SummaryService {
	/** @var HoldingMapper */
	private $holdings;
	/** @var AccountMapper */
	private $accounts;
	/** @var TransactionMapper */
	private $transactions;

	public function __construct(HoldingMapper $holdings, AccountMapper $accounts, TransactionMapper $transactions) {
		$this->holdings = $holdings;
		$this->accounts = $accounts;
		$this->transactions = $transactions;
	}

	public function perUser(string $uid): array {
		$holdingRows = [];
		foreach ($this->holdings->findByUser($uid) as $h) {
			$holdingRows[] = [
				'accountId'   => (int) $h->getAccountId(),
				'qty'         => $this->f($h->getQuantity()),
				'avgCost'     => $this->f($h->getAvgCost()),
				'marketValue' => $this->f($h->getMarketValue()),
			];
		}
		$accountRows = [];
		foreach ($this->accounts->findByUser($uid) as $a) {
			$accountRows[] = [
				'id'   => (int) $a->getId(),
				'key'  => (string) $a->getAccountKey(),
				'name' => (string) $a->getName(),
				'cash' => $this->f($a->getCashAmount()),
			];
		}

		// External cash flows (net contributions + XIRR) and net income, in one pass.
		$netContrib = 0.0;
		$income = 0.0;
		$flows = [];
		foreach ($this->transactions->findByUser($uid) as $t) {
			$type = (string) $t->getType();
			$amt = $this->f($t->getAmount());
			if ($type === 'external_deposit') {
				$netContrib += abs($amt);
				$flows[] = ['date' => (string) $t->getBookedAt(), 'amount' => -abs($amt)];
			} elseif ($type === 'external_withdrawal') {
				$netContrib -= abs($amt);
				$flows[] = ['date' => (string) $t->getBookedAt(), 'amount' => abs($amt)];
			}
			$class = FiscalClassifier::classify(['category' => $type]);
			if ($class === 'dividend' || $class === 'interest') {
				$income += $amt;
			} elseif ($class === 'withholding') {
				$income -= abs($amt);
			}
		}

		$model = PortfolioReconcile::build($holdingRows, $accountRows, $netContrib, $income);

		// XIRR: external flows + current total value as the terminal positive flow.
		$xirr = null;
		$status = 'insufficient_flows';
		if (!empty($flows) && $model['total_value'] > 0.0) {
			$flows[] = ['date' => date('Y-m-d'), 'amount' => (float) $model['total_value']];
			$xirr = Xirr::compute($flows);
			if ($xirr !== null) {
				$status = 'ok';
			}
		}
		$model['xirr'] = $xirr;
		$model['xirr_status'] = $status;
		return $model;
	}

	/** DB money is an exact decimal string (or null) — parse at this edge only. */
	private function f($v): float {
		return $v === null || $v === '' ? 0.0 : (float) $v;
	}
}
