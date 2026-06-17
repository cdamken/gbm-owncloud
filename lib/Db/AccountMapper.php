<?php
/** AccountMapper — layer 1 state. Scoped by user_id. */

namespace OCA\Gbm\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Mapper;
use OCP\IDBConnection;

class AccountMapper extends Mapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'gbm_accounts', Account::class);
	}

	/** @return Account[] */
	public function findByUser(string $userId): array {
		$sql = 'SELECT * FROM `*PREFIX*gbm_accounts` WHERE `user_id` = ?';
		return $this->findEntities($sql, [$userId]);
	}

	public function findByKey(string $userId, string $accountKey): ?Account {
		$sql = 'SELECT * FROM `*PREFIX*gbm_accounts` WHERE `user_id` = ? AND `account_key` = ?';
		try {
			return $this->findEntity($sql, [$userId, $accountKey]);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
