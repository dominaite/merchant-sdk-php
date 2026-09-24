<?php
// Dependency-free tests for the static helpers integrators build on: minor-unit
// conversion and the status predicates.
// Run: php tests/helper_vectors.php - exits non-zero on any mismatch.
//
// Why this file exists: amounts cross the wire as integer minor units, and a wrong
// conversion is a wrong charge. Every case here is one a float-based conversion or a
// "every currency has two decimals" shortcut gets wrong.

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
    ['1000', 'KRW', '1000'],
    ['150', 'ISK', '150'],
    ['1.250', 'KWD', '1250'],    // three-decimal
    ['1.25', 'BHD', '1250'],
    ['0.001', 'OMR', '1'],
    ['10', 'JOD', '10000'],
    ['2.5', 'TND', '2500'],
    ['9.99', 'eur', '999'],      // currency is case-insensitive
    ['92233720368547758.07', 'EUR', (string) PHP_INT_MAX],
];
foreach ($conversions as [$amount, $currency, $expected]) {
    check("toMinorUnits(\"$amount\", $currency)", minor($amount, $currency), $expected);
}
check('toMinorUnits returns an int', gettype(DominaiteClient::toMinorUnits('0.30', 'EUR')), 'integer');

// Every currency the SDK lists, with the exponent the owner decision pins.
$exponents = [
    'EUR' => 2, 'USD' => 2, 'GBP' => 2, 'BGN' => 2, 'RON' => 2, 'CHF' => 2,
    'PLN' => 2, 'CZK' => 2, 'HUF' => 2, 'SEK' => 2, 'DKK' => 2, 'NOK' => 2,
    'JPY' => 0, 'KRW' => 0, 'ISK' => 0,
    'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3,
];
foreach ($exponents as $currency => $exponent) {
    check("$currency has exponent $exponent", (string) DominaiteClient::minorUnitExponent($currency), (string) $exponent);
}

// --- toMinorUnits: refusals ----------------------------------------------------------------
$refusals = [
    'three decimals for EUR' => ['25.505', 'EUR'],
    'a trailing zero past the exponent' => ['25.500', 'EUR'],
    'any decimals for JPY' => ['100.0', 'JPY'],
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
    'a currency not listed' => ['5.00', 'AUD'],
    'an empty currency' => ['5.00', ''],
];
foreach ($refusals as $label => [$amount, $currency]) {
    check("toMinorUnits refuses $label", minor($amount, $currency), 'rejected');
}

if ($failures > 0) {
    echo "\n$failures helper check(s) failed\n";
    exit(1);
}
echo "\nall helper checks passed\n";
