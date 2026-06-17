<?php
/** DividendMapper — layer 3 events. Scoped by user_id, deduped by external_id. */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class DividendMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_dividends', Dividend::class);
	}

	/** @return Dividend[] */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_dividends` '
			. 'WHERE `user_id` = ? ORDER BY `paid_at` ASC';
		return $this->findEntities($sql, [$userId]);
	}

	/** Dedup lookup for the ingest upsert. */
	public function findByExternalId(string $userId, string $externalId): ?Dividend {
		$sql = 'SELECT * FROM `*PREFIX*gbm_dividends` WHERE `user_id` = ? AND `external_id` = ?';
		try {
			return $this->findEntity($sql, [$userId, $externalId]);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
