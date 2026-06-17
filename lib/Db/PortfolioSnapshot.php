<?php
/** Daily portfolio snapshot (layer 2 · history). One per day per user. */

namespace OCA\Gbm\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getCapturedOn()
 * @method void setCapturedOn(string $capturedOn)
 * @method string getTotalValue()
 * @method void setTotalValue(string $totalValue)
 * @method string getTotalCost()
 * @method void setTotalCost(string $totalCost)
 * @method string getCash()
 * @method void setCash(string $cash)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getSource()
 * @method void setSource(string $source)
 */
class PortfolioSnapshot extends Entity {
	protected $userId;
	protected $capturedOn;
	protected $totalValue;
	protected $totalCost;
	protected $cash;
	protected $currency;
	protected $source;
}
