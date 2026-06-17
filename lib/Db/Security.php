<?php
/**
 * Security reference row (layer 4). One per ISIN per user.
 *
 * Untyped properties on purpose: the ownCloud 10 Entity hydrator assigns
 * via magic setters and this stays identical on PHP 7.4 and 8.x (typed
 * properties would throw on a null column). Money/dates are text columns
 * → kept as strings; no addType for them.
 */

namespace OCA\GbmNext\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getIsin()
 * @method void setIsin(string $isin)
 * @method string getTicker()
 * @method void setTicker(string $ticker)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getAssetClass()
 * @method void setAssetClass(string $assetClass)
 * @method string getRegion()
 * @method void setRegion(string $region)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 */
class Security extends Entity {
	protected $userId;
	protected $isin;
	protected $ticker;
	protected $name;
	protected $assetClass;
	protected $region;
	protected $currency;
}
