<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/PortfolioAnalytics.php';

use OCA\Gbm\Analytics\PortfolioAnalytics;

// Holdings spanning all five asset classes + both regions, plus a zero and an
// unrecognized class that must be dropped from ALL dimensions.
$holdings = [
    ['asset_class' => 'equity',         'region' => 'MX',      'market_value' => 100.0],
    ['asset_class' => 'equity_sic',     'region' => 'foreign', 'market_value' => 50.0],
    ['asset_class' => 'equity_foreign', 'region' => 'foreign', 'market_value' => 30.0],
    ['asset_class' => 'equity_fund',    'region' => 'MX',      'market_value' => 20.0],
    ['asset_class' => 'debt_fund',      'region' => 'MX',      'market_value' => 40.0],
    ['asset_class' => 'equity',         'region' => 'MX',      'market_value' => 0.0],    // zero -> skipped
    ['asset_class' => 'mystery',        'region' => 'foreign', 'market_value' => 999.0],  // unknown -> dropped everywhere
];
$out = PortfolioAnalytics::allocation($holdings, 10.0); // + 10 cash

// helper: pull a dimension into key=>value for easy asserts
$asMap = function (array $list) {
    $m = [];
    foreach ($list as $r) { $m[$r['key']] = $r['value']; }
    return $m;
};
$sum = function (array $list) {
    $t = 0.0;
    foreach ($list as $r) { $t += $r['value']; }
    return $t;
};

// market: 5 recognized buckets + efectivo = 6
assert_eq(6, count($out['market']), 'market has 6 buckets (5 classes + cash)');
$mk = $asMap($out['market']);
assert_close(100.0, $mk['mercado_capitales'], 'market capitales');
assert_close(50.0,  $mk['mercados_globales_sic'], 'market SIC');
assert_close(30.0,  $mk['mercado_extranjero'], 'market extranjero');
assert_close(20.0,  $mk['sociedades_inversion_comun'], 'market comun');
assert_close(40.0,  $mk['sociedades_inversion_deuda'], 'market deuda');
assert_close(10.0,  $mk['efectivo'], 'market efectivo = cash');

// class: renta_variable (100+50+30+20), renta_fija (40), efectivo (10)
assert_eq(3, count($out['class']), 'class has 3 buckets');
$cl = $asMap($out['class']);
assert_close(200.0, $cl['renta_variable'], 'renta_variable = equities + comun fund');
assert_close(40.0,  $cl['renta_fija'], 'renta_fija = debt fund');
assert_close(10.0,  $cl['efectivo'], 'class efectivo = cash');

// region: mx (100+20+40+10 cash), foreign (50+30)
assert_eq(2, count($out['region']), 'region has 2 buckets');
$rg = $asMap($out['region']);
assert_close(170.0, $rg['mx'], 'region mx = MX holdings + cash');
assert_close(80.0,  $rg['foreign'], 'region foreign = SIC + extranjero');

// equal totals across dimensions (unknown 'mystery' dropped everywhere)
assert_close(250.0, $sum($out['market']), 'market total = 250');
assert_close(250.0, $sum($out['class']),  'class total = 250');
assert_close(250.0, $sum($out['region']), 'region total = 250');

// descending sort within a dimension
assert_true($out['market'][0]['value'] >= $out['market'][1]['value'], 'market sorted desc');
assert_eq('renta_variable', $out['class'][0]['key'], 'class sorted desc (renta_variable first)');

// empty portfolio + zero cash -> all dimensions empty
$empty = PortfolioAnalytics::allocation([], 0.0);
assert_eq(0, count($empty['market']), 'empty market');
assert_eq(0, count($empty['class']),  'empty class');
assert_eq(0, count($empty['region']), 'empty region');

// cash-only -> efectivo/mx buckets only
$cashOnly = PortfolioAnalytics::allocation([], 5.0);
assert_eq(1, count($cashOnly['market']), 'cash-only market = 1 bucket');
assert_eq('efectivo', $cashOnly['market'][0]['key'], 'cash-only market efectivo');
assert_eq('mx', $cashOnly['region'][0]['key'], 'cash-only region mx');
