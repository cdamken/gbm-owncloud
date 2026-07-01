<?php
// Tiny assertion harness for pure-PHP core tests. PHP 7.4 compatible.
// Test files require_once this, call assert_*; run_all.php checks the global
// counters and exits non-zero on any failure. Idempotent under require_once.
if (!isset($GLOBALS['__t_pass'])) {
    $GLOBALS['__t_pass'] = 0;
    $GLOBALS['__t_fail'] = 0;
}

function assert_eq($expected, $actual, $label) {
    if ($expected === $actual) {
        $GLOBALS['__t_pass']++;
        return;
    }
    $GLOBALS['__t_fail']++;
    fwrite(STDERR, sprintf("FAIL: %s\n  expected: %s\n  actual:   %s\n",
        $label, var_export($expected, true), var_export($actual, true)));
}

function assert_true($cond, $label) {
    assert_eq(true, (bool) $cond, $label);
}

function assert_close($expected, $actual, $label, $eps = 0.001) {
    if (abs(((float) $expected) - ((float) $actual)) <= $eps) {
        $GLOBALS['__t_pass']++;
        return;
    }
    $GLOBALS['__t_fail']++;
    fwrite(STDERR, sprintf("FAIL: %s\n  expected ~%s\n  actual   %s\n",
        $label, var_export($expected, true), var_export($actual, true)));
}
