<?php
// Dependency-free tests for refunds: the signed body of a partial and a full refund, the
// key handling, the refund as one shape whatever the wire omits, and every refund error
// code as a RefundException with its retry classification.
// Run: php tests/refund_vectors.php - exits non-zero on any mismatch.
//
// tests/transport_vectors.php sends a refund end to end through real curl, including the
// Idempotency-Key header and the signature bytes.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/AuthenticationException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/RefundException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ApiException;
use Dominaite\Exception\RefundException;
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
const TRANSACTION_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
const REFUND_ID = 're_7c1e9a2b4d6f48a0b3c5d7e9f1a2b3c4';
const REFUND_KEY = 'refund-credit-note-77';

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
    public function __construct(array $canned, int $status = 202)
    {
        parent::__construct(KEY_ID, SECRET);
        $this->canned = $canned;
        $this->status = $status;
    }

    protected function send(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'key' => $idempotencyKey];

        return $this->readResponse($this->status, (string) json_encode($this->canned), []);
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

/** The bytes send() signs for a body, including the {} of an empty one. */
function encode(?array $body): string
{
    if ($body === null) {
        return '';
    }

    return $body === [] ? '{}' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** @param array<string,mixed> $data */
function envelope(array $data): array
{
    return ['success' => true, 'data' => $data,
        'metadata' => ['requestId' => 'r', 'timestamp' => 't', 'apiVersion' => '1.0', 'processingTimeMs' => 1]];
}

/** @param array<string,mixed> $value */
function keysOf(array $value): string
{
    $keys = array_keys($value);
    sort($keys);

    return implode(',', $keys);
}

const REFUND_FIELDS = 'amount,completedAt,currency,failureCode,failureMessage,refundId,status,transactionId';

// As the gateway sends them: null fields omitted.
$pendingPartial = envelope(['refundId' => REFUND_ID, 'transactionId' => TRANSACTION_ID, 'status' => 'pending',
    'amount' => 2500, 'currency' => 'EUR']);
$pendingFull = envelope(['refundId' => REFUND_ID, 'transactionId' => TRANSACTION_ID, 'status' => 'pending',
    'currency' => 'HUF']);
$succeeded = envelope(['refundId' => REFUND_ID, 'transactionId' => TRANSACTION_ID, 'status' => 'succeeded',
    'amount' => 2500, 'currency' => 'EUR', 'completedAt' => '2026-09-26T10:05:40.1200000Z']);
$failed = envelope(['refundId' => REFUND_ID, 'transactionId' => TRANSACTION_ID, 'status' => 'failed',
    'currency' => 'EUR', 'failureCode' => 'REFUND_FAILED',
    'failureMessage' => 'The refund could not be completed.', 'completedAt' => '2026-09-26T10:05:41.0000000Z']);

// --- createRefund: partial ------------------------------------------------------------
$client = new RecordingClient($pendingPartial);
$refund = $client->createRefund(TRANSACTION_ID, ['amount' => 2500, 'idempotencyKey' => REFUND_KEY]);
check('a partial refund is a POST on the payment refunds path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'POST ' . DominaiteClient::PAYMENTS_PATH . '/' . TRANSACTION_ID . '/refunds');
check('a partial refund sends the amount', encode($client->calls[0]['body']), '{"amount":2500}');
check('the refund signs and sends the caller key', $client->calls[0]['key'], REFUND_KEY);
check('the refund key is readable afterwards', (string) $client->getLastIdempotencyKey(), REFUND_KEY);
check('the idempotency key never rides the refund body',
    array_key_exists('idempotencyKey', (array) $client->calls[0]['body']) ? 'leaked' : 'absent', 'absent');
check('the 202 is returned as the refund', (string) $refund['refundId'], REFUND_ID);
check('the refund has every field', keysOf($refund), REFUND_FIELDS);
check('the partial refund keeps its amount as an int', var_export($refund['amount'], true), '2500');
foreach (['failureCode', 'failureMessage', 'completedAt'] as $nullable) {
    check("a pending refund reads the omitted $nullable as null", var_export($refund[$nullable], true), 'NULL');
}

// With a reason, in contract order after the amount.
$client = new RecordingClient($pendingPartial);
$client->createRefund(TRANSACTION_ID, ['reason' => 'Returned item', 'amount' => 2500, 'idempotencyKey' => REFUND_KEY,
    'currency' => 'EUR']);
check('the refund body is built field by field in contract order', encode($client->calls[0]['body']),
    '{"amount":2500,"reason":"Returned item"}');

// --- createRefund: full ---------------------------------------------------------------
// Everything still refundable: no amount key at all, never "amount": null.
$client = new RecordingClient($pendingFull);
$refund = $client->createRefund(TRANSACTION_ID, ['idempotencyKey' => REFUND_KEY]);
check('a full refund sends an empty JSON object', encode($client->calls[0]['body']), '{}');
check('a full refund sends no amount key', array_key_exists('amount', (array) $client->calls[0]['body']) ? 'sent' : 'absent', 'absent');
check('a full refund reads its omitted amount as null', array_key_exists('amount', $refund) ? var_export($refund['amount'], true) : 'absent', 'NULL');
check('a full refund keeps the payment currency', (string) $refund['currency'], 'HUF');

$client = new RecordingClient($pendingFull);
$client->createRefund(TRANSACTION_ID, ['amount' => null, 'reason' => 'Order cancelled', 'idempotencyKey' => REFUND_KEY]);
check('an amount of null is a full refund too', encode($client->calls[0]['body']), '{"reason":"Order cancelled"}');

// The transaction id goes into the signed path lowercased, like getStatus().
$client = new RecordingClient($pendingFull);
$client->createRefund(' ' . strtoupper(TRANSACTION_ID) . ' ', ['idempotencyKey' => REFUND_KEY]);
check('the transaction id is signed lowercase', $client->calls[0]['path'],
    DominaiteClient::PAYMENTS_PATH . '/' . TRANSACTION_ID . '/refunds');

// A replay of the same key answers 202 with the refund as it stands now.
$refund = (new RecordingClient($succeeded))->createRefund(TRANSACTION_ID, ['amount' => 2500, 'idempotencyKey' => REFUND_KEY]);
check('a replay returns the refund as it stands now', (string) $refund['status'], 'succeeded');

// --- createRefund: validation, before anything is signed ------------------------------
$cases = [
    'no idempotencyKey' => ['idempotencyKey' => null],
    'empty idempotencyKey' => ['idempotencyKey' => ''],
    'idempotencyKey with a space' => ['idempotencyKey' => 'credit note 77'],
    'zero amount' => ['amount' => 0],
    'negative amount' => ['amount' => -5],
    'float amount' => ['amount' => 25.5],
    'string amount' => ['amount' => '2500'],
    'non-string reason' => ['reason' => 7],
];
foreach ($cases as $label => $override) {
    $client = new RecordingClient($pendingPartial);
    check("createRefund rejects $label locally",
        thrownBy(static function () use ($client, $override): void {
            $client->createRefund(TRANSACTION_ID, array_merge(['idempotencyKey' => REFUND_KEY], $override));
        }), \InvalidArgumentException::class);
    check("createRefund with $label never reaches the transport", (string) count($client->calls), '0');
    check("createRefund with $label leaves no stale key", $client->getLastIdempotencyKey() === null ? 'null' : 'set', 'null');
}
foreach (['', 'order-1042', '1a2b3c4d/5e6f', TRANSACTION_ID . '/refunds'] as $bad) {
    $client = new RecordingClient($pendingPartial);
    check('createRefund refuses transaction id ' . var_export($bad, true),
        thrownBy(static function () use ($client, $bad): void {
            $client->createRefund($bad, ['idempotencyKey' => REFUND_KEY]);
        }), \InvalidArgumentException::class);
    check('getRefund refuses transaction id ' . var_export($bad, true),
        thrownBy(static function () use ($client, $bad): void {
            $client->getRefund($bad, REFUND_ID);
        }), \InvalidArgumentException::class);
    check('no bad transaction id ' . var_export($bad, true) . ' reached the transport', (string) count($client->calls), '0');
}

// --- getRefund ------------------------------------------------------------------------
$client = new RecordingClient($succeeded, 200);
$refund = $client->getRefund(TRANSACTION_ID, REFUND_ID);
check('getRefund is a GET on the refund path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'GET ' . DominaiteClient::PAYMENTS_PATH . '/' . TRANSACTION_ID . '/refunds/' . REFUND_ID);
check('getRefund signs an empty idempotency key', $client->calls[0]['key'], '');
check('getRefund signs an empty body', $client->calls[0]['body'] === null ? 'null' : 'body', 'null');
check('a succeeded refund has every field', keysOf($refund), REFUND_FIELDS);
check('a succeeded refund carries the amount refunded', var_export($refund['amount'], true), '2500');
check('a succeeded refund carries completedAt', (string) $refund['completedAt'], '2026-09-26T10:05:40.1200000Z');
check('a succeeded refund has no failureCode', var_export($refund['failureCode'], true), 'NULL');

$client = new RecordingClient($succeeded, 200);
$client->getRefund(TRANSACTION_ID, REFUND_ID);
check('getRefund leaves the last idempotency key untouched', $client->getLastIdempotencyKey() === null ? 'null' : 'set', 'null');

$refund = (new RecordingClient($failed, 200))->getRefund(TRANSACTION_ID, REFUND_ID);
check('a failed refund has every field', keysOf($refund), REFUND_FIELDS);
check('a failed refund reads as failed', (string) $refund['status'], 'failed');
check('a failed refund never carries an amount', var_export($refund['amount'], true), 'NULL');
check('a failed refund carries REFUND_FAILED', (string) $refund['failureCode'], DominaiteClient::REFUND_FAILED);
check('a failed refund carries its message', (string) $refund['failureMessage'], 'The refund could not be completed.');
check('a failure code is one the SDK knows',
    in_array($refund['failureCode'], DominaiteClient::REFUND_FAILURE_CODES, true) ? 'known' : 'unknown', 'known');

foreach (['', ' ', 're_1/x', 're_1?x=1', 're 1', str_repeat('r', 101)] as $bad) {
    $client = new RecordingClient($succeeded, 200);
    check('getRefund refuses refund id ' . var_export($bad, true),
        thrownBy(static function () use ($client, $bad): void {
            $client->getRefund(TRANSACTION_ID, $bad);
        }), \InvalidArgumentException::class);
    check('the bad refund id ' . var_export($bad, true) . ' never reached the transport', (string) count($client->calls), '0');
}

// --- refund errors --------------------------------------------------------------------
// Every code at its HTTP status is a RefundException (an ApiException) with the code, the
// status and the retry classification the contract gives it.
$errors = [
    ['createRefund', 404, 'PAYMENT_NOT_FOUND', false],
    ['getRefund', 404, 'PAYMENT_NOT_FOUND', false],
    ['getRefund', 404, 'REFUND_NOT_FOUND', true],
    ['createRefund', 422, 'PAYMENT_NOT_REFUNDABLE', false],
    ['createRefund', 422, 'REFUND_AMOUNT_EXCEEDED', false],
    ['createRefund', 422, 'IDEMPOTENCY_KEY_REUSED', false],
    ['createRefund', 409, 'DUPLICATE_REQUEST', true],
    ['createRefund', 400, 'IDEMPOTENCY_KEY_REQUIRED', false],
];
$seen = [];
foreach ($errors as [$route, $status, $code, $retryable]) {
    $seen[] = $code;
    $body = ['success' => false, 'error' => ['message' => 'Refused.', 'code' => $code, 'statusCode' => $status]];
    $client = new RecordingClient($body, $status);
    $thrown = null;
    try {
        $route === 'createRefund'
            ? $client->createRefund(TRANSACTION_ID, ['amount' => 2500, 'idempotencyKey' => REFUND_KEY])
            : $client->getRefund(TRANSACTION_ID, REFUND_ID);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    $label = "$route $status $code";
    check("$label throws RefundException", $thrown === null ? 'no exception' : get_class($thrown), RefundException::class);
    if (!$thrown instanceof RefundException) {
        continue;
    }
    check("$label is an ApiException", $thrown instanceof ApiException ? 'yes' : 'no', 'yes');
    check("$label keeps status and code", $thrown->getHttpStatus() . ' ' . $thrown->getErrorCode(), "$status $code");
    check("$label keeps the message", $thrown->getMessage(), 'Refused.');
    check("$label keeps the whole envelope", keysOf($thrown->getResult()), 'error,success');
    check("$label retry classification", var_export($thrown->isRetryable(), true), var_export($retryable, true));
}
sort($seen);
$codes = DominaiteClient::REFUND_ERROR_CODES;
sort($codes);
check('every refund error code is exercised', implode(',', array_values(array_unique($seen))), implode(',', $codes));
check('REFUND_FAILED is a failure code, never an HTTP error code',
    in_array(DominaiteClient::REFUND_FAILED, DominaiteClient::REFUND_ERROR_CODES, true) ? 'listed' : 'not listed', 'not listed');

// A 500 means nothing was queued: the retryable transport error, coded or not.
foreach (['coded' => ['success' => false, 'error' => ['message' => 'Boom', 'code' => 'INTERNAL_ERROR', 'statusCode' => 500]],
    'codeless' => ['success' => false]] as $label => $body) {
    check("a $label 500 on createRefund is a retryable transport error",
        thrownBy(static function () use ($body): void {
            (new RecordingClient($body, 500))->createRefund(TRANSACTION_ID, ['idempotencyKey' => REFUND_KEY]);
        }), TransportException::class);
}
$client = new RecordingClient(['success' => false], 500);
try {
    $client->createRefund(TRANSACTION_ID, ['idempotencyKey' => REFUND_KEY]);
} catch (TransportException $e) {
}
check('after a 500 the key to retry with is readable', (string) $client->getLastIdempotencyKey(), REFUND_KEY);

// A code that is not a refund code stays the generic ApiException.
check('a 400 validation error on createRefund is a plain ApiException',
    thrownBy(static function (): void {
        (new RecordingClient(['success' => false, 'error' => ['message' => 'Validation failed', 'code' => 'VALIDATION_ERROR',
            'statusCode' => 400]], 400))->createRefund(TRANSACTION_ID, ['reason' => str_repeat('r', 501), 'idempotencyKey' => REFUND_KEY]);
    }), ApiException::class);

// A 2xx without a refund body is not a refund, whatever success says.
check('a 202 without a refund body is an ApiException',
    thrownBy(static function (): void {
        (new RecordingClient(['success' => true]))->createRefund(TRANSACTION_ID, ['idempotencyKey' => REFUND_KEY]);
    }), ApiException::class);

// --- vocabularies -----------------------------------------------------------------------
check('refund status vocabulary', implode(',', DominaiteClient::REFUND_STATUS_VOCABULARY), 'pending,processing,succeeded,failed');
check('refund failure codes', implode(',', DominaiteClient::REFUND_FAILURE_CODES), 'REFUND_AMOUNT_EXCEEDED,PAYMENT_NOT_REFUNDABLE,REFUND_FAILED');

exit($failures === 0 ? 0 : 1);
