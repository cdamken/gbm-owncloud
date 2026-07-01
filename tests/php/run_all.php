<?php
// Runs every tests/php/test_*.php, accumulating assert_* results, exits 1 if
// any failed. Run locally (php 8.5) for speed and — authoritatively — on the
// server (php 7.4.3) after deploy. PHP 7.4 compatible.
require_once __DIR__ . '/assert.php';
foreach (glob(__DIR__ . '/test_*.php') as $file) {
    require $file;
}
fwrite(STDOUT, sprintf("\nPHP core tests: %d passed, %d failed\n",
    $GLOBALS['__t_pass'], $GLOBALS['__t_fail']));
exit($GLOBALS['__t_fail'] === 0 ? 0 : 1);
