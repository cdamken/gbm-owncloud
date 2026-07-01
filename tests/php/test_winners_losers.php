<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/PortfolioAnalytics.php';

use OCA\Gbm\Analytics\PortfolioAnalytics;

// perStock-shaped input: a winner (price up + dividends) and a loser.
$perStock = [
    [
        'security_id' => 1, 'ext_id' => 'AAPL', 'name' => 'Apple', 'asset_class' => 'stock',
        'quantity' => 10.0, 'avg_cost' => 10.0, 'cost_basis' => 100.0, 'market_value' => 130.0,
        'unrealized_pl' => 30.0, 'unrealized_pct' => 30.0, 'dividends' => 20.0, 'yield_on_cost_pct' => 20.0,
    ],
    [
        'security_id' => 2, 'ext_id' => 'XYZ', 'name' => 'Xyz', 'asset_class' => 'stock',
        'quantity' => 5.0, 'avg_cost' => 40.0, 'cost_basis' => 200.0, 'market_value' => 150.0,
        'unrealized_pl' => -50.0, 'unrealized_pct' => -25.0, 'dividends' => 0.0, 'yield_on_cost_pct' => 0.0,
    ],
];
$out = PortfolioAnalytics::winnersLosers($perStock);
assert_eq(2, count($out), 'one row per holding');
assert_eq('AAPL', $out[0]['ext_id'], 'best return% ranked first');
assert_close(50.0, $out[0]['total_return'], 'AAPL total return = 30 upl + 20 div');
assert_close(50.0, $out[0]['total_return_pct'], 'AAPL total return% = 50/100*100');
assert_eq('XYZ', $out[1]['ext_id'], 'loser ranked last');
assert_close(-50.0, $out[1]['total_return'], 'XYZ total return = -50 + 0');
assert_close(-25.0, $out[1]['total_return_pct'], 'XYZ total return% = -50/200*100');

// zero cost basis -> 0% (no divide-by-zero).
$zero = PortfolioAnalytics::winnersLosers([
    [
        'security_id' => 3, 'ext_id' => 'Z', 'name' => 'Z', 'asset_class' => '',
        'quantity' => 0.0, 'avg_cost' => 0.0, 'cost_basis' => 0.0, 'market_value' => 0.0,
        'unrealized_pl' => 0.0, 'unrealized_pct' => 0.0, 'dividends' => 0.0, 'yield_on_cost_pct' => 0.0,
    ],
]);
assert_close(0.0, $zero[0]['total_return_pct'], 'zero cost -> 0% not div-by-zero');

// empty input -> empty output (no crash).
assert_eq(0, count(PortfolioAnalytics::winnersLosers([])), 'empty in -> empty out');
