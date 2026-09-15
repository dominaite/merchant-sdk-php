<?php
// Dependency-free tests for stored payment methods: saveCard on a session, the charge
// body and key handling, declines as results, refusals as exceptions, and the id guard
// that keeps a payment method id a single path segment of the signed canonical path.
// Run: php tests/payment_method_vectors.php - exits non-zero on any mismatch.
//
// tests/transport_vectors.php covers the same two routes end to end through real curl,
// including the signature header bytes.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/AuthenticationException.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ApiException;
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\TransportException;

$failures = 0;

set_exception_handler(static function (\Throwable $e): void {
    echo 'FAIL uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

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

const KEY_ID = 'dmk_0123456789abcdef';
const SECRET = 'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const PAYMENT_METHOD_ID = 'pm_0123456789abcdef0123456789abcdef';
const CHARGE_BODY = '{"amount":2500,"currency":"EUR","orderReference":"order-1043"}';
const CHARGE_KEY = '00000000-0000-4000-8000-000000000003';

/** Records what reached the transport and answers with a canned payload. */
final class RecordingClient extends DominaiteClient
{
    /** @var list<array{method:string,path:string,body:?array<string,mixed>,key:string}> */
    public array $calls = [];

    /** @var array<string,mixed> */
    private array $canned;

    /** @param array<string,mixed> $canned */
    public function __construct(array $canned)
    {
        parent::__construct(KEY_ID, SECRET);
        $this->canned = $canned;
    }

    protected function request(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'key' => $idempotencyKey];

        return $this->canned;
    }
}

/** Exposes the response handling so a canned status + body can be pushed through it. */
final class ResponseClient extends DominaiteClient
{
    /** @return array<string,mixed> */
    public function handle(int $status, string $raw): array
    {
        return $this->handleResponse($status, $raw, []);
    }
}

/** @return string The class name of the thrown exception, or 'no exception'. */
function thrownBy(callable $fn): string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return get_class($e);
    }

    return 'no exception';
}

/** The exact bytes request() would sign and send for a body. */
function encode(?array $body): string
{
    return $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

$checkout = [
    'success' => true,
    'checkout' => [
        'transactionId' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0', 'orderId' => 'ord_1',
        'cashierKey' => 'ck', 'cashierToken' => 'ct', 'amount' => 2500, 'currency' => 'EUR',
        'expiresAt' => '2026-01-01T00:00:00Z',
    ],
];
$charge = ['chargeId' => 'chg_1', 'status' => 'succeeded', 'declineClass' => null, 'declineCode' => null,
    'transactionId' => '33333333-3333-4333-8333-333333333333'];
$sessionParams = ['amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-1042',
    'idempotencyKey' => '00000000-0000-4000-8000-000000000001'];
$chargeParams = ['amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-1043', 'idempotencyKey' => CHARGE_KEY];

// --- saveCard -------------------------------------------------------------------------
$client = new RecordingClient($checkout);
$client->createCheckoutSession($sessionParams + ['saveCard' => true]);
check('saveCard is sent in the session body', var_export($client->calls[0]['body']['saveCard'] ?? null, true), 'true');
check('saveCard does not disturb the idempotency key', $client->calls[0]['key'], '00000000-0000-4000-8000-000000000001');
check('idempotencyKey never leaks into the body', array_key_exists('idempotencyKey', $client->calls[0]['body']) ? 'leaked' : 'absent', 'absent');

// A session without saveCard keeps the exact vector body: the flag is omitted, not sent
// as false, so the signed bytes of every existing integration do not move.
$client = new RecordingClient($checkout);
$client->createCheckoutSession($sessionParams);
check('a session without saveCard keeps the vector body', encode($client->calls[0]['body']),
    '{"amount":2500,"currency":"EUR","orderReference":"order-1042"}');

check('a non-bool saveCard is rejected before the call',
    thrownBy(static function () use ($checkout, $sessionParams): void {
        (new RecordingClient($checkout))->createCheckoutSession($sessionParams + ['saveCard' => 'yes']);
    }), \InvalidArgumentException::class);

// --- chargePaymentMethod: the signed body ------------------------------------------------
$client = new RecordingClient($charge);
$result = $client->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
check('charge returns the payload', (string) $result['chargeId'], 'chg_1');
check('charge is a POST on the payment-methods path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'POST ' . DominaiteClient::PAYMENT_METHODS_PATH . '/' . PAYMENT_METHOD_ID . '/charges');
check('charge body is the vector body byte for byte', encode($client->calls[0]['body']), CHARGE_BODY);
check('charge sends the caller idempotency key', $client->calls[0]['key'], CHARGE_KEY);
check('charge idempotency key is readable afterwards', (string) $client->getLastIdempotencyKey(), CHARGE_KEY);

// Only the contract's fields reach the body, in the contract's order, description last.
$client = new RecordingClient($charge);
$client->chargePaymentMethod(PAYMENT_METHOD_ID, [
    'orderReference' => 'order-1043', 'description' => 'Monthly plan', 'currency' => 'EUR', 'amount' => 2500,
    'customer' => ['email' => 'not-on-this-route'],
]);
check('charge body is built field by field in contract order', encode($client->calls[0]['body']),
    '{"amount":2500,"currency":"EUR","orderReference":"order-1043","description":"Monthly plan"}');
check('charge generates an idempotency key when the caller omits one',
    preg_match('/^[0-9a-f]{32}$/', $client->calls[0]['key']) === 1 ? 'generated' : $client->calls[0]['key'], 'generated');
check('the generated charge key is readable afterwards',
    $client->getLastIdempotencyKey() === $client->calls[0]['key'] ? 'same key' : 'lost', 'same key');

// --- chargePaymentMethod: declines are results, refusals are exceptions -----------------
$declined = $charge;
$declined['chargeId'] = 'chg_2';
$declined['status'] = 'failed';
$declined['declineClass'] = 'soft_funds';
$declined['declineCode'] = '51';
$result = (new RecordingClient($declined))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
check('a declined charge is returned, not thrown', (string) $result['status'], 'failed');
check('a declined charge carries its class and code', $result['declineClass'] . '/' . $result['declineCode'], 'soft_funds/51');

$refused = null;
try {
    (new RecordingClient([
        'success' => false, 'errorCode' => 'ALREADY_PROCESSED', 'errorMessage' => 'Already charged',
        'transactionId' => '33333333-3333-4333-8333-333333333333',
    ]))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
} catch (CheckoutRefusedException $e) {
    $refused = $e;
}
check('a refused charge throws CheckoutRefusedException', $refused === null ? 'no exception' : get_class($refused), CheckoutRefusedException::class);
check('the refusal carries the code', $refused === null ? '' : $refused->getErrorCode(), 'ALREADY_PROCESSED');
check('the refusal names the transaction', $refused === null ? '' : (string) $refused->getTransactionId(), '33333333-3333-4333-8333-333333333333');

// A 200 without a chargeId is not a charge either, whatever success says.
check('a payload without a chargeId is a refusal',
    thrownBy(static function () use ($chargeParams): void {
        (new RecordingClient(['success' => true]))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
    }), CheckoutRefusedException::class);

// --- chargePaymentMethod: validation, before anything is signed -------------------------
$cases = [
    'float amount' => ['amount' => 25.5],
    'zero amount' => ['amount' => 0],
    'string amount' => ['amount' => '2500'],
    'empty orderReference' => ['orderReference' => ''],
    'orderReference too long' => ['orderReference' => str_repeat('x', 101)],
    'non-string description' => ['description' => 7],
    'empty idempotencyKey' => ['idempotencyKey' => ''],
    'idempotencyKey too long' => ['idempotencyKey' => str_repeat('k', 101)],
    'idempotencyKey with CRLF' => ['idempotencyKey' => "order-1\r\nX-Forwarded-For: 1.2.3.4"],
];
foreach ($cases as $label => $override) {
    $client = new RecordingClient($charge);
    check("charge rejects $label locally",
        thrownBy(static function () use ($client, $chargeParams, $override): void {
            $client->chargePaymentMethod(PAYMENT_METHOD_ID, array_merge($chargeParams, $override));
        }), \InvalidArgumentException::class);
    check("charge with $label never reaches the transport", (string) count($client->calls), '0');
    check("charge with $label leaves no stale key", $client->getLastIdempotencyKey() === null ? 'null' : 'set', 'null');
}
foreach (['amount', 'currency', 'orderReference'] as $required) {
    $params = $chargeParams;
    unset($params[$required]);
    check("charge requires $required",
        thrownBy(static function () use ($charge, $params): void {
            (new RecordingClient($charge))->chargePaymentMethod(PAYMENT_METHOD_ID, $params);
        }), \InvalidArgumentException::class);
}

// The id goes into the signed canonical path verbatim, so anything that is not one
// path segment is refused before signing - for both routes.
$client = new RecordingClient($charge);
foreach (['', ' ', 'pm_1/charges', 'pm_1?x=1', 'pm_1#f', 'pm 1', 'pm_1%2F', str_repeat('p', 101)] as $bad) {
    $label = var_export($bad, true);
    check("charge refuses payment method id $label",
        thrownBy(static function () use ($client, $chargeParams, $bad): void {
            $client->chargePaymentMethod($bad, $chargeParams);
        }), \InvalidArgumentException::class);
    check("revoke refuses payment method id $label",
        thrownBy(static function () use ($client, $bad): void {
            $client->revokePaymentMethod($bad);
        }), \InvalidArgumentException::class);
}
check('no bad id reached the transport', (string) count($client->calls), '0');

// Surrounding whitespace is trimmed, the same courtesy getStatus() extends to a UUID.
$client = new RecordingClient($charge);
$client->chargePaymentMethod(' ' . PAYMENT_METHOD_ID . ' ', $chargeParams);
check('a padded id is trimmed', $client->calls[0]['path'], DominaiteClient::PAYMENT_METHODS_PATH . '/' . PAYMENT_METHOD_ID . '/charges');

// --- revokePaymentMethod ---------------------------------------------------------------
$client = new RecordingClient($charge);
$client->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
$outcome = 'returned';
try {
    $returned = $client->revokePaymentMethod(PAYMENT_METHOD_ID);
    $outcome = $returned === null ? 'returned null' : 'returned a value';
} catch (\Throwable $e) {
    $outcome = get_class($e);
}
check('revoke returns nothing', $outcome, 'returned null');
check('revoke is a DELETE on the payment-methods path', $client->calls[1]['method'] . ' ' . $client->calls[1]['path'],
    'DELETE ' . DominaiteClient::PAYMENT_METHODS_PATH . '/' . PAYMENT_METHOD_ID);
check('revoke signs an empty body', $client->calls[1]['body'] === null ? 'null' : 'body', 'null');
check('revoke signs an empty idempotency key', $client->calls[1]['key'], '');
check('revoke leaves the last charge key untouched', (string) $client->getLastIdempotencyKey(), CHARGE_KEY);

// --- the response handling behind both routes -------------------------------------------
$responses = new ResponseClient(KEY_ID, SECRET);
check('a 204 with no body is a success with nothing to parse', (string) count($responses->handle(204, '')), '0');
check('a 201 charge body is handed back',
    (string) ($responses->handle(201, '{"chargeId":"chg_1","status":"pending","transactionId":"t"}')['status'] ?? ''), 'pending');
check('a 201 charge inside the envelope is unwrapped',
    (string) ($responses->handle(201, '{"success":true,"data":{"chargeId":"chg_1","status":"failed","declineClass":"hard","transactionId":"t"}}')['declineClass'] ?? ''), 'hard');

$notFound = null;
try {
    $responses->handle(404, '{"success":false,"error":{"code":"NOT_FOUND","message":"No such payment method"}}');
} catch (ApiException $e) {
    $notFound = $e;
}
check('an id that is not yours is an ApiException', $notFound === null ? 'no exception' : get_class($notFound), ApiException::class);
check('the 404 keeps its status', (string) ($notFound === null ? 0 : $notFound->getHttpStatus()), '404');
check('a 503 on a charge is still a retryable transport error',
    thrownBy(static function () use ($responses): void { $responses->handle(503, ''); }), TransportException::class);

exit($failures === 0 ? 0 : 1);
