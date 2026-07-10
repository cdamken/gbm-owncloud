<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/PortfolioReconcile.php';

use OCA\Gbm\Analytics\PortfolioReconcile;

// Account 1 (id 1): 2 holdings; Account 2 (id 2): 1 holding; Account 3 (id 3): no holdings, cash only.
$holdings = [
    ['accountId' => 1, 'qty' => 10.0, 'avgCost' => 10.0, 'marketValue' => 130.0], // cost 100, mv 130
    ['accountId' => 1, 'qty' => 5.0,  'avgCost' => 20.0, 'marketValue' => 90.0],   // cost 100, mv 90
    ['accountId' => 2, 'qty' => 4.0,  'avgCost' => 50.0, 'marketValue' => 250.0],  // cost 200, mv 250
];
$accounts = [
    ['id' => 1, 'key' => 'EP01', 'name' => 'Personal', 'cash' => 0.0],
    ['id' => 2, 'key' => 'EP02', 'name' => 'Trading',  'cash' => 0.0],
    ['id' => 3, 'key' => 'EP03', 'name' => 'Smart Cash', 'cash' => 500.0],
];
$m = PortfolioReconcile::build($holdings, $accounts, 400.0, 25.0);

assert_close(470.0, $m['market_value'], 'market_value = 130+90+250');
assert_close(400.0, $m['cost_basis'], 'cost_basis = 100+100+200');
assert_close(70.0,  $m['unrealized_pl'], 'unrealized = 470-400');
assert_close(17.5,  $m['unrealized_pct'], 'unrealized% = 70/400*100');
assert_close(500.0, $m['cash'], 'cash = 500');
assert_close(970.0, $m['total_value'], 'total = market 470 + cash 500');
assert_close(400.0, $m['net_contributions'], 'net contributions passthrough');
assert_close(25.0,  $m['income_net'], 'income passthrough');
assert_eq(null, $m['realized_pl'], 'realized deferred -> null');
assert_eq(3, $m['positions_count'], 'positions = holdings rows');

// per-account: unrealized sums back to the header
$acc = [];
foreach ($m['accounts'] as $a) { $acc[$a['key']] = $a; }
assert_eq(3, count($m['accounts']), 'all 3 accounts present (incl. cash-only)');
assert_close(20.0,  $acc['EP01']['unrealized_pl'], 'EP01 unrealized = (130-100)+(90-100)=20');
assert_close(50.0,  $acc['EP02']['unrealized_pl'], 'EP02 unrealized = 250-200');
assert_close(0.0,   $acc['EP03']['unrealized_pl'], 'cash-only account unrealized = 0 (not API plus_minus)');
assert_close(500.0, $acc['EP03']['value'], 'cash-only account value = its cash');
assert_close(220.0, $acc['EP01']['value'], 'EP01 value = holdings mv 220 + cash 0');
$sum = $acc['EP01']['unrealized_pl'] + $acc['EP02']['unrealized_pl'] + $acc['EP03']['unrealized_pl'];
assert_close($m['unrealized_pl'], $sum, 'sum of per-account unrealized == header unrealized');

// empty portfolio -> zeros, no div-by-zero
$e = PortfolioReconcile::build([], [], 0.0, 0.0);
assert_close(0.0, $e['unrealized_pct'], 'empty -> 0% not div-by-zero');
assert_eq(0, $e['positions_count'], 'empty -> 0 positions');
