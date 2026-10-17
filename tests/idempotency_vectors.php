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
require __DIR__ . '/../src/Exception/ChargeException.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/RefundException.php';
require __DIR__ . '/../src/Exception/RevokeException.php';
require __DIR__ . '/../src/Exception/StorefrontException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ChargeException;
use Dominaite\Exception\RateLimitException;
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
            'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            DominaiteClient::SANDBOX_BASE_URL
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
    'orderId with a space' => ['checkout', 'order 1042', 8440, 'EUR'],
    'scope with a space' => ['web checkout', 'order-1042', 8440, 'EUR'],
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
    'inner space' => 'ORDER 1042 / attempt #2',
    'leading space' => ' order-1',
    'trailing space' => 'order-1 ',
    'only a space' => ' ',
    'DEL' => "order-1\x7F",
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
foreach (['order-1042', 'ORDER-1042/attempt#2', str_repeat('k', 100), '~!@#$%^&*()_+={}[]|:;"<>,.?', '!', '~'] as $good) {
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
        new DominaiteClient($badKeyId, 'dms_0123456789abcdef', DominaiteClient::SANDBOX_BASE_URL);
    } catch (\InvalidArgumentException $e) {
        $outcome = 'rejected locally';
    }
    check("key id with header-breaking bytes rejected: $label", $outcome, 'rejected locally');
}

// 7. The routes that read or delete never touch the key. After any of them the accessor
// still reads the key of the last create, so the catch block that retries it finds it.
final class RouteClient extends DominaiteClient
{
    /** @var list<string> The idempotency key each read or delete signed. */
    public array $readKeys = [];

    public function __construct()
    {
        parent::__construct(
            'dmk_0123456789abcdef',
            'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            DominaiteClient::SANDBOX_BASE_URL
        );
    }

    protected function send(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        if ($method !== 'POST') {
            $this->readKeys[] = $idempotencyKey;
        }
        if ($method === 'DELETE') {
            return $this->readResponse(204, '', []);
        }
        // listPlans() and listWebhookEndpoints() read a bare list. Every other route
        // gets one object that each of their parsers accepts.
        $data = $method === 'GET' && in_array($path, ['/merchant-api/plans', '/merchant-api/webhooks'], true) ? [] : [
            'id' => 'plan_0123456789abcdef', 'refundId' => 're_1', 'transactionId' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
            'currency' => 'EUR', 'refundedAmount' => 0, 'refundableAmount' => 0, 'refunds' => [], 'items' => [],
            'nextCursor' => null,
        ];

        return $this->readResponse($method === 'POST' ? 201 : 200, (string) json_encode(['success' => true, 'data' => $data]), []);
    }
}

$routes = new RouteClient();
$transactionId = '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';
$readsAndDeletes = [
    'ping()' => static function () use ($routes): void {
        $routes->ping();
    },
    'getIntegrationContract()' => static function () use ($routes): void {
        $routes->getIntegrationContract();
    },
    'getStatus()' => static function () use ($routes, $transactionId): void {
        $routes->getStatus($transactionId);
    },
    'getRefund()' => static function () use ($routes, $transactionId): void {
        $routes->getRefund($transactionId, 're_1');
    },
    'listRefunds()' => static function () use ($routes, $transactionId): void {
        $routes->listRefunds($transactionId);
    },
    'listTransactions()' => static function () use ($routes): void {
        $routes->listTransactions(['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z']);
    },
    'revokePaymentMethod()' => static function () use ($routes): void {
        $routes->revokePaymentMethod('pm_0123456789abcdef');
    },
    'listPlans()' => static function () use ($routes): void {
        $routes->listPlans();
    },
    'getPlan()' => static function () use ($routes): void {
        $routes->getPlan('plan_0123456789abcdef');
    },
    'updatePlan() without a key' => static function () use ($routes): void {
        $routes->updatePlan('plan_0123456789abcdef', ['trialDays' => 14]);
    },
    'deletePlan()' => static function () use ($routes): void {
        $routes->deletePlan('plan_0123456789abcdef');
    },
    'getAgreement()' => static function () use ($routes): void {
        $routes->getAgreement('agr_0123456789abcdef');
    },
    'cancelAgreement()' => static function () use ($routes): void {
        $routes->cancelAgreement('agr_0123456789abcdef');
    },
    'listWebhookEndpoints()' => static function () use ($routes): void {
        $routes->listWebhookEndpoints();
    },
    'deleteWebhookEndpoint()' => static function () use ($routes): void {
        $routes->deleteWebhookEndpoint('3f2c9a1e-0b7d-4c55-9a8e-1d2f3a4b5c6d');
    },
];
foreach ($readsAndDeletes as $label => $call) {
    $routes->createPlan(['name' => 'Gold', 'amount' => 2500, 'currency' => 'EUR', 'intervalUnit' => 'month',
        'intervalCount' => 1, 'idempotencyKey' => 'plan-gold']);
    try {
        $call();
        $outcome = 'returned';
    } catch (\Throwable $e) {
        $outcome = get_class($e) . ': ' . $e->getMessage();
    }
    check("$label leaves the key of the last create", $outcome . ', key ' . (string) $routes->getLastIdempotencyKey(),
        'returned, key plan-gold');
}
check('every read and delete signed an empty key',
    count($routes->readKeys) . ' ' . json_encode(array_values(array_unique($routes->readKeys))), '15 [""]');

// --- Automatic retries ------------------------------------------------------------------
// The client sends a failed attempt again on its own. The contract: only what the API can
// replay (a GET, or a POST with a key, but never webhook create), only after no answer, a
// 5xx or PAYMENT_PROCESSING_UNAVAILABLE, and with the same key, path and body every time.

/**
 * Stands in for the network below send(): each attempt takes the next canned answer and
 * records what the SDK put on the wire. The waits and the random draws are recorded too,
 * so no test sleeps.
 */
final class WireClient extends DominaiteClient
{
    /** @var list<array{status:int,body:string,headers:array<string,string>,error:?string,oversized:bool}> */
    public array $answers = [];
    /** @var list<array{method:string,url:string,headers:array<string,string>,payload:?string}> */
    public array $sent = [];
    /** @var list<int> */
    public array $slept = [];
    /** @var list<int> The $max of every random draw. */
    public array $drawMax = [];
    /** 'max' returns the top of the range, 'real' uses the SDK's own random_int() draw. */
    public string $draw = 'max';

    /** @param array<string,mixed> $options */
    public function __construct(array $options = [])
    {
        parent::__construct(
            'dmk_0123456789abcdef',
            'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            DominaiteClient::SANDBOX_BASE_URL,
            $options
        );
    }

    protected function exchange(string $method, string $url, array $headers, ?string $payload): array
    {
        $named = [];
        foreach ($headers as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $named[$name] = $value;
        }
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $named, 'payload' => $payload];
        $answer = array_shift($this->answers);
        if ($answer === null) {
            throw new \LogicException('no canned answer left for attempt ' . count($this->sent));
        }

        return $answer;
    }

    protected function sleepMs(int $milliseconds): void
    {
        $this->slept[] = $milliseconds;
    }

    protected function randomMs(int $max): int
    {
        $this->drawMax[] = $max;

        return $this->draw === 'max' ? $max : parent::randomMs($max);
    }
}

/** @return array{status:int,body:string,headers:array<string,string>,error:?string,oversized:bool} */
function answer(int $status, string $body, array $headers = []): array
{
    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'error' => null, 'oversized' => false];
}

/** @return array{status:int,body:string,headers:array<string,string>,error:?string,oversized:bool} */
function noAnswer(): array
{
    return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'Operation timed out after 15000 milliseconds',
        'oversized' => false];
}

/** @param array<string,mixed> $data */
function okBody(array $data, string $requestId = 'req_ok'): string
{
    return (string) json_encode(['success' => true, 'data' => $data, 'metadata' => ['requestId' => $requestId]]);
}

function errorBody(string $code, string $requestId = 'req_err'): string
{
    return (string) json_encode(['success' => false, 'error' => ['code' => $code, 'message' => 'x'],
        'metadata' => ['requestId' => $requestId]]);
}

/** @return string The class of what the call threw, or 'returned'. */
function outcomeOf(callable $fn): string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return get_class($e);
    }

    return 'returned';
}

/** Whether every attempt carried the same key, path and body, each signed with its own timestamp. */
function sameSignedInput(WireClient $client, string $method, string $path): string
{
    $seen = [];
    foreach ($client->sent as $i => $attempt) {
        $h = $attempt['headers'];
        $expected = DominaiteClient::signRequest(
            'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            $h['X-Timestamp'], $method, $path, $h['Idempotency-Key'] ?? '', (string) $attempt['payload']
        );
        if (!hash_equals($expected, $h['X-Signature'])) {
            return "attempt $i has a signature that does not match its input";
        }
        $seen[] = json_encode([$attempt['url'], $h['Idempotency-Key'] ?? '', $attempt['payload']]);
    }

    return count(array_unique($seen)) === 1 ? 'same' : 'differs: ' . implode(' | ', $seen);
}

$pm = 'pm_' . str_repeat('a', 32);
$chargeData = ['chargeId' => 'ch_33333333333343338333333333333333', 'status' => 'succeeded',
    'transactionId' => '33333333-3333-4333-8333-333333333333'];

// A charge that timed out, then went through: one charge, two attempts, one key.
$wire = new WireClient();
$wire->answers = [noAnswer(), answer(201, okBody($chargeData, 'req_charge'))];
$charged = $wire->chargePaymentMethod($pm, ['amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-7',
    'idempotencyKey' => 'charge-order-7']);
check('a charge after a transport failure is retried and returns the charge',
    count($wire->sent) . ' ' . $charged['chargeId'], '2 ch_33333333333343338333333333333333');
check('the charge retry reuses the key, the path and the body',
    sameSignedInput($wire, 'POST', '/merchant-api/payment-methods/' . $pm . '/charges'), 'same');
check('the charge retry sends the caller key', (string) ($wire->sent[1]['headers']['Idempotency-Key'] ?? ''), 'charge-order-7');
check('the charge result reads the request id of the attempt that answered', (string) $wire->getLastRequestId(), 'req_charge');
check('one wait before the one retry, inside 0..500 ms', json_encode([$wire->drawMax, $wire->slept]), '[[500],[500]]');

// A refund that timed out, then went through.
$refundTx = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
$wire = new WireClient();
$wire->answers = [noAnswer(), answer(201, okBody(['refundId' => 're_7c1e9a2b4d6f48a0b3c5d7e9f1a2b3c4',
    'transactionId' => $refundTx, 'status' => 'pending', 'amount' => 2500, 'currency' => 'EUR']))];
$refunded = $wire->createRefund($refundTx, ['amount' => 2500, 'idempotencyKey' => 'refund-order-7']);
check('a refund after a transport failure is retried and returns the refund',
    count($wire->sent) . ' ' . $refunded['refundId'], '2 re_7c1e9a2b4d6f48a0b3c5d7e9f1a2b3c4');
check('the refund retry reuses the key, the path and the body',
    sameSignedInput($wire, 'POST', '/merchant-api/payments/' . $refundTx . '/refunds'), 'same');
check('the refund retry sends the caller key', (string) ($wire->sent[1]['headers']['Idempotency-Key'] ?? ''), 'refund-order-7');

// maxRetries 0 sends once.
$wire = new WireClient(['maxRetries' => 0]);
$wire->answers = [noAnswer(), answer(201, okBody($chargeData))];
check('maxRetries 0: a transport failure is thrown after one attempt',
    outcomeOf(static function () use ($wire, $pm): void {
        $wire->chargePaymentMethod($pm, ['amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-8',
            'idempotencyKey' => 'charge-order-8']);
    }) . ' ' . count($wire->sent) . ' ' . count($wire->slept),
    TransportException::class . ' 1 0');

// A 429 is never retried, and the Retry-After survives.
$wire = new WireClient();
$wire->answers = [answer(429, errorBody('RATE_LIMITED'), ['retry-after' => '7']), answer(200, okBody(['ok' => true]))];
$limited = null;
try {
    $wire->listTransactions([]);
} catch (RateLimitException $e) {
    $limited = $e;
}
check('a 429 is not retried', count($wire->sent) . ' ' . count($wire->slept), '1 0');
check('the 429 keeps its Retry-After', $limited === null ? 'no exception' : (string) $limited->getRetryAfterSeconds(), '7');

// The backoff: full jitter, 0..min(8 s, 500 ms x 2^n) before retry n.
$wire = new WireClient(['maxRetries' => 6]);
$wire->answers = array_fill(0, 7, answer(503, errorBody('MERCHANT_API_UNAVAILABLE')));
check('a 503 is retried until maxRetries runs out, then thrown',
    outcomeOf(static function () use ($wire): void { $wire->listTransactions([]); }) . ' ' . count($wire->sent),
    TransportException::class . ' 7');
check('the backoff ceiling doubles from 500 ms and stops at 8 s',
    json_encode($wire->drawMax), '[500,1000,2000,4000,8000,8000]');
check('with the top draw each wait is the ceiling', json_encode($wire->slept), '[500,1000,2000,4000,8000,8000]');

$inside = 'inside';
for ($round = 0; $round < 50; $round++) {
    $wire = new WireClient(['maxRetries' => 6]);
    $wire->draw = 'real';
    $wire->answers = array_fill(0, 7, noAnswer());
    try {
        $wire->listTransactions([]);
    } catch (TransportException $e) {
    }
    foreach ($wire->slept as $n => $waited) {
        $ceiling = min(8000, 500 * 2 ** $n);
        if ($waited < 0 || $waited > $ceiling || $wire->drawMax[$n] !== $ceiling) {
            $inside = "retry $n waited $waited ms, ceiling $ceiling";
        }
    }
}
check('real random waits stay inside 0..ceiling', $inside, 'inside');

// What is not retried.
$wire = new WireClient();
$wire->answers = [noAnswer(), answer(201, okBody(['id' => 'we_1', 'secret' => 'whsec_1']))];
check('webhook endpoint create is sent once, even after a transport failure',
    outcomeOf(static function () use ($wire): void {
        $wire->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => ['payment.succeeded'],
            'idempotencyKey' => 'hook-1']);
    }) . ' ' . count($wire->sent),
    TransportException::class . ' 1');

$wire = new WireClient();
$wire->answers = [['status' => 200, 'body' => '', 'headers' => [], 'error' => null, 'oversized' => true],
    answer(200, okBody(['items' => []]))];
check('an oversized answer is not retried',
    outcomeOf(static function () use ($wire): void { $wire->listTransactions([]); }) . ' ' . count($wire->sent),
    TransportException::class . ' 1');

foreach (['CHARGE_OUTCOME_UNKNOWN' => 502, 'CHARGE_FAILED' => 502, 'PAYMENT_METHOD_CHARGES_DISABLED' => 503] as $code => $status) {
    $wire = new WireClient();
    $wire->answers = [answer($status, errorBody($code)), answer(201, okBody($chargeData))];
    check("a $status $code charge is not retried",
        outcomeOf(static function () use ($wire, $pm): void {
            $wire->chargePaymentMethod($pm, ['amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-9',
                'idempotencyKey' => 'charge-order-9']);
        }) . ' ' . count($wire->sent),
        ChargeException::class . ' 1');
}

$wire = new WireClient();
$wire->answers = [answer(502, errorBody('UPSTREAM_CONTRACT_ERROR')), answer(204, '')];
check('a 502 UPSTREAM_CONTRACT_ERROR is not retried, and DELETE is not retried at all',
    outcomeOf(static function () use ($wire, $pm): void { $wire->revokePaymentMethod($pm); }) . ' ' . count($wire->sent),
    \Dominaite\Exception\RevokeException::class . ' 1');
$wire = new WireClient();
$wire->answers = [noAnswer(), answer(204, '')];
check('DELETE is not retried after a transport failure',
    outcomeOf(static function () use ($wire, $pm): void { $wire->revokePaymentMethod($pm); }) . ' ' . count($wire->sent),
    TransportException::class . ' 1');
$wire = new WireClient();
$wire->answers = [noAnswer(), answer(200, okBody(['planId' => 'plan_1']))];
check('PATCH is not retried after a transport failure',
    outcomeOf(static function () use ($wire): void {
        $wire->updatePlan('plan_0123456789abcdef0123456789abcdef', ['name' => 'Gold+']);
    }) . ' ' . count($wire->sent),
    TransportException::class . ' 1');

$wire = new WireClient();
$wire->answers = [answer(400, errorBody('VALIDATION_ERROR')), answer(200, okBody(['items' => []]))];
check('a 4xx is not retried',
    outcomeOf(static function () use ($wire): void { $wire->listTransactions([]); }) . ' ' . count($wire->sent),
    \Dominaite\Exception\ApiException::class . ' 1');

// What is retried besides a lost answer.
$wire = new WireClient();
$wire->answers = [answer(502, "<html>Bad Gateway</html>"), answer(200, okBody(['status' => 'pending',
    'transactionId' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0'], 'req_status'))];
$wire->getStatus('0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0');
check('an HTML 502 on a GET is retried', count($wire->sent) . ' ' . $wire->getLastRequestId(), '2 req_status');

$sessionParams = ['amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042', 'idempotencyKey' => 'order-1042'];
$wire = new WireClient();
$wire->answers = [
    answer(200, (string) json_encode(['success' => false, 'errorCode' => 'PAYMENT_PROCESSING_UNAVAILABLE',
        'errorMessage' => 'try later'])),
    answer(200, (string) json_encode(['success' => true, 'checkout' => [
        'transactionId' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', 'orderId' => 'ord_9', 'cashierKey' => 'ck_9',
        'cashierToken' => 'ct_9', 'amount' => 8440, 'currency' => 'EUR', 'expiresAt' => '2026-08-20T16:00:00Z',
    ]])),
];
$session = $wire->createCheckoutSession($sessionParams);
check('a 200 PAYMENT_PROCESSING_UNAVAILABLE refusal is retried with the same key',
    count($wire->sent) . ' ' . ($session['checkout']['transactionId'] ?? $session['transactionId'] ?? '?') . ' '
        . sameSignedInput($wire, 'POST', '/merchant-api/checkout/sessions'),
    '2 0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0 same');

$wire = new WireClient();
$wire->answers = [answer(503, errorBody('PAYMENT_PROCESSING_UNAVAILABLE')), answer(201, okBody($chargeData))];
$wire->chargePaymentMethod($pm, ['amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-10',
    'idempotencyKey' => 'charge-order-10']);
check('a 503 PAYMENT_PROCESSING_UNAVAILABLE charge is retried', (string) count($wire->sent), '2');

// The options.
foreach ([['maxRetries' => -1], ['maxRetries' => '2'], ['maxRetries' => 1.0], ['timeout' => 0], ['timeout' => -1],
    ['timeout' => '15'], ['timeout' => INF]] as $bad) {
    check('option ' . json_encode($bad, JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR) . ' is refused',
        outcomeOf(static function () use ($bad): void { new WireClient($bad); }), \InvalidArgumentException::class);
}
foreach ([['maxRetries' => 0], ['maxRetries' => 5], ['timeout' => 30], ['timeout' => 2.5]] as $good) {
    check('option ' . json_encode($good) . ' is accepted',
        outcomeOf(static function () use ($good): void { new WireClient($good); }), 'returned');
}

/** The per-attempt limit curl gets, in milliseconds. No public getter exists; the default is documented. */
function timeoutMsOf(DominaiteClient $client): int
{
    $property = new \ReflectionProperty(DominaiteClient::class, 'timeoutMs');
    $property->setAccessible(true);

    return (int) $property->getValue($client);
}

check('the default timeout is 45 seconds per attempt', (string) timeoutMsOf(new WireClient()), '45000');
check('the timeout option sets the per-attempt limit', (string) timeoutMsOf(new WireClient(['timeout' => 2.5])), '2500');

exit($failures === 0 ? 0 : 1);
