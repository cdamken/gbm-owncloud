<?php
/** Dividend event (layer 3). Deduped by (user_id, external_id). */

namespace OCA\Gbm\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getExternalId()
 * @method void setExternalId(string $externalId)
 * @method int getSecurityId()
 * @method void setSecurityId(int $securityId)
 * @method string getGross()
 * @method void setGross(string $gross)
 * @method string getNet()
 * @method void setNet(string $net)
 * @method string getTax()
 * @method void setTax(string $tax)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getPaidAt()
 * @method void setPaidAt(string $paidAt)
 */
class Dividend extends Entity {
	protected $userId;
	protected $externalId;
	protected $securityId;
	protected $gross;
	protected $net;
	protected $tax;
	protected $currency;
	protected $paidAt;

	public function __construct() {
		$this->addType('securityId', 'integer');
	}
}
