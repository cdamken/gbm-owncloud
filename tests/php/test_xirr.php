<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/Xirr.php';

use OCA\Gbm\Analytics\Xirr;

// -1000 in at t0, +1100 out one year later → ~10%
$r = Xirr::compute([
    ['date' => '2025-01-01', 'amount' => -1000.0],
    ['date' => '2026-01-01', 'amount' => 1100.0],
]);
assert_true($r !== null, 'xirr converges for a simple 2-flow case');
assert_close(0.10, $r, 'xirr ≈ 10%', 0.01);

// two contributions then a terminal value → converges, positive
$r2 = Xirr::compute([
    ['date' => '2025-01-01', 'amount' => -1000.0],
    ['date' => '2025-07-01', 'amount' => -500.0],
    ['date' => '2026-01-01', 'amount' => 1700.0],
]);
assert_true($r2 !== null && $r2 > 0.0, 'xirr converges positive with mid contribution');

// all same sign → null
assert_eq(null, Xirr::compute([
    ['date' => '2025-01-01', 'amount' => -100.0],
    ['date' => '2026-01-01', 'amount' => -50.0],
]), 'all-negative flows -> null');

// fewer than 2 flows → null
assert_eq(null, Xirr::compute([['date' => '2025-01-01', 'amount' => -100.0]]), '<2 flows -> null');

// unparseable dates drop below 2 → null
assert_eq(null, Xirr::compute([
    ['date' => '', 'amount' => -100.0],
    ['date' => 'nope', 'amount' => 100.0],
]), 'unparseable dates -> null');
