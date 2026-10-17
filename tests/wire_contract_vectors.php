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

// The contract's HTTP 200 codes are the first refusal codes. The SDK adds the subscription
// session refusals, which the contract does not group yet.
$http200 = codesWithStatus(200, $wire['errorCodes']['transient'], $wire['errorCodes']['idempotency']);
check(
    'the HTTP 200 error codes are the first refusal codes',
    sortedJson(array_slice(DominaiteClient::REFUSAL_ERROR_CODES, 0, count($http200))),
    sortedJson($http200)
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
check('the refusal list starts with the named constants',
    sortedJson(array_slice(DominaiteClient::REFUSAL_ERROR_CODES, 0, 5)),
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

// The authorization group is the 403 a key gets on a route outside its scope. The SDK's
// list is that group in order, each at 403, none retryable.
check('constant INSUFFICIENT_SCOPE carries its own wire spelling', DominaiteClient::INSUFFICIENT_SCOPE, 'INSUFFICIENT_SCOPE');
check('the authorization list is exactly the contract authorization group, in order',
    json_encode(DominaiteClient::AUTHORIZATION_ERROR_CODES),
    json_encode(array_map(static function (array $entry): string {
        return $entry['code'];
    }, $wire['errorCodes']['authorization'] ?? [])));
foreach ($wire['errorCodes']['authorization'] ?? [] as $entry) {
    check("the contract's {$entry['code']} is HTTP 403", (string) $entry['httpStatus'], '403');
    check("the contract's {$entry['code']} is not retryable", var_export($entry['retry'], true), 'false');
}

// Every group the contract publishes maps to one SDK list, exactly and in order.
$groups = [
    'auth' => DominaiteClient::AUTHENTICATION_ERROR_CODES,
    'authorization' => DominaiteClient::AUTHORIZATION_ERROR_CODES,
    'storefront' => DominaiteClient::STOREFRONT_ERROR_CODES,
    'transient' => DominaiteClient::TRANSIENT_ERROR_CODES,
    'validation' => DominaiteClient::FIELD_VALIDATION_CODES,
];
foreach ($groups as $group => $codes) {
    check("the $group list is exactly the contract $group group, in order",
        json_encode($codes),
        json_encode(array_map(static function (array $entry): string {
            return $entry['code'];
        }, $wire['errorCodes'][$group] ?? [])));
}
foreach ($wire['errorCodes']['auth'] ?? [] as $entry) {
    check("the contract's {$entry['code']} is HTTP 401", (string) $entry['httpStatus'], '401');
}
check('constant MERCHANT_API_UNAVAILABLE carries its own wire spelling',
    DominaiteClient::MERCHANT_API_UNAVAILABLE, 'MERCHANT_API_UNAVAILABLE');

// The vocabulary guard: every code the contract publishes, in any group, is named by the SDK.
$sdkCodes = array_merge(
    DominaiteClient::REFUSAL_ERROR_CODES,
    DominaiteClient::VALIDATION_ERROR_CODES,
    DominaiteClient::STOREFRONT_ERROR_CODES,
    DominaiteClient::AUTHORIZATION_ERROR_CODES,
    DominaiteClient::AUTHENTICATION_ERROR_CODES,
    DominaiteClient::TRANSIENT_ERROR_CODES,
    DominaiteClient::FIELD_VALIDATION_CODES,
    DominaiteClient::CHARGE_ERROR_CODES,
    DominaiteClient::REVOKE_ERROR_CODES,
    DominaiteClient::REFUND_ERROR_CODES,
    DominaiteClient::RECURRING_ERROR_CODES,
    DominaiteClient::WEBHOOK_ERROR_CODES
);
foreach ($wire['errorCodes'] as $group => $entries) {
    foreach ($entries as $entry) {
        check("the SDK names the contract's $group code {$entry['code']}",
            in_array($entry['code'], $sdkCodes, true) ? 'named' : 'missing', 'named');
    }
}

// Webhook event types: the payment catalog and the recurring list, exactly and in order.
check('the payment webhook events are exactly the contract catalog, in order',
    json_encode(DominaiteClient::PAYMENT_WEBHOOK_EVENTS), json_encode($wire['webhookEventCatalog']));
check('the recurring webhook events are exactly the contract list, in order',
    json_encode(DominaiteClient::RECURRING_WEBHOOK_EVENTS), json_encode($wire['recurringWebhookEvents']));
// verification.* is not in the contract yet; the SDK lists the two the platform sends.
check('the verification webhook events are the two the platform sends',
    json_encode(DominaiteClient::VERIFICATION_WEBHOOK_EVENTS), '["verification.succeeded","verification.failed"]');
check('no event type is in two lists',
    (string) count(array_unique(array_merge(DominaiteClient::PAYMENT_WEBHOOK_EVENTS,
        DominaiteClient::RECURRING_WEBHOOK_EVENTS, DominaiteClient::VERIFICATION_WEBHOOK_EVENTS))),
    (string) (count(DominaiteClient::PAYMENT_WEBHOOK_EVENTS) + count(DominaiteClient::RECURRING_WEBHOOK_EVENTS)
        + count(DominaiteClient::VERIFICATION_WEBHOOK_EVENTS)));

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
