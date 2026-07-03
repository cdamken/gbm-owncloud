<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/FiscalReport.php';

use OCA\Gbm\Analytics\FiscalReport;

$rows = [
    ['fiscal_class' => 'dividend',    'fiscal_year' => 2025, 'amount' => 100.0],
    ['fiscal_class' => 'dividend',    'fiscal_year' => 2025, 'amount' => 50.0],
    ['fiscal_class' => 'interest',    'fiscal_year' => 2025, 'amount' => 20.0],
    ['fiscal_class' => 'withholding', 'fiscal_year' => 2025, 'amount' => 10.0],
    ['fiscal_class' => 'dividend',    'fiscal_year' => 2026, 'amount' => 200.0],
    ['fiscal_class' => 'none',        'fiscal_year' => 2026, 'amount' => 9999.0],
    ['fiscal_class' => 'dividend',    'fiscal_year' => 0,    'amount' => 5.0],
];
$out = FiscalReport::build($rows);
assert_eq(2, count($out), 'two years (year 0 dropped)');
assert_eq(2026, $out[0]['year'], 'newest year first');
assert_close(200.0, $out[0]['dividends'], '2026 dividends');
assert_close(200.0, $out[0]['net'], '2026 net (none ignored)');
assert_close(150.0, $out[1]['dividends'], '2025 dividends summed');
assert_close(20.0,  $out[1]['interest'], '2025 interest');
assert_close(10.0,  $out[1]['withholding'], '2025 withholding');
assert_close(160.0, $out[1]['net'], '2025 net = 150+20-10');
