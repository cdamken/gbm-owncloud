<?php
/**
 * Renders the two HTML pages (portfolio + orders).
 *
 * No data is inlined — both pages fetch JSON via the api#data route, which
 * is what isolates one user from another at request time.
 */

namespace OCA\Gbm\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller {

	private $urlGenerator;

	public function __construct(string $appName, IRequest $request, IURLGenerator $urlGenerator) {
		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse {
		return $this->renderTemplate('main');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function orders(): TemplateResponse {
		return $this->renderTemplate('orders');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function ordersAll(): TemplateResponse {
		return $this->renderTemplate('orders_all');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function dividends(): TemplateResponse {
		return $this->renderTemplate('dividends');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function transactions(): TemplateResponse {
		return $this->renderTemplate('transactions');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function glossary(): TemplateResponse {
		return $this->renderTemplate('glossary');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function settings(): TemplateResponse {
		return $this->renderTemplate('settings');
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function analysis(): TemplateResponse {
		return $this->renderTemplate('analysis');
	}

	private function renderTemplate(string $template): TemplateResponse {
		\OCP\Util::addStyle($this->appName, 'dashboard');
		// One JS module per template. 'main' → dashboard.js; the others map 1:1.
		$scriptMap = [
			'main'         => 'dashboard',
			'orders'       => 'orders',
			'orders_all'   => 'orders_all',
			'dividends'    => 'dividends',
			'transactions' => 'transactions',
			'glossary'     => 'glossary',
			'settings'     => 'settings',
			'analysis'     => 'analysis',
		];
		// _shared.js injects the sticky top-bar (brand + 7 tabs + Actualizar
		// button). Must load BEFORE the per-page JS so #update-btn exists
		// in the DOM when each page's DOMContentLoaded handler queries it.
		\OCP\Util::addScript($this->appName, '_shared');
		// Chart.js (vendored — CSP forbids CDN) is needed by analysis (the
		// cost-basis/benchmark chart) and dividends (the monthly bar). Load
		// it BEFORE the per-page script so window.Chart is defined when the
		// chart helpers fire on DOMContentLoaded.
		if (in_array($template, ['analysis', 'dividends'], true)) {
			\OCP\Util::addScript($this->appName, 'vendor/chart.umd.min');
		}
		// update_flow.js makes the "🔄 Actualizar" button work on every
		// page (not just main.php). Auto-injects the TOTP modal + toast
		// HTML when the page doesn't already carry it. main.php opts out
		// via data-update-flow-owner="page" — dashboard.js handles the
		// flow there itself (verbatim port from gbm-dashboard upstream).
		\OCP\Util::addScript($this->appName, 'update_flow');
		\OCP\Util::addScript($this->appName, $scriptMap[$template] ?? 'dashboard');

		// Pass route URLs to the JS so it doesn't hardcode paths.
		$params = [
			'routes' => [
				'index'         => $this->urlGenerator->linkToRoute('gbm.page.index'),
				'orders'        => $this->urlGenerator->linkToRoute('gbm.page.orders'),
				'orders_all'    => $this->urlGenerator->linkToRoute('gbm.page.ordersAll'),
				'dividends'     => $this->urlGenerator->linkToRoute('gbm.page.dividends'),
				'transactions'  => $this->urlGenerator->linkToRoute('gbm.page.transactions'),
				'glossary'      => $this->urlGenerator->linkToRoute('gbm.page.glossary'),
				'settings'      => $this->urlGenerator->linkToRoute('gbm.page.settings'),
				'analysis'      => $this->urlGenerator->linkToRoute('gbm.page.analysis'),
				'data'          => $this->urlGenerator->linkToRoute('gbm.api.data',      ['type' => '__TYPE__']),
				'config'        => $this->urlGenerator->linkToRoute('gbm.api.getConfig'),
				'update'        => $this->urlGenerator->linkToRoute('gbm.api.update'),
				'settings_api'  => $this->urlGenerator->linkToRoute('gbm.api.settingsGet'),
				'reset'         => $this->urlGenerator->linkToRoute('gbm.api.reset'),
				'export_csv'    => $this->urlGenerator->linkToRoute('gbm.api.exportTransactionsCsv'),
				'export_page'   => $this->urlGenerator->linkToRoute('gbm.api.exportPageCsv', ['kind' => '__KIND__']),
				'benchmark'     => $this->urlGenerator->linkToRoute('gbm.api.benchmark', ['symbol' => '__SYMBOL__']),
			],
		];

		$response = new TemplateResponse($this->appName, $template, $params);
		$csp = new ContentSecurityPolicy();
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
