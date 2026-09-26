<?php
// Dependency-free known-answer tests for webhook signature verification.
// Run: php tests/webhook_vectors.php - exits non-zero on any mismatch.
// The vector below is the canonical cross-SDK one: the same body, secret, timestamp
// and header are pinned by every Dominaite SDK. Do not reformat the body string.

require __DIR__ . '/../src/DominaiteClient.php';

use Dominaite\DominaiteClient;

$secret = 'whsec_abababababababababababababababababababababababababababababababab';
$timestamp = 1755700000;
$body = '{"id":"7f9c24e5-1d1f-4c0a-9b6c-2f3a4d5e6f70","type":"payment.succeeded","createdAt":"2026-08-20T14:00:00Z","data":{"transactionId":"0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0","status":"succeeded","previousStatus":"pending","kind":"sale","amount":8440,"grossAmount":8701,"surchargeAmount":261,"currency":"EUR","originalTransactionId":null,"idempotencyKey":"order-123"}}';
$header = 't=1755700000,v1=5305bcf1302fdaba8f8c19a20c899e916fb4d2a7d8d547c62529ff87c4697b72';

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

/** Reports the verdict, or the exception type when one escapes - both are outcomes we pin. */
function verdict(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): string
{
    try {
        return DominaiteClient::verifyWebhook($payload, $header, $secret, $tolerance, $now) ? 'true' : 'false';
    } catch (\Throwable $e) {
        return 'threw ' . get_class($e);
    }
}

// 1. The canonical vector verifies.
check('canonical mac', hash_hmac('sha256', $timestamp . '.' . $body, $secret),
    '5305bcf1302fdaba8f8c19a20c899e916fb4d2a7d8d547c62529ff87c4697b72');
check('canonical vector verifies', verdict($body, $header, $secret, 300, $timestamp), 'true');
check('verifies at the tolerance edge', verdict($body, $header, $secret, 300, $timestamp + 300), 'true');

// 2. A single-byte body tamper fails: 8440 -> 8441, nothing else touched.
$tampered = str_replace('"amount":8440', '"amount":8441', $body);
check('tampered body is a different string', $tampered === $body ? 'same' : 'different', 'different');
check('single-byte tamper fails', verdict($tampered, $header, $secret, 300, $timestamp), 'false');

// 3. A wrong secret fails - same body, same header.
check('wrong secret fails',
    verdict($body, $header, 'whsec_cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd', 300, $timestamp),
    'false');

// 4. A valid MAC outside the tolerance window fails, in both directions.
check('stale delivery fails', verdict($body, $header, $secret, 300, $timestamp + 301), 'false');
check('future delivery fails', verdict($body, $header, $secret, 300, $timestamp - 301), 'false');

// 5. Malformed headers fail with a plain false, never an exception.
$mac = '5305bcf1302fdaba8f8c19a20c899e916fb4d2a7d8d547c62529ff87c4697b72';
$malformed = [
    'missing t'          => 'v1=' . $mac,
    'missing v1'         => 't=1755700000',
    'empty header'       => '',
    'garbage'            => 'not-a-signature',
    'no separator'       => 't1755700000v1' . $mac,
    'non-numeric t'      => 't=yesterday,v1=' . $mac,
    'non-hex v1'         => 't=1755700000,v1=zzzz',
    'truncated v1'       => 't=1755700000,v1=' . substr($mac, 0, 32),
    'uppercase v1'       => 't=1755700000,v1=' . strtoupper($mac),
    'v1 with whitespace' => 't=1755700000,v1= ' . $mac,
];
foreach ($malformed as $name => $bad) {
    check("malformed header rejected: $name", verdict($body, $bad, $secret, 300, $timestamp), 'false');
}

// 6. The ten shared header-grammar vectors from WEBHOOKS-CONTRACT.md. Every Dominaite SDK
// pins this same list; nine reject, the last one verifies. The grammar is deliberately
// narrow - the platform only ever emits "t={digits},v1={64 lowercase hex}", so anything
// wider is accept-set we gain nothing from and an attacker gets to aim at.
$grammarRejects = [
    'g1 missing v1'        => 't=1755700000',
    'g2 missing t'         => 'v1=' . $mac,
    'g3 uppercase hex'     => 't=1755700000,v1=' . strtoupper($mac),
    'g4 repeated v1'       => 't=1755700000,v1=' . $mac . ',v1=' . $mac,
    'g5 repeated t'        => 't=1755700000,t=1755700000,v1=' . $mac,
    'g6 empty t + repeat'  => 't=,v1=garbage,v1=' . $mac,
    'g7 space after comma' => 't=1755700000, v1=' . $mac,
    'g8 non-digit in t'    => 't=+1755700000,v1=' . $mac,
    'g9 element without =' => 'garbage',
];
foreach ($grammarRejects as $name => $bad) {
    check("grammar vector rejected: $name", verdict($body, $bad, $secret, 300, $timestamp), 'false');
}

// g4/g5/g6 are the audit A7 shapes: a repeat must sink the header even when one of the
// candidates carries the real MAC. The platform never rotates secrets on the wire, so a
// second candidate is never something we are meant to fall back to.
check('grammar vector verifies: g10 unknown key ignored',
    verdict($body, 't=1755700000,v1=' . $mac . ',v9=deadbeef', $secret, 300, $timestamp), 'true');

// The raw digit substring is what gets signed, not a number we parsed and printed back.
// A parser that reformats "01755700000" to "1755700000" would accept the first of these.
check('leading-zero t does not match the reformatted MAC',
    verdict($body, 't=01755700000,v1=' . $mac, $secret, 300, $timestamp), 'false');
check('leading-zero t matches the MAC over the raw digits',
    verdict($body, 't=01755700000,v1=' . hash_hmac('sha256', '01755700000.' . $body, $secret), $secret, 300, $timestamp),
    'true');

// Caller-side mistakes are the caller's bug, and must be loud rather than a silent false.
check('empty secret throws', verdict($body, $header, '', 300, $timestamp), 'threw InvalidArgumentException');
check('negative tolerance throws', verdict($body, $header, $secret, -1, $timestamp), 'threw InvalidArgumentException');

// 7. Envelope fields added under apiVersion 2026-09-25 (gateway PR #3507). The signature
// covers the raw bytes whatever fields they carry, so these verify with the unchanged
// verifier. Bodies are shaped exactly as the gateway emits them (field order included)
// and the MACs were computed with openssl, not with this SDK, so the verifier is checked
// against an independent implementation of the recipe.
$eventTimestamp = 1790328600; // 2026-09-25T09:30:00Z
$agreementBody = '{"id":"2b7e1d9a-4c3f-4e8b-9a61-5d0c7f2e8b14","type":"agreement.past_due","apiVersion":"2026-09-25","createdAt":"2026-09-25T09:30:00Z","data":{"id":"agr_5f0c2a9e7d1b4c3a8e6f9d2b1a0c7e4f","planId":"plan_monthly_basic","customerReference":"cust-8817","storedPaymentMethodId":"pm_0a1b2c3d4e5f60718293a4b5c6d7e8f9","status":"past_due","previousStatus":"active","amount":2500,"currency":"EUR","intervalUnit":"month","intervalCount":1,"periodCount":null,"trialDays":0,"nextChargeAt":"2026-09-24T00:00:00Z","activatedAt":"2026-06-24T10:12:00Z","cancelledAt":null,"version":7,"sequence":4}}';
$agreementMac = '223198f3c1ee8f20c83e32f3431cf84765b55eb2c3de4e71b3ba8eff7c2e24ca';
$chargeBody = '{"id":"9d4a6c21-7e3b-4f58-b0c2-1e8f5a3d7c96","type":"charge.retrying","apiVersion":"2026-09-25","createdAt":"2026-09-25T09:30:00Z","data":{"chargeId":"ch_3e9a7c1d5b2f4a6e8c0d2f4b6a8e0c1d","transactionId":"6c2e8a4f-1b3d-4f5a-9c7e-0d2b4f6a8c1e","storedPaymentMethodId":"pm_0a1b2c3d4e5f60718293a4b5c6d7e8f9","agreementId":"agr_5f0c2a9e7d1b4c3a8e6f9d2b1a0c7e4f","customerReference":"cust-8817","outcome":"failed","periodNumber":4,"attemptNumber":2,"amount":2500,"currency":"EUR","paymentMethod":{"brand":"visa","last4":"4242"},"orderReference":"sub-8817-2026-09","description":"Monthly plan, September","declineClass":"soft_funds","declineCode":"51","nextAttemptAt":"2026-09-27T09:30:00Z","nextChargeAt":null,"sequence":3}}';
$chargeMac = '546ef98d31d18ef9a3d792fb6dbabbe75a6169bf0a1b5961e813f911d1e81e5e';
// Recorded before apiVersion and sequence existed: no apiVersion, no data.sequence. A
// retry resends the stored bytes, so this shape keeps arriving after the upgrade.
$legacyChargeBody = '{"id":"5e3f8b20-6a1d-4c97-8e2b-3f7a9c1d0e45","type":"charge.succeeded","createdAt":"2026-09-20T08:00:00Z","data":{"chargeId":"ch_7b1d3f5a9c2e4b6d8f0a1c3e5b7d9f2a","transactionId":"1a3c5e7b-9d2f-4b6a-8c0e-2d4f6b8a0c3e","storedPaymentMethodId":"pm_0a1b2c3d4e5f60718293a4b5c6d7e8f9","agreementId":null,"customerReference":"cust-8817","outcome":"succeeded","periodNumber":null,"attemptNumber":1,"amount":1200,"currency":"EUR","paymentMethod":{"brand":"visa","last4":"4242"},"orderReference":"order-9921","description":"One-off top-up","declineClass":null,"declineCode":null,"nextAttemptAt":null,"nextChargeAt":null}}';
$legacyChargeMac = 'b03c4e2a402ce0a81191342d4855be64fba804e1c6491f4e0e9ca76096ab14d8';

check('agreement event with apiVersion and sequence verifies',
    verdict($agreementBody, 't=' . $eventTimestamp . ',v1=' . $agreementMac, $secret, 300, $eventTimestamp), 'true');
check('charge event with apiVersion and sequence verifies',
    verdict($chargeBody, 't=' . $eventTimestamp . ',v1=' . $chargeMac, $secret, 300, $eventTimestamp), 'true');
check('legacy charge event without apiVersion or sequence verifies',
    verdict($legacyChargeBody, 't=' . $eventTimestamp . ',v1=' . $legacyChargeMac, $secret, 300, $eventTimestamp), 'true');
check('sequence is inside the MAC: 4 -> 5 fails',
    verdict(str_replace('"sequence":4', '"sequence":5', $agreementBody), 't=' . $eventTimestamp . ',v1=' . $agreementMac, $secret, 300, $eventTimestamp),
    'false');
check('apiVersion is inside the MAC: a changed version fails',
    verdict(str_replace('"apiVersion":"2026-09-25"', '"apiVersion":"2026-09-26"', $chargeBody), 't=' . $eventTimestamp . ',v1=' . $chargeMac, $secret, 300, $eventTimestamp),
    'false');

// The documented read: json_decode the verified bytes and take the new fields with ?? so
// an older delivery reads as absent instead of raising a notice.
$agreementEvent = json_decode($agreementBody, true);
check('agreement envelope apiVersion', (string) ($agreementEvent['apiVersion'] ?? 'absent'), '2026-09-25');
check('agreement envelope createdAt', (string) $agreementEvent['createdAt'], '2026-09-25T09:30:00Z');
check('agreement data.sequence is an integer', var_export($agreementEvent['data']['sequence'] ?? null, true), '4');
check('agreement object key is data.id', (string) $agreementEvent['data']['id'], 'agr_5f0c2a9e7d1b4c3a8e6f9d2b1a0c7e4f');

$chargeEvent = json_decode($chargeBody, true);
check('charge envelope apiVersion', (string) ($chargeEvent['apiVersion'] ?? 'absent'), '2026-09-25');
check('charge envelope createdAt', (string) $chargeEvent['createdAt'], '2026-09-25T09:30:00Z');
check('charge data.sequence is an integer', var_export($chargeEvent['data']['sequence'] ?? null, true), '3');
check('platform charge object key is agreementId + periodNumber',
    $chargeEvent['data']['agreementId'] . '/' . var_export($chargeEvent['data']['periodNumber'], true),
    'agr_5f0c2a9e7d1b4c3a8e6f9d2b1a0c7e4f/4');

$legacyEvent = json_decode($legacyChargeBody, true);
check('legacy envelope has no apiVersion', var_export($legacyEvent['apiVersion'] ?? null, true), 'NULL');
check('legacy data has no sequence', var_export($legacyEvent['data']['sequence'] ?? null, true), 'NULL');
check('legacy one-off charge keeps chargeId and createdAt',
    $legacyEvent['data']['chargeId'] . ' ' . $legacyEvent['createdAt'],
    'ch_7b1d3f5a9c2e4b6d8f0a1c3e5b7d9f2a 2026-09-20T08:00:00Z');

exit($failures === 0 ? 0 : 1);
