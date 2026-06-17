<?php
/** PortfolioSnapshotMapper — layer 2 history. Scoped by user_id. */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class PortfolioSnapshotMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_portfolio_snapshots', PortfolioSnapshot::class);
	}

	/**
	 * Full history, oldest first — the time series the charts consume.
	 * @return PortfolioSnapshot[]
	 */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_portfolio_snapshots` '
			. 'WHERE `user_id` = ? ORDER BY `captured_on` ASC';
		return $this->findEntities($sql, [$userId]);
	}

	/** Lookup one day, for the idempotent daily upsert. */
	public function findByDate(string $userId, string $capturedOn): ?PortfolioSnapshot {
		$sql = 'SELECT * FROM `*PREFIX*gbm_portfolio_snapshots` '
			. 'WHERE `user_id` = ? AND `captured_on` = ?';
		try {
			return $this->findEntity($sql, [$userId, $capturedOn]);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
