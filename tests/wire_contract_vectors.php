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

check('the contract still lists this SDK', in_array('php', $wire['sdks'], true) ? 'listed' : 'missing', 'listed');

check(
    'wallet types match the gateway, in order',
    json_encode(DominaiteClient::WALLET_TYPES),
    json_encode($wire['wallets']['walletTypes'] ?? null)
);

$reportingPaths = [];
$allOptional = true;
foreach ($wire['wallets']['reportingFields'] ?? [] as $field) {
    $reportingPaths[] = $field['path'];
    $allOptional = $allOptional && $field['required'] === false;
}
check(
    'wallet reporting fields are paymentMethod and walletType',
    json_encode($reportingPaths),
    json_encode(['paymentMethod', 'walletType'])
);
check('wallet reporting fields are all optional', $allOptional ? 'all optional' : 'some required', 'all optional');

if ($failures > 0) {
    echo "\n$failures wire-contract check(s) failed\n";
    exit(1);
}
echo "\nall wire-contract checks passed\n";
