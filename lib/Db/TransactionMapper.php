<?php
/** TransactionMapper — layer 3 events. Scoped by user_id, deduped by external_id. */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class TransactionMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_transactions', Transaction::class);
	}

	/** @return Transaction[] */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_transactions` '
			. 'WHERE `user_id` = ? ORDER BY `booked_at` ASC';
		return $this->findEntities($sql, [$userId]);
	}

	/** Dedup lookup for the ingest upsert. */
	public function findByExternalId(string $userId, string $externalId): ?Transaction {
		$sql = 'SELECT * FROM `*PREFIX*gbm_transactions` WHERE `user_id` = ? AND `external_id` = ?';
		try {
			return $this->findEntity($sql, [$userId, $externalId]);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
