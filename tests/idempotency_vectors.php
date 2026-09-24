<?php
// Dependency-free tests for the idempotency key: it is required, the order-derived
// helper builds a stable one, it stays the same across a retried call, and it can never
// smuggle extra HTTP headers.
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

// 1. The key is required. A missing key is refused before anything is signed or sent:
//    the old random fallback turned every reload that forgot to reuse a key into a
//    second payment for the same order.
$keyless = new FlakyClient();
$caught = 'no throw';
try {
    $keyless->createCheckoutSession($params);
} catch (\InvalidArgumentException $e) {
    $caught = 'InvalidArgumentException';
}
check('a create without an idempotency key throws before the call', $caught, 'InvalidArgumentException');
check('nothing was sent for the keyless create', (string) count($keyless->keysSent), '0');
check('the keyless create leaves no key to read back',
    $keyless->getLastIdempotencyKey() === null ? 'null' : 'set', 'null');
$explicitNull = new FlakyClient();
$caught = 'no throw';
try {
    $explicitNull->createCheckoutSession($params + ['idempotencyKey' => null]);
} catch (\InvalidArgumentException $e) {
    $caught = 'InvalidArgumentException';
}
check('an explicit null key is refused the same way', $caught, 'InvalidArgumentException');

// 2. The order-derived key: "{scope}-{orderId}-{amountMinor}-{CURRENCY}".
check('the derived key has the documented shape',
    DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'EUR'), 'checkout-order-1042-8440-EUR');
check('the currency is uppercased',
    DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'eur'), 'checkout-order-1042-8440-EUR');
check('the same order at the same amount derives the same key (a reload replays)',
    DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'EUR')
        === DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'eur') ? 'same' : 'different', 'same');
check('a changed amount derives a new key',
    DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'EUR')
        !== DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8441, 'EUR') ? 'different' : 'same', 'different');
check('a changed currency derives a new key',
    DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'EUR')
        !== DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'BGN') ? 'different' : 'same', 'different');
check('a different scope derives a new key for the same order',
    DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'EUR')
        !== DominaiteClient::orderIdempotencyKey('renewal', 'order-1042', 8440, 'EUR') ? 'different' : 'same', 'different');

// The derived key goes through the same rules as a hand-written one, so it can never
// be a key the SDK would refuse at send time.
$badParts = [
    'empty scope' => ['', 'order-1042', 8440, 'EUR'],
    'empty orderId' => ['checkout', '', 8440, 'EUR'],
    'zero amount' => ['checkout', 'order-1042', 0, 'EUR'],
    'negative amount' => ['checkout', 'order-1042', -1, 'EUR'],
    'two-letter currency' => ['checkout', 'order-1042', 8440, 'EU'],
    'four-letter currency' => ['checkout', 'order-1042', 8440, 'EURO'],
    'numeric currency' => ['checkout', 'order-1042', 8440, '978'],
    'currency with newline' => ['checkout', 'order-1042', 8440, "EUR\n"],
    'orderId with CRLF' => ['checkout', "order-1\r\nX-Injected: yes", 8440, 'EUR'],
    'non-ascii orderId' => ['checkout', "order-\u{00e9}", 8440, 'EUR'],
    'over 100 characters' => ['checkout', str_repeat('o', 90), 8440, 'EUR'],
];
foreach ($badParts as $label => $parts) {
    $outcome = 'accepted';
    try {
        DominaiteClient::orderIdempotencyKey(...$parts);
    } catch (\InvalidArgumentException $e) {
        $outcome = 'rejected';
    }
    check("derived key refused: $label", $outcome, 'rejected');
}
check('a derived key of exactly 100 characters is accepted',
    (string) strlen(DominaiteClient::orderIdempotencyKey('checkout', str_repeat('o', 100 - strlen('checkout--8440-EUR')), 8440, 'EUR')),
    '100');

// Retrying with the derived key sends that SAME key on every attempt. A second key
// here would be a second real payment for one order.
$retried = new FlakyClient(2);
$derived = DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'EUR');
$attempts = 0;
for ($i = 0; $i < 3; $i++) {
    $attempts++;
    try {
        $retried->createCheckoutSession($params + ['idempotencyKey' => $derived]);
        break;
    } catch (TransportException $e) {
        // retry with the same params, so the same key
    }
}
check('the retry loop ran three attempts', (string) $attempts, '3');
check('every attempt sent the derived key', implode(',', array_unique($retried->keysSent)), $derived);
check('a timed-out attempt leaves its key readable for a generic catch block',
    (string) $retried->getLastIdempotencyKey(), $derived);

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
