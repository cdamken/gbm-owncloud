<?php
/** Order event (layer 3). Deduped by (user_id, external_id). */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getExternalId()
 * @method void setExternalId(string $externalId)
 * @method string getAccountKey()
 * @method void setAccountKey(string $accountKey)
 * @method int getSecurityId()
 * @method void setSecurityId(int $securityId)
 * @method string getSide()
 * @method void setSide(string $side)
 * @method string getQuantity()
 * @method void setQuantity(string $quantity)
 * @method string getPrice()
 * @method void setPrice(string $price)
 * @method string getFees()
 * @method void setFees(string $fees)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getExecutedAt()
 * @method void setExecutedAt(string $executedAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 */
class Order extends Entity {
	protected $userId;
	protected $externalId;
	protected $accountKey;
	protected $securityId;
	protected $side;
	protected $quantity;
	protected $price;
	protected $fees;
	protected $currency;
	protected $executedAt;
	protected $status;

	public function __construct() {
		$this->addType('securityId', 'integer');
	}
}
