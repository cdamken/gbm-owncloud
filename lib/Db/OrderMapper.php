<?php
/** OrderMapper — layer 3 events. Scoped by user_id, deduped by external_id. */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class OrderMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_orders', Order::class);
	}

	/** @return Order[] */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_orders` '
			. 'WHERE `user_id` = ? ORDER BY `executed_at` ASC';
		return $this->findEntities($sql, [$userId]);
	}

	/** Dedup lookup for the ingest upsert. */
	public function findByExternalId(string $userId, string $externalId): ?Order {
		$sql = 'SELECT * FROM `*PREFIX*gbm_orders` WHERE `user_id` = ? AND `external_id` = ?';
		try {
			return $this->findEntity($sql, [$userId, $externalId]);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
