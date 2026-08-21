<?php
// Dependency-free tests for the idempotency key: it stays the same across a retried call.
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

exit($failures === 0 ? 0 : 1);
