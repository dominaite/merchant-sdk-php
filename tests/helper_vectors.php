<?php
// Dependency-free tests for the static helpers integrators build on: minor-unit
// conversion and the status predicates (isPaid, isTerminal).
// Run: php tests/helper_vectors.php - exits non-zero on any mismatch.
//
// Why this file exists: amounts cross the wire as integer minor units, and a wrong
// conversion is a wrong charge. Every case here is one a float-based conversion, a
// "every currency has two decimals" shortcut or a plain ISO 4217 table gets wrong: the
// exponents follow the gateway's currency registry, which counts HUF in whole forints.

require __DIR__ . '/../src/DominaiteClient.php';

use Dominaite\DominaiteClient;

$failures = 0;

set_exception_handler(static function (\Throwable $e): void {
    echo 'FAIL uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

function check(string $name, string $actual, string $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "ok  $name\n";
    } else {
        echo "FAIL $name\n  expected $expected\n  actual   $actual\n";
        $failures++;
    }
}

/** The converted amount as a string, or 'rejected' when the helper refused it. */
function minor(string $amount, string $currency): string
{
    try {
        return var_export(DominaiteClient::toMinorUnits($amount, $currency), true);
    } catch (\InvalidArgumentException $e) {
        return 'rejected';
    }
}

// --- toMinorUnits: conversion -----------------------------------------------------------
$conversions = [
    ['0.30', 'EUR', '30'],       // (int) (0.3 * 100) is 29: the reason this takes a string
    ['0.29', 'EUR', '29'],
    ['19.99', 'USD', '1999'],
    ['25', 'EUR', '2500'],
    ['25.5', 'EUR', '2550'],
    ['25.50', 'GBP', '2550'],
    ['0.01', 'BGN', '1'],
    ['1234567.89', 'RON', '123456789'],
    ['007.10', 'CHF', '710'],
    ['0', 'EUR', '0'],
    ['0.00', 'EUR', '0'],
    ['1000', 'JPY', '1000'],     // zero-decimal: 1000 JPY is 1000, not 100000
    ['5000', 'HUF', '5000'],     // the gateway counts whole forints; ISO's 2 would be 500000
    ['5000', 'huf', '5000'],
    ['19.99', 'CAD', '1999'],
    ['19.99', 'AUD', '1999'],
    ['1.250', 'KWD', '1250'],    // three-decimal
    ['1.25', 'BHD', '1250'],
    ['9.99', 'eur', '999'],      // currency is case-insensitive
    ['92233720368547758.07', 'EUR', (string) PHP_INT_MAX],
];
foreach ($conversions as [$amount, $currency, $expected]) {
    check("toMinorUnits(\"$amount\", $currency)", minor($amount, $currency), $expected);
}
check('toMinorUnits returns an int', gettype(DominaiteClient::toMinorUnits('0.30', 'EUR')), 'integer');

// Every currency the SDK lists, with the exponent the gateway registry uses.
$exponents = [
    'EUR' => 2, 'USD' => 2, 'GBP' => 2, 'CAD' => 2, 'AUD' => 2, 'CHF' => 2, 'BGN' => 2,
    'RON' => 2, 'PLN' => 2, 'CZK' => 2, 'SEK' => 2, 'DKK' => 2, 'NOK' => 2,
    'JPY' => 0, 'HUF' => 0,
    'BHD' => 3, 'KWD' => 3,
];
foreach ($exponents as $currency => $exponent) {
    check("$currency has exponent $exponent", (string) DominaiteClient::minorUnitExponent($currency), (string) $exponent);
}

// --- toMinorUnits: refusals ----------------------------------------------------------------
$refusals = [
    'three decimals for EUR' => ['25.505', 'EUR'],
    'a trailing zero past the exponent' => ['25.500', 'EUR'],
    'three zero decimals for EUR' => ['25.000', 'EUR'],
    'any decimals for JPY' => ['100.0', 'JPY'],
    'any decimals for HUF, even zeros' => ['5000.00', 'HUF'],
    'fillér for HUF' => ['5000.50', 'HUF'],
    'four decimals for KWD' => ['1.2500', 'KWD'],
    'a negative amount' => ['-5.00', 'EUR'],
    'a plus sign' => ['+5.00', 'EUR'],
    'a leading dot' => ['.50', 'EUR'],
    'a trailing dot' => ['5.', 'EUR'],
    'a comma decimal mark' => ['5,00', 'EUR'],
    'a thousands separator' => ['1,000.00', 'EUR'],
    'surrounding whitespace' => [' 5.00', 'EUR'],
    'a trailing newline' => ["5.00\n", 'EUR'],
    'exponent notation' => ['1e3', 'EUR'],
    'an empty string' => ['', 'EUR'],
    'two dots' => ['1.2.3', 'EUR'],
    'past PHP_INT_MAX' => ['92233720368547758.08', 'EUR'],
    'far past PHP_INT_MAX' => [str_repeat('9', 30), 'JPY'],
    'an unknown currency' => ['5.00', 'XYZ'],
    'a currency not listed' => ['5.00', 'MXN'],
    'ISK, where ISO and the gateway disagree' => ['150', 'ISK'],
    'KRW, where ISO and the gateway disagree' => ['1000', 'KRW'],
    'OMR, where ISO and the gateway disagree' => ['0.001', 'OMR'],
    'JOD, where ISO and the gateway disagree' => ['10', 'JOD'],
    'TND, where ISO and the gateway disagree' => ['2.5', 'TND'],
    'a lowercase unsupported currency' => ['1000', 'krw'],
    'an empty currency' => ['5.00', ''],
];
foreach ($refusals as $label => [$amount, $currency]) {
    check("toMinorUnits refuses $label", minor($amount, $currency), 'rejected');
}
foreach (['ISK', 'KRW', 'OMR', 'JOD', 'TND'] as $unsupported) {
    $outcome = 'returned';
    try {
        DominaiteClient::minorUnitExponent($unsupported);
    } catch (\InvalidArgumentException $e) {
        $outcome = strpos($e->getMessage(), 'not supported') !== false ? 'not supported' : $e->getMessage();
    }
    check("minorUnitExponent($unsupported) says not supported", $outcome, 'not supported');
}

// --- isPaid / isTerminal ----------------------------------------------------------------
// The whole vocabulary, one row each, so a status added to STATUS_VOCABULARY without a
// decision here fails the count check below.
$verdicts = [
    'pending' => ['paid' => false, 'terminal' => false],
    'processing' => ['paid' => false, 'terminal' => false],
    'succeeded' => ['paid' => true, 'terminal' => true],
    'failed' => ['paid' => false, 'terminal' => true],
    'refunded' => ['paid' => false, 'terminal' => true],
    'partially_refunded' => ['paid' => false, 'terminal' => true],
    'cancelled' => ['paid' => false, 'terminal' => true],
    'disputed' => ['paid' => false, 'terminal' => false],
    'requires_capture' => ['paid' => false, 'terminal' => false],
    'abandoned' => ['paid' => false, 'terminal' => true],
];
check('every status in the vocabulary has a verdict here',
    json_encode(array_keys($verdicts)), json_encode(DominaiteClient::STATUS_VOCABULARY));
foreach ($verdicts as $status => $verdict) {
    check("isPaid($status)", var_export(DominaiteClient::isPaid($status), true), var_export($verdict['paid'], true));
    check("isTerminal($status)", var_export(DominaiteClient::isTerminal($status), true), var_export($verdict['terminal'], true));
}
foreach (['chargeback_pending', '', 'SUCCEEDED', ' succeeded'] as $unknown) {
    $label = var_export($unknown, true);
    check("an unknown status $label is not paid", var_export(DominaiteClient::isPaid($unknown), true), 'false');
    check("an unknown status $label is not terminal", var_export(DominaiteClient::isTerminal($unknown), true), 'false');
}
check('every terminal status is in the vocabulary',
    json_encode(array_values(array_diff(DominaiteClient::TERMINAL_STATUSES, DominaiteClient::STATUS_VOCABULARY))), '[]');

if ($failures > 0) {
    echo "\n$failures helper check(s) failed\n";
    exit(1);
}
echo "\nall helper checks passed\n";
