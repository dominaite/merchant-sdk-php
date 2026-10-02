<?php
// Pins this SDK's hardcoded enumerations against the gateway's live contract.
// Run: php tests/wire_contract_vectors.php - exits non-zero on any mismatch.
//
// tests/merchant-api-wire-contract.json is the machine-relevant projection of the
// gateway's GET /merchant-api/integration/contract, refreshed by
// .github/workflows/contract-drift.yml. When a check here fails the gateway moved:
// fix the SDK and release, never the fixture.

require __DIR__ . '/../src/DominaiteClient.php';

use Dominaite\DominaiteClient;

$failures = 0;

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

function sortedJson(array $list): string
{
    sort($list);
    return json_encode(array_values($list));
}

/** @param array<int, array<string, mixed>> ...$groups */
function codesWithStatus(int $httpStatus, array ...$groups): array
{
    $codes = [];
    foreach ($groups as $group) {
        foreach ($group as $entry) {
            if ($entry['httpStatus'] === $httpStatus) {
                $codes[] = $entry['code'];
            }
        }
    }
    return $codes;
}

/**
 * The top-level keys of the array shape in getStatus()'s @return docblock, as
 * key => 'key:type' or 'key?:type' for an optional key. That docblock is the status
 * type: it is what an integrator's IDE and static analyser read.
 *
 * @return array<string, string>
 */
function statusShape(): array
{
    $doc = (string) (new ReflectionMethod(DominaiteClient::class, 'getStatus'))->getDocComment();
    if (preg_match('/@return array\{(.*)\}\s*$/m', $doc, $match) !== 1) {
        return [];
    }
    $entries = [];
    $depth = 0;
    $current = '';
    foreach (str_split($match[1]) as $char) {
        if ($char === ',' && $depth === 0) {
            $entries[] = $current;
            $current = '';
            continue;
        }
        $depth += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
        $current .= $char;
    }
    $entries[] = $current;
    $shape = [];
    foreach ($entries as $entry) {
        $shape[(string) preg_replace('/\??:.*$/s', '', $entry)] = $entry;
    }
    return $shape;
}

$wire = json_decode(file_get_contents(__DIR__ . '/merchant-api-wire-contract.json'), true);

check(
    'status vocabulary matches the gateway, in order',
    json_encode(DominaiteClient::STATUS_VOCABULARY),
    json_encode($wire['statuses'])
);

check(
    'refusal codes are exactly the HTTP 200 error codes',
    sortedJson(DominaiteClient::REFUSAL_ERROR_CODES),
    sortedJson(codesWithStatus(200, $wire['errorCodes']['transient'], $wire['errorCodes']['idempotency']))
);

check(
    'validation codes are exactly the HTTP 400 idempotency codes',
    sortedJson(DominaiteClient::VALIDATION_ERROR_CODES),
    sortedJson(codesWithStatus(400, $wire['errorCodes']['idempotency']))
);

check('validation responses are HTTP 400', (string) $wire['validationHttpStatus'], '400');

// Every code the SDK names as a constant is spelled exactly as the gateway sends it.
$named = [
    'PAYMENT_PROCESSING_UNAVAILABLE' => DominaiteClient::PAYMENT_PROCESSING_UNAVAILABLE,
    'DUPLICATE_REQUEST' => DominaiteClient::DUPLICATE_REQUEST,
    'ALREADY_PROCESSED' => DominaiteClient::ALREADY_PROCESSED,
    'IDEMPOTENCY_KEY_REUSED' => DominaiteClient::IDEMPOTENCY_KEY_REUSED,
    'PRIOR_ATTEMPT_FAILED' => DominaiteClient::PRIOR_ATTEMPT_FAILED,
    'STOREFRONT_NOT_WHITELISTED' => DominaiteClient::STOREFRONT_NOT_WHITELISTED,
    'STOREFRONT_INACTIVE' => DominaiteClient::STOREFRONT_INACTIVE,
    'STOREFRONT_MISMATCH' => DominaiteClient::STOREFRONT_MISMATCH,
];
foreach ($named as $name => $value) {
    check("constant $name carries its own wire spelling", $value, $name);
}
check('the refusal list is built from the named constants',
    sortedJson(DominaiteClient::REFUSAL_ERROR_CODES),
    sortedJson(array_slice(array_values($named), 0, 5)));
check('the storefront list is exactly the storefront constants',
    sortedJson(DominaiteClient::STOREFRONT_ERROR_CODES),
    sortedJson(array_slice(array_values($named), 5)));
check('storefront codes are not HTTP 200 refusals',
    json_encode(array_values(array_intersect(DominaiteClient::STOREFRONT_ERROR_CODES, DominaiteClient::REFUSAL_ERROR_CODES))), '[]');

// The gateway publishes the storefront codes as their own group: the SDK's list is that
// group in order, each at the status the SDK documents, none retryable.
check('the storefront list is exactly the contract storefront group, in order',
    json_encode(DominaiteClient::STOREFRONT_ERROR_CODES),
    json_encode(array_map(static function (array $entry): string {
        return $entry['code'];
    }, $wire['errorCodes']['storefront'])));
$storefrontStatus = [
    'STOREFRONT_MISMATCH' => 400,
    'STOREFRONT_INACTIVE' => 409,
    'STOREFRONT_NOT_WHITELISTED' => 409,
];
foreach ($wire['errorCodes']['storefront'] as $entry) {
    check("the contract's {$entry['code']} status matches the SDK",
        (string) $entry['httpStatus'], (string) ($storefrontStatus[$entry['code']] ?? 'unknown'));
    check("the contract's {$entry['code']} is not retryable", var_export($entry['retry'], true), 'false');
}

check('the contract still lists this SDK', in_array('php', $wire['sdks'], true) ? 'listed' : 'missing', 'listed');

check(
    'wallet types match the gateway, in order',
    json_encode(DominaiteClient::WALLET_TYPES),
    json_encode($wire['wallets']['walletTypes'] ?? null)
);

// Each wallet reporting field is on the status type, optional and nullable exactly as the
// gateway declares it.
$shape = statusShape();
check('the gateway still names the wallet reporting fields',
    (string) count($wire['wallets']['reportingFields'] ?? []), '2');
foreach ($wire['wallets']['reportingFields'] ?? [] as $field) {
    $path = (string) $field['path'];
    check("the status type carries $path as the gateway declares it",
        $shape[$path] ?? 'missing',
        $path . ($field['required'] ? ':' : '?:') . '?' . $field['type']);
}

if ($failures > 0) {
    echo "\n$failures wire-contract check(s) failed\n";
    exit(1);
}
echo "\nall wire-contract checks passed\n";
