<?php
// Dependency-free known-answer tests for the signing recipe. Run: php tests/vectors.php
// Exits non-zero on any mismatch. These are the same published vectors every
// Dominaite SDK pins - see the dashboard's Website-integration tab.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\CheckoutRefusedException;

$secret = 'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$path = '/merchant-api/checkout/sessions';
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
    '8f5fba0b29a8eea81b76a0e6d7119e79ec68f586910f77713b045652e5ce9b74');

// GET vector: empty idempotency key AND empty body - still five lines
check('get signature', DominaiteClient::signRequest($secret, '1755302400', 'GET', $path . '/00000000-0000-4000-8000-000000000002', '', ''),
    '70002896ec8411efb7754de6c49c2fd6f35bb2d001966978a2f573de1914e68d');

// Ping vector: same GET shape as the status read - empty idempotency key, empty body,
// and the canonical path only, never the base URL's own prefix.
check('ping path', DominaiteClient::PING_PATH, '/merchant-api/ping');
check('ping signature', DominaiteClient::signRequest($secret, '1755302400', 'GET', DominaiteClient::PING_PATH, '', ''),
    '2c5cf05fe4d5c72c8a1876525fe6449c3b18b4f36456b5c436240bd1a15e857e');

// UTF-8 vector: hash the exact bytes you send
$utf8Body = "{\"amount\":2500,\"currency\":\"EUR\",\"orderReference\":\"order-1042\",\"customer\":{\"firstName\":\"\u{0410}\u{043d}\u{043d}\u{0430}\",\"lastName\":\"M\u{00fc}ller\"}}";
check('utf8 body sha256', hash('sha256', $utf8Body), 'baf00d6116d9f2eec6c3a422af0bc2c342717f669aa2350ef6ed556f57ac34b5');
check('utf8 signature', DominaiteClient::signRequest($secret, '1755302400', 'POST', $path, '00000000-0000-4000-8000-000000000003', $utf8Body),
    'dd809cb0b902326704a380110c29d9f789cc355864e1ed1de663157342834010');

// Stored-payment-method vectors, same secret and timestamp. The charge vector is the
// only POST besides sessions and the only one whose canonical path carries a resource
// id; the revoke vector pins that DELETE signs an empty key and an empty body exactly
// like GET. Shared byte-for-byte with the gateway's MerchantApiRequestAuthenticator tests.
$paymentMethodId = 'pm_0123456789abcdef0123456789abcdef';
$chargePath = DominaiteClient::PAYMENT_METHODS_PATH . '/' . $paymentMethodId . '/charges';
$chargeBody = '{"amount":2500,"currency":"EUR","orderReference":"order-1043"}';
check('payment methods path', DominaiteClient::PAYMENT_METHODS_PATH, '/merchant-api/payment-methods');
check('charge body sha256', hash('sha256', $chargeBody), '641a0d2b08f88ebc458dca49410dede0a166359a5030bff5c977e507f13ab828');
check('charge signature', DominaiteClient::signRequest($secret, '1755302400', 'POST', $chargePath, '00000000-0000-4000-8000-000000000003', $chargeBody),
    '9ce9f54efa2533a46aa4493b97b56aeb657f41d6a18f1c008c7fd412029aebf9');

$revokePath = DominaiteClient::PAYMENT_METHODS_PATH . '/' . $paymentMethodId;
check('revoke body sha256 is the empty hash', hash('sha256', ''), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
check('revoke signature', DominaiteClient::signRequest($secret, '1755302400', 'DELETE', $revokePath, '', ''),
    '9330100343c4b820504890a09829a193d5815ca39e92160fdfc13d320a802a02');
// Same recipe as the GET vector: only the method moved, so the signature must move too.
check('revoke signs the method, not just the path',
    DominaiteClient::signRequest($secret, '1755302400', 'GET', $revokePath, '', '') === '9330100343c4b820504890a09829a193d5815ca39e92160fdfc13d320a802a02' ? 'same' : 'different',
    'different');

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
