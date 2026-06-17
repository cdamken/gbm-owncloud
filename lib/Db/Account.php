<?php
/** Account row (layer 1 · state). One per broker contract per user. */

namespace OCA\Gbm\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getAccountKey()
 * @method void setAccountKey(string $accountKey)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getCashAmount()
 * @method void setCashAmount(string $cashAmount)
 * @method string getTotalValue()
 * @method void setTotalValue(string $totalValue)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Account extends Entity {
	protected $userId;
	protected $accountKey;
	protected $name;
	protected $type;
	protected $currency;
	protected $cashAmount;
	protected $totalValue;
	protected $updatedAt;
}
