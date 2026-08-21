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

exit($failures === 0 ? 0 : 1);
