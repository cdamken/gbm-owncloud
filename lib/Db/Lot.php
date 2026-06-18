<?php
/** FIFO lot (layer 5). Derived from orders; open lots persisted per recompute. */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getSecurityId()
 * @method void setSecurityId(int $securityId)
 * @method string getAccountKey()
 * @method void setAccountKey(string $accountKey)
 * @method int getOpenOrderId()
 * @method void setOpenOrderId(int $openOrderId)
 * @method string getQuantityOrig()
 * @method void setQuantityOrig(string $quantityOrig)
 * @method string getQuantityOpen()
 * @method void setQuantityOpen(string $quantityOpen)
 * @method string getCostBasis()
 * @method void setCostBasis(string $costBasis)
 * @method string getOpenedAt()
 * @method void setOpenedAt(string $openedAt)
 * @method string getClosedAt()
 * @method void setClosedAt(string $closedAt)
 */
class Lot extends Entity {
	protected $userId;
	protected $securityId;
	protected $accountKey;
	protected $openOrderId;
	protected $quantityOrig;
	protected $quantityOpen;
	protected $costBasis;
	protected $openedAt;
	protected $closedAt;

	public function __construct() {
		$this->addType('securityId', 'integer');
		$this->addType('openOrderId', 'integer');
	}
}
