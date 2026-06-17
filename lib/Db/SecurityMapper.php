<?php
/**
 * SecurityMapper — layer 4 reference.
 *
 * ownCloud 10.13 has no QBMapper; we extend the legacy
 * OCP\AppFramework\Db\Mapper. Every query is scoped by user_id (the
 * per-user isolation boundary). Raw SQL uses the `*PREFIX*` table-prefix
 * token and positional `?` params, which the legacy Mapper binds safely.
 */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class SecurityMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_securities', Security::class);
	}

	/** @return Security[] */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_securities` WHERE `user_id` = ?';
		return $this->findEntities($sql, [$userId]);
	}

	/** Resolve by broker-native id (GBM issue_id / security_id). */
	public function findByExtId(string $userId, string $extId): ?Security {
		$sql = 'SELECT * FROM `*PREFIX*gbm_securities` WHERE `user_id` = ? AND `ext_id` = ?';
		try {
			return $this->findEntity($sql, [$userId, $extId]);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
