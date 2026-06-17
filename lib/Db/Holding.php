<?php
/** Holding row (layer 1 · state). One per security per account per user. */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int getSecurityId()
 * @method void setSecurityId(int $securityId)
 * @method string getQuantity()
 * @method void setQuantity(string $quantity)
 * @method string getAvgCost()
 * @method void setAvgCost(string $avgCost)
 * @method string getLastPrice()
 * @method void setLastPrice(string $lastPrice)
 * @method string getClosePrice()
 * @method void setClosePrice(string $closePrice)
 * @method string getMarketValue()
 * @method void setMarketValue(string $marketValue)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Holding extends Entity {
	protected $userId;
	protected $accountId;
	protected $securityId;
	protected $quantity;
	protected $avgCost;
	protected $lastPrice;
	protected $closePrice;
	protected $marketValue;
	protected $currency;
	protected $updatedAt;

	public function __construct() {
		$this->addType('accountId', 'integer');
		$this->addType('securityId', 'integer');
	}
}
