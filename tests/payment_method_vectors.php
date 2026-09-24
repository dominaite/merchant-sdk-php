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
require __DIR__ . '/../src/Exception/ChargeException.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/RevokeException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ApiException;
use Dominaite\Exception\ChargeException;
use Dominaite\Exception\RevokeException;
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

/**
 * Records what reached the transport and answers with a canned gateway body under a
 * canned HTTP status, pushed through the real response parser.
 */
final class RecordingClient extends DominaiteClient
{
    /** @var list<array{method:string,path:string,body:?array<string,mixed>,key:string}> */
    public array $calls = [];

    /** @var array<string,mixed> */
    private array $canned;
    private int $status;

    /** @param array<string,mixed> $canned */
    public function __construct(array $canned, int $status = 200)
    {
        parent::__construct(KEY_ID, SECRET);
        $this->canned = $canned;
        $this->status = $status;
    }

    protected function send(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'key' => $idempotencyKey];
        $raw = $this->status === 204 ? '' : (string) json_encode($this->canned);

        return $this->readResponse($this->status, $raw, []);
    }
}

/** Exposes the generic response handling so a canned status + body can be pushed through it. */
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
// The gateway's envelope for a placed charge, as it goes over the wire: declineClass
// and declineCode are omitted, not null.
$charge = ['success' => true, 'data' => [
    'chargeId' => 'ch_33333333333343338333333333333333', 'status' => 'succeeded',
    'transactionId' => '33333333-3333-4333-8333-333333333333',
], 'metadata' => ['requestId' => 'r', 'timestamp' => 't', 'apiVersion' => '1.0', 'processingTimeMs' => 1]];
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
$client = new RecordingClient($charge, 201);
$result = $client->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
check('charge returns the unwrapped charge', (string) $result['chargeId'], 'ch_33333333333343338333333333333333');
check('charge reads an omitted declineClass as null, present',
    array_key_exists('declineClass', $result) ? var_export($result['declineClass'], true) : 'absent', 'NULL');
check('charge reads an omitted declineCode as null, present',
    array_key_exists('declineCode', $result) ? var_export($result['declineCode'], true) : 'absent', 'NULL');
check('charge is a POST on the payment-methods path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'POST ' . DominaiteClient::PAYMENT_METHODS_PATH . '/' . PAYMENT_METHOD_ID . '/charges');
check('charge body is the vector body byte for byte', encode($client->calls[0]['body']), CHARGE_BODY);
check('charge sends the caller idempotency key', $client->calls[0]['key'], CHARGE_KEY);
check('charge idempotency key is readable afterwards', (string) $client->getLastIdempotencyKey(), CHARGE_KEY);

// Only the contract's fields reach the body, in the contract's order, description last.
$client = new RecordingClient($charge);
$client->chargePaymentMethod(PAYMENT_METHOD_ID, [
    'orderReference' => 'order-1043', 'description' => 'Monthly plan', 'currency' => 'EUR', 'amount' => 2500,
    'customer' => ['email' => 'not-on-this-route'], 'idempotencyKey' => CHARGE_KEY,
]);
check('charge body is built field by field in contract order', encode($client->calls[0]['body']),
    '{"amount":2500,"currency":"EUR","orderReference":"order-1043","description":"Monthly plan"}');
check('the idempotency key rides the header, never the charge body',
    array_key_exists('idempotencyKey', $client->calls[0]['body']) ? 'leaked' : 'absent', 'absent');

// --- chargePaymentMethod: declines are results, refusals are typed exceptions ----------
// A 402 says success=false and CHARGE_DECLINED, but the charge is right there with its
// decline class: it comes back as a result.
$declined = ['success' => false, 'data' => [
    'chargeId' => 'ch_33333333333343338333333333333334', 'status' => 'failed',
    'declineClass' => 'soft_funds', 'declineCode' => '51',
    'transactionId' => '33333333-3333-4333-8333-333333333334',
], 'error' => ['message' => 'The payment provider declined the charge.', 'code' => 'CHARGE_DECLINED', 'statusCode' => 402]];
$result = (new RecordingClient($declined, 402))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
check('a 402 decline is returned, not thrown', (string) $result['status'], 'failed');
check('a declined charge carries its class and code', $result['declineClass'] . '/' . $result['declineCode'], 'soft_funds/51');

// A durable replay answers 200 with the first body; still the charge.
$result = (new RecordingClient($charge, 200))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
check('a 200 replay is returned as the charge', (string) $result['chargeId'], 'ch_33333333333343338333333333333333');

// 502 CHARGE_OUTCOME_UNKNOWN: the charge may have happened. The exception carries the
// row the gateway attached, so the caller can poll instead of retrying under a new key.
$unknown = ['success' => false, 'data' => [
    'chargeId' => 'ch_33333333333343338333333333333335', 'status' => 'pending',
    'transactionId' => '33333333-3333-4333-8333-333333333335',
], 'error' => ['message' => 'The payment provider gave no verdict.', 'code' => 'CHARGE_OUTCOME_UNKNOWN', 'statusCode' => 502]];
$thrown = null;
try {
    (new RecordingClient($unknown, 502))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
} catch (ChargeException $e) {
    $thrown = $e;
}
check('CHARGE_OUTCOME_UNKNOWN throws ChargeException', $thrown === null ? 'no exception' : get_class($thrown), ChargeException::class);
check('the charge exception keeps the status', (string) ($thrown === null ? 0 : $thrown->getHttpStatus()), '502');
check('the charge exception keeps the code', $thrown === null ? '' : $thrown->getErrorCode(), 'CHARGE_OUTCOME_UNKNOWN');
check('the charge exception keeps the message', $thrown === null ? '' : $thrown->getMessage(), 'The payment provider gave no verdict.');
check('the charge exception attaches the charge row', $thrown === null ? '' : (string) ($thrown->getCharge()['chargeId'] ?? ''), 'ch_33333333333343338333333333333335');
check('the attached row has declineClass present and null',
    $thrown !== null && is_array($thrown->getCharge()) && array_key_exists('declineClass', $thrown->getCharge()) ? var_export($thrown->getCharge()['declineClass'], true) : 'absent', 'NULL');
check('the charge exception names the transaction to poll', $thrown === null ? '' : (string) $thrown->getTransactionId(), '33333333-3333-4333-8333-333333333335');
check('the charge exception keeps the whole envelope', $thrown === null ? '' : implode(',', array_keys($thrown->getResult())), 'success,data,error');

// 409, 422, 503 carry no data: the same exception without a charge row.
foreach ([409 => 'PAYMENT_METHOD_NOT_ACTIVE', 422 => 'IDEMPOTENCY_KEY_REUSED', 503 => 'PAYMENT_METHOD_CHARGES_DISABLED'] as $status => $code) {
    $thrown = null;
    try {
        (new RecordingClient(['success' => false, 'error' => ['message' => 'Refused.', 'code' => $code, 'statusCode' => $status]], $status))
            ->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
    } catch (ChargeException $e) {
        $thrown = $e;
    }
    check("$status $code throws ChargeException", $thrown === null ? 'no exception' : get_class($thrown), ChargeException::class);
    check("$status $code keeps status and code", $thrown === null ? '' : $thrown->getHttpStatus() . ' ' . $thrown->getErrorCode(), "$status $code");
    check("$status $code attaches no charge row", $thrown === null || $thrown->getCharge() !== null ? 'attached' : 'null', 'null');
    check("$status $code has no transaction to poll", $thrown !== null && $thrown->getTransactionId() === null ? 'null' : 'set', 'null');
}

// The generic statuses keep their generic exceptions, code and all.
$missing = null;
try {
    (new RecordingClient(['success' => false, 'error' => ['message' => 'No stored payment method with this id.', 'code' => 'PAYMENT_METHOD_NOT_FOUND', 'statusCode' => 404]], 404))
        ->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
} catch (ApiException $e) {
    $missing = $e;
}
check('a 404 on a charge is an ApiException', $missing === null ? 'no exception' : get_class($missing), ApiException::class);
check('the charge 404 keeps its code', $missing === null ? '' : (string) $missing->getErrorCode(), 'PAYMENT_METHOD_NOT_FOUND');
check('a 400 on a charge is an ApiException',
    thrownBy(static function () use ($chargeParams): void {
        (new RecordingClient(['success' => false, 'error' => ['message' => 'Validation failed', 'code' => 'VALIDATION_ERROR', 'statusCode' => 400]], 400))
            ->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
    }), ApiException::class);
check('a codeless 503 on a charge is still a retryable transport error',
    thrownBy(static function () use ($chargeParams): void {
        (new RecordingClient(['success' => false], 503))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
    }), TransportException::class);

// A 503 that carries PAYMENT_PROCESSING_UNAVAILABLE must stay on the retry path on both
// routes. On a charge it is the typed exception whose code says "nothing charged, retry
// later with the same key"; on a session create, where the gateway normally answers this
// code as a 200 refusal, a 503 is the retryable transport error. Neither may become a
// generic ApiException, which reads as "your request is wrong, do not retry".
$unavailable = ['success' => false, 'error' => ['message' => 'Card payments are not available right now.',
    'code' => DominaiteClient::PAYMENT_PROCESSING_UNAVAILABLE, 'statusCode' => 503]];
$thrown = null;
try {
    (new RecordingClient($unavailable, 503))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
} catch (\Throwable $e) {
    $thrown = $e;
}
check('a charge 503 PAYMENT_PROCESSING_UNAVAILABLE is a ChargeException',
    $thrown === null ? 'no exception' : get_class($thrown), ChargeException::class);
check('the charge 503 keeps its code for the retry decision',
    $thrown instanceof ChargeException ? $thrown->getHttpStatus() . ' ' . $thrown->getErrorCode() : '',
    '503 PAYMENT_PROCESSING_UNAVAILABLE');
check('a session 503 PAYMENT_PROCESSING_UNAVAILABLE is a retryable transport error',
    thrownBy(static function () use ($unavailable, $sessionParams): void {
        (new RecordingClient($unavailable, 503))->createCheckoutSession($sessionParams);
    }), TransportException::class);

// A 2xx without a chargeId is not a charge either, whatever success says.
check('a 201 without a charge body is an ApiException',
    thrownBy(static function () use ($chargeParams): void {
        (new RecordingClient(['success' => true], 201))->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
    }), ApiException::class);

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
foreach (['amount', 'currency', 'orderReference', 'idempotencyKey'] as $required) {
    $params = $chargeParams;
    unset($params[$required]);
    $client = new RecordingClient($charge);
    check("charge requires $required",
        thrownBy(static function () use ($client, $params): void {
            $client->chargePaymentMethod(PAYMENT_METHOD_ID, $params);
        }), \InvalidArgumentException::class);
    check("charge without $required never reaches the transport", (string) count($client->calls), '0');
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
$client = new RecordingClient($charge, 201);
$client->chargePaymentMethod(' ' . PAYMENT_METHOD_ID . ' ', $chargeParams);
check('a padded id is trimmed', $client->calls[0]['path'], DominaiteClient::PAYMENT_METHODS_PATH . '/' . PAYMENT_METHOD_ID . '/charges');

// --- revokePaymentMethod ---------------------------------------------------------------
$client = new RecordingClient($charge, 201);
$client->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
$client = new RecordingClient([], 204);
$outcome = 'returned';
try {
    $returned = $client->revokePaymentMethod(PAYMENT_METHOD_ID);
    $outcome = $returned === null ? 'returned null' : 'returned a value';
} catch (\Throwable $e) {
    $outcome = get_class($e);
}
check('revoke returns nothing on a 204', $outcome, 'returned null');
check('revoke is a DELETE on the payment-methods path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'DELETE ' . DominaiteClient::PAYMENT_METHODS_PATH . '/' . PAYMENT_METHOD_ID);
check('revoke signs an empty body', $client->calls[0]['body'] === null ? 'null' : 'body', 'null');
check('revoke signs an empty idempotency key', $client->calls[0]['key'], '');

// The last charge key is not disturbed by a revoke, which signs no key of its own.
$client = new RecordingClient($charge, 201);
$client->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
$client->revokePaymentMethod(PAYMENT_METHOD_ID);
check('revoke leaves the last charge key untouched', (string) $client->getLastIdempotencyKey(), CHARGE_KEY);

// 502 and 503 with a code are a RevokeException; nothing changed under either.
foreach ([502 => 'UPSTREAM_CONTRACT_ERROR', 503 => 'MERCHANT_API_UNAVAILABLE'] as $status => $code) {
    $thrown = null;
    try {
        (new RecordingClient(['success' => false, 'error' => ['message' => 'Nothing changed.', 'code' => $code, 'statusCode' => $status]], $status))
            ->revokePaymentMethod(PAYMENT_METHOD_ID);
    } catch (RevokeException $e) {
        $thrown = $e;
    }
    check("revoke $status $code throws RevokeException", $thrown === null ? 'no exception' : get_class($thrown), RevokeException::class);
    check("revoke $status $code keeps status, code and message",
        $thrown === null ? '' : $thrown->getHttpStatus() . ' ' . $thrown->getErrorCode() . ' ' . $thrown->getMessage(), "$status $code Nothing changed.");
}
$missing = null;
try {
    (new RecordingClient(['success' => false, 'error' => ['message' => 'Validation failed', 'code' => 'VALIDATION_ERROR', 'statusCode' => 404,
        'validationErrors' => [['field' => 'id', 'message' => "'" . PAYMENT_METHOD_ID . "' not found", 'code' => 'VALIDATION_FAILED']]]], 404))
        ->revokePaymentMethod(PAYMENT_METHOD_ID);
} catch (ApiException $e) {
    $missing = $e;
}
check('a revoke on an id that is not yours is an ApiException', $missing === null ? 'no exception' : get_class($missing), ApiException::class);
check('the revoke 404 keeps its status', (string) ($missing === null ? 0 : $missing->getHttpStatus()), '404');
check('the revoke 404 keeps the gateway validation code', $missing === null ? '' : (string) $missing->getErrorCode(), 'VALIDATION_ERROR');
check('a codeless 503 on a revoke is still a retryable transport error',
    thrownBy(static function (): void {
        (new RecordingClient(['success' => false], 503))->revokePaymentMethod(PAYMENT_METHOD_ID);
    }), TransportException::class);

// --- the generic response handling the other routes keep ---------------------------------
$responses = new ResponseClient(KEY_ID, SECRET);
check('a 204 with no body is a success with nothing to parse', (string) count($responses->handle(204, '')), '0');
check('a body inside the envelope is unwrapped',
    (string) ($responses->handle(200, '{"success":true,"data":{"chargeId":"ch_1","status":"failed","declineClass":"hard","transactionId":"t"}}')['declineClass'] ?? ''), 'hard');

$notFound = null;
try {
    $responses->handle(404, '{"success":false,"error":{"code":"NOT_FOUND","message":"No such payment method"}}');
} catch (ApiException $e) {
    $notFound = $e;
}
check('a generic 404 is an ApiException', $notFound === null ? 'no exception' : get_class($notFound), ApiException::class);
check('the generic 404 keeps its status', (string) ($notFound === null ? 0 : $notFound->getHttpStatus()), '404');
check('the generic 404 keeps its code', (string) ($notFound === null ? '' : $notFound->getErrorCode()), 'NOT_FOUND');
check('a coded 503 through the generic handling is still a retryable transport error',
    thrownBy(static function () use ($responses): void {
        $responses->handle(503, '{"success":false,"error":{"code":"PAYMENT_PROCESSING_UNAVAILABLE","message":"Down"}}');
    }), TransportException::class);
check('an empty 503 through the generic handling is a retryable transport error',
    thrownBy(static function () use ($responses): void { $responses->handle(503, ''); }), TransportException::class);

exit($failures === 0 ? 0 : 1);
