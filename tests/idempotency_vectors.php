<?php
// Dependency-free tests for the idempotency key: it stays the same across a retried
// call, and it can never smuggle extra HTTP headers.
// Run: php tests/idempotency_vectors.php - exits non-zero on any mismatch.
//
// Why this file exists: the key is the gateway's only duplicate-payment guard. A retry
// that sends a different key is a second real charge for one order, so the key the SDK
// used has to be reachable from the catch block that decides to retry.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/AuthenticationException.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\TransportException;

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

/**
 * A transport that times out for the first $failFirst calls and then succeeds, recording
 * the Idempotency-Key header value the SDK built for every attempt.
 */
final class FlakyClient extends DominaiteClient
{
    /** @var list<string> The idempotency key sent, per attempt. */
    public array $keysSent = [];

    private int $failFirst;

    public function __construct(int $failFirst = 0)
    {
        parent::__construct(
            'dmk_0123456789abcdef',
            'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
        );
        $this->failFirst = $failFirst;
    }

    protected function request(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $this->keysSent[] = $idempotencyKey;
        if (count($this->keysSent) <= $this->failFirst) {
            throw new TransportException('Could not reach the Dominaite API: timed out');
        }

        return [
            'success' => true,
            'checkout' => [
                'transactionId' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
                'orderId' => 'ord_9',
                'cashierKey' => 'ck_9',
                'cashierToken' => 'ct_9',
                'amount' => 8440,
                'currency' => 'EUR',
                'expiresAt' => '2026-08-20T16:00:00Z',
            ],
        ];
    }
}

$params = ['amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042'];

// 1. The generated key is reachable from the catch block, which is the only thing that
//    makes "retry with the same key" possible when the caller supplied none.
$timeout = new FlakyClient(1);
$caught = 'no throw';
try {
    $timeout->createCheckoutSession($params);
} catch (TransportException $e) {
    $caught = 'TransportException';
}
check('a timed-out create surfaces TransportException', $caught, 'TransportException');
check('the generated key is readable after the failure',
    $timeout->getLastIdempotencyKey() === $timeout->keysSent[0] ? 'same key' : 'lost', 'same key');

// 2. Retrying that call with the key it exposed sends the SAME key on every attempt.
//    A second key here would be a second real payment for one order.
$retried = new FlakyClient(2);
$key = null;
$attempts = 0;
for ($i = 0; $i < 3; $i++) {
    $attempts++;
    try {
        $retryParams = $params;
        if ($key !== null) {
            $retryParams['idempotencyKey'] = $key;
        }
        $retried->createCheckoutSession($retryParams);
        break;
    } catch (TransportException $e) {
        $key = $retried->getLastIdempotencyKey();
    }
}
check('the retry loop ran three attempts', (string) $attempts, '3');
check('every attempt sent one and the same idempotency key',
    (string) count(array_unique($retried->keysSent)), '1');
check('the exposed key is the one that was sent',
    (string) $retried->getLastIdempotencyKey(), $retried->keysSent[0]);

// 3. A caller-supplied key is used unchanged, on every attempt.
$mine = new FlakyClient(1);
for ($i = 0; $i < 2; $i++) {
    try {
        $mine->createCheckoutSession($params + ['idempotencyKey' => 'order-1042']);
    } catch (TransportException $e) {
        // retry
    }
}
check('a caller key is sent verbatim on every attempt',
    implode(',', $mine->keysSent), 'order-1042,order-1042');
check('a caller key is what getLastIdempotencyKey() reports',
    (string) $mine->getLastIdempotencyKey(), 'order-1042');

// 4. Nothing that would break out of the header line survives validation. The key is
//    concatenated into an HTTP header, so a CR or LF in it injects headers of the
//    caller's choosing - X-Forwarded-For, say, which the pre-auth rate limiter trusts.
$injections = [
    'CRLF' => "order-1\r\nX-Injected: yes",
    'bare CR' => "order-1\rX-Injected: yes",
    'bare LF' => "order-1\nX-Injected: yes",
    'trailing LF' => "order-1\n",
    'leading CRLF' => "\r\norder-1",
    'NUL' => "order-1\0",
    'tab' => "order-1\tX",
    'non-ascii' => "order-\u{00e9}1",
];
foreach ($injections as $label => $bad) {
    $client = new FlakyClient();
    $outcome = 'accepted';
    try {
        $client->createCheckoutSession($params + ['idempotencyKey' => $bad]);
    } catch (\InvalidArgumentException $e) {
        $outcome = 'rejected locally';
    }
    check("idempotency key rejected before the call: $label", $outcome, 'rejected locally');
    check("nothing was sent for the rejected key: $label", (string) count($client->keysSent), '0');
}

// Ordinary keys still pass - the check must not cost anyone a working integration.
foreach (['order-1042', 'ORDER 1042 / attempt #2', str_repeat('k', 100), '~!@#$%^&*()_+={}[]|:;"<>,.?'] as $good) {
    $client = new FlakyClient();
    $outcome = 'rejected';
    try {
        $client->createCheckoutSession($params + ['idempotencyKey' => $good]);
        $outcome = 'accepted';
    } catch (\InvalidArgumentException $e) {
        // falls through as rejected
    }
    check('normal idempotency key accepted: ' . substr($good, 0, 12), $outcome, 'accepted');
}

// 5. A call that never reached the wire must not leave the PREVIOUS order's key readable.
//    Otherwise an error handler files order A's key against order B, and retrying B with it
//    collides with A and reports A's transaction as the duplicate.
$reused = new FlakyClient();
$reused->createCheckoutSession($params + ['idempotencyKey' => 'order-A']);
check('the accessor holds the key after a successful call',
    (string) $reused->getLastIdempotencyKey(), 'order-A');
foreach (['malformed' => "order-B\r\nX: y", 'empty' => '', 'too long' => str_repeat('k', 101)] as $label => $bad) {
    try {
        $reused->createCheckoutSession($params + ['idempotencyKey' => $bad]);
    } catch (\InvalidArgumentException $e) {
        // the point is what the accessor reads afterwards
    }
    check("a rejected key clears the accessor rather than keeping the old one: $label",
        $reused->getLastIdempotencyKey() === null ? 'null' : (string) $reused->getLastIdempotencyKey(), 'null');
    $reused->createCheckoutSession($params + ['idempotencyKey' => 'order-A']);
}
// The same holds for a rejected amount, which is caught before the key is even read.
try {
    $reused->createCheckoutSession(['amount' => 0, 'currency' => 'EUR', 'orderReference' => 'order-B']);
} catch (\InvalidArgumentException $e) {
    // expected
}
check('a rejected amount clears the accessor too',
    $reused->getLastIdempotencyKey() === null ? 'null' : 'stale', 'null');

// 6. The key id lands in X-Api-Key-Id, so it gets the same treatment.
$badKeyIds = [
    'CRLF' => "dmk_a\r\nX-Injected: yes",
    'trailing LF' => "dmk_a\n",
    'non-ascii' => "dmk_\u{00e9}",
];
foreach ($badKeyIds as $label => $badKeyId) {
    $outcome = 'accepted';
    try {
        new DominaiteClient($badKeyId, 'dms_0123456789abcdef');
    } catch (\InvalidArgumentException $e) {
        $outcome = 'rejected locally';
    }
    check("key id with header-breaking bytes rejected: $label", $outcome, 'rejected locally');
}

exit($failures === 0 ? 0 : 1);
