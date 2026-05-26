<?php
/**
 * Routes for the GBM Portfolio app.
 *
 *   page#index         GET  /                     → portfolio dashboard
 *   page#orders        GET  /orders               → blotter (filled orders)
 *   page#ordersAll     GET  /orders_all           → blotter (any status)
 *   page#dividends     GET  /dividends            → cash distributions
 *   page#transactions  GET  /transactions         → full ledger ("Libro Diario")
 *   api#data           GET  /data/{type}          → per-user JSON
 *   api#getConfig      GET  /api/config           → { configured, email }
 *   api#setConfig      POST /api/config           → save { email, password }
 *   api#update         POST /api/update           → trigger refresh
 */

return [
	'routes' => [
		['name' => 'page#index',        'url' => '/',              'verb' => 'GET'],
		['name' => 'page#orders',       'url' => '/orders',        'verb' => 'GET'],
		['name' => 'page#ordersAll',    'url' => '/orders_all',    'verb' => 'GET'],
		['name' => 'page#dividends',    'url' => '/dividends',     'verb' => 'GET'],
		['name' => 'page#transactions', 'url' => '/transactions',  'verb' => 'GET'],
		['name' => 'api#data',          'url' => '/data/{type}',   'verb' => 'GET'],
		['name' => 'api#getConfig',     'url' => '/api/config',    'verb' => 'GET'],
		['name' => 'api#setConfig',     'url' => '/api/config',    'verb' => 'POST'],
		['name' => 'api#update',        'url' => '/api/update',    'verb' => 'POST'],
	],
];
