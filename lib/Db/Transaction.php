<?php
/** Cash/book transaction (layer 3). Deduped by (user_id, external_id). */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getExternalId()
 * @method void setExternalId(string $externalId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getRawType()
 * @method void setRawType(string $rawType)
 * @method string getAmount()
 * @method void setAmount(string $amount)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method int getSecurityId()
 * @method void setSecurityId(int $securityId)
 * @method string getBookedAt()
 * @method void setBookedAt(string $bookedAt)
 */
class Transaction extends Entity {
	protected $userId;
	protected $externalId;
	protected $type;
	protected $rawType;
	protected $amount;
	protected $currency;
	protected $securityId;
	protected $bookedAt;

	public function __construct() {
		$this->addType('securityId', 'integer');
	}
}
