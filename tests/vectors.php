<?php
// Dependency-free known-answer tests for the signing recipe. Run: php tests/vectors.php
// Exits non-zero on any mismatch. These are the same published vectors every
// Dominaite SDK pins - see the dashboard's Website-integration tab.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\CheckoutRefusedException;

$secret = 'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$path = '/merchant-api/bridgerpay/checkout/sessions';
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

// POST vector
$body = '{"amount":2500,"currency":"EUR","orderReference":"order-1042"}';
check('post body sha256', hash('sha256', $body), 'aa3edd72cd1829f4e053abb048b08c1ae91c2d67b08955997c4b6c4dab4f98ff');
check('post signature', DominaiteClient::signRequest($secret, '1755302400', 'POST', $path, '00000000-0000-4000-8000-000000000001', $body),
    '95759958a0a0a9bd3e6e37101c01e8e7fee1166406e4ac2ff488764f5f742cbf');

// GET vector: empty idempotency key AND empty body - still five lines
check('get signature', DominaiteClient::signRequest($secret, '1755302400', 'GET', $path . '/00000000-0000-4000-8000-000000000002', '', ''),
    '010635e61caabdb82a031a51fa56999b670b61d57239e5fa3db71a43c731f93d');

// Ping vector: same GET shape as the status read - empty idempotency key, empty body,
// and the canonical path only, never the base URL's own prefix.
check('ping path', DominaiteClient::PING_PATH, '/merchant-api/ping');
check('ping signature', DominaiteClient::signRequest($secret, '1755302400', 'GET', DominaiteClient::PING_PATH, '', ''),
    '2c5cf05fe4d5c72c8a1876525fe6449c3b18b4f36456b5c436240bd1a15e857e');

// UTF-8 vector: hash the exact bytes you send
$utf8Body = "{\"amount\":2500,\"currency\":\"EUR\",\"orderReference\":\"order-1042\",\"customer\":{\"firstName\":\"\u{0410}\u{043d}\u{043d}\u{0430}\",\"lastName\":\"M\u{00fc}ller\"}}";
check('utf8 body sha256', hash('sha256', $utf8Body), 'baf00d6116d9f2eec6c3a422af0bc2c342717f669aa2350ef6ed556f57ac34b5');
check('utf8 signature', DominaiteClient::signRequest($secret, '1755302400', 'POST', $path, '00000000-0000-4000-8000-000000000003', $utf8Body),
    '460659cb1218d97bf2e86c1c09c60f0db87197c499d8296dd5d07a614e17257c');

// A replay refusal must expose the transaction the key collided with, or the
// documented recovery (read it back with getStatus) is unreachable from the catch.
$refusal = new CheckoutRefusedException(
    'DUPLICATE_REQUEST',
    'A checkout session for this idempotency key is already open.',
    '11111111-2222-4333-8444-555555555555',
    ['success' => false, 'errorCode' => 'DUPLICATE_REQUEST', 'transactionId' => '11111111-2222-4333-8444-555555555555']
);
check('refusal error code', $refusal->getErrorCode(), 'DUPLICATE_REQUEST');
check('refusal transaction id', (string) $refusal->getTransactionId(), '11111111-2222-4333-8444-555555555555');
check('refusal result payload', (string) ($refusal->getResult()['errorCode'] ?? ''), 'DUPLICATE_REQUEST');

// The two-argument form still works: the recovery fields are additive, and the
// concurrent-race DUPLICATE_REQUEST genuinely arrives without a transaction id.
$bare = new CheckoutRefusedException('PAYMENT_PROCESSING_UNAVAILABLE', 'Card payments are off');
check('refusal without id stays null', $bare->getTransactionId() === null ? 'null' : 'set', 'null');
check('refusal without id has empty result', (string) count($bare->getResult()), '0');

exit($failures === 0 ? 0 : 1);
