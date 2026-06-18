<?php
/** LotMapper — layer 5. FIFO lots are derived: replaced wholesale per recompute. */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class LotMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_lots', Lot::class);
	}

	/** @return Lot[] */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_lots` WHERE `user_id` = ? ORDER BY `opened_at` ASC';
		return $this->findEntities($sql, [$userId]);
	}

	public function deleteByUser(string $userId): void {
		$this->execute('DELETE FROM `*PREFIX*gbm_lots` WHERE `user_id` = ?', [$userId]);
	}
}
