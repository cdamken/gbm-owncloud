<?php
/**
 * Application class — registers the navigation entry.
 *
 * GbmService is NOT registered as a custom binding here: the ownCloud 10 DI
 * container auto-wires it from its `IUserSession + IConfig + ICrypto`
 * constructor. Registering a closure with `registerService(GbmService::class,
 * ...)` doesn't override that auto-wiring reliably — the container resolves
 * by class name first and only consults closures for non-class service ids.
 * To keep per-user isolation, GbmService resolves the userId lazily from
 * IUserSession (see GbmService::userId()).
 */

namespace OCA\GbmNext;

use OCP\AppFramework\App;
use OCP\INavigationManager;
use OCP\IURLGenerator;

class Application extends App {

	const APPID = 'gbm_next';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APPID, $urlParams);

		$container = $this->getContainer();

		$container->query(INavigationManager::class)->add(function () use ($container) {
			$url = $container->query(IURLGenerator::class);
			return [
				'id'    => self::APPID,
				'order' => 80,
				'href'  => $url->linkToRoute('gbm_next.page.index'),
				'icon'  => $url->imagePath(self::APPID, 'app.svg'),
				'name'  => 'GBM Portfolio (next)',
			];
		});
	}
}
