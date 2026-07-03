<?php
require_once __DIR__ . '/assert.php';
require_once __DIR__ . '/../../lib/Analytics/FiscalClassifier.php';

use OCA\Gbm\Analytics\FiscalClassifier;

assert_eq('dividend',    FiscalClassifier::classify(['category' => 'dividend']),        'dividend category');
assert_eq('withholding', FiscalClassifier::classify(['category' => 'tax_withholding']), 'tax_withholding -> withholding');
assert_eq('interest',    FiscalClassifier::classify(['category' => 'repo_mature']),     'repo maturity -> interest');
assert_eq('none',        FiscalClassifier::classify(['category' => 'buy_stock']),       'buy -> none');
assert_eq('none',        FiscalClassifier::classify(['category' => 'deposit']),         'deposit -> none');
assert_eq('none',        FiscalClassifier::classify([]),                                'missing category -> none');
assert_eq(2026, FiscalClassifier::fiscalYear('2026-04-02T10:44:18-06:00'), 'year from iso datetime');
assert_eq(2025, FiscalClassifier::fiscalYear('2025-12-31'),                'year from date');
assert_eq(null, FiscalClassifier::fiscalYear(''),                         'empty -> null');
