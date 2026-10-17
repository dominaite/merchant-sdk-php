<?php
// Dependency-free tests for the reporting-key routes: webhook endpoints (webhooks:manage)
// and the transaction list (transactions:read). The signed method, path, key and body of
// every route, the passthrough of fields the SDK does not model, the answer as one shape
// whatever the wire omits, and the failures the API sends.
// Run: php tests/reporting_vectors.php - exits non-zero on any mismatch.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/AuthenticationException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ApiException;
use Dominaite\Exception\AuthenticationException;
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
const WEBHOOK_ID = '3f2c9a1e-0b7d-4c55-9a8e-1d2f3a4b5c6d';
const WEBHOOK_KEY = 'webhook-orders-endpoint-1';
const WEBHOOK_SECRET = 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

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
        parent::__construct(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL);
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

function caught(callable $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }

    return null;
}

/** The bytes send() signs for a body, including the {} of an empty one. */
function encode(?array $body): string
{
    if ($body === null) {
        return '';
    }

    return $body === [] ? '{}' : (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** @param mixed $data */
function envelope($data): array
{
    return ['success' => true, 'data' => $data,
        'metadata' => ['requestId' => 'r', 'timestamp' => 't', 'apiVersion' => '1.0', 'processingTimeMs' => 1]];
}

function failure(int $status, string $code): array
{
    return ['success' => false, 'error' => ['code' => $code, 'message' => 'Refused.', 'statusCode' => $status]];
}

/** @param array<string,mixed> $value */
function keysOf(array $value): string
{
    $keys = array_keys($value);
    sort($keys);

    return implode(',', $keys);
}

/** The code and status of an ApiException or AuthenticationException, or what was thrown instead. */
function refusal(?\Throwable $e): string
{
    if ($e instanceof ApiException || $e instanceof AuthenticationException) {
        return get_class($e) . ' ' . $e->getHttpStatus() . ' ' . $e->getErrorCode();
    }

    return $e === null ? 'no exception' : get_class($e);
}

// --- webhook endpoints ----------------------------------------------------------------------
$endpoint = ['id' => WEBHOOK_ID, 'url' => 'https://shop.example/hooks', 'events' => ['payment.succeeded', 'future.event'],
    'status' => 'active', 'createdAt' => '2026-10-01T08:00:00Z'];

// createWebhookEndpoint: the key is signed and sent; events go out as given, a type the
// SDK has never seen included, because only the API decides which types exist.
$client = new RecordingClient(envelope($endpoint + ['secret' => WEBHOOK_SECRET]), 201);
$created = $client->createWebhookEndpoint(['url' => 'https://shop.example/hooks',
    'events' => ['payment.succeeded', 'future.event'], 'futureField' => 1, 'idempotencyKey' => WEBHOOK_KEY]);
check('createWebhookEndpoint is a POST on the webhooks path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'POST /merchant-api/webhooks');
check('the webhooks path is the published one', DominaiteClient::WEBHOOKS_PATH, '/merchant-api/webhooks');
check('createWebhookEndpoint signs and sends the caller key', $client->calls[0]['key'], WEBHOOK_KEY);
check('the endpoint key is readable afterwards', (string) $client->getLastIdempotencyKey(), WEBHOOK_KEY);
check('createWebhookEndpoint sends url, events and any other key as given', encode($client->calls[0]['body']),
    '{"url":"https://shop.example/hooks","events":["payment.succeeded","future.event"],"futureField":1}');
check('the first 201 carries the secret', (string) $created['secret'], WEBHOOK_SECRET);
check('the endpoint has every field', keysOf($created), 'createdAt,events,id,secret,status,url');
check('the endpoint keeps the events the API answered', implode(',', $created['events']), 'payment.succeeded,future.event');

$replayed = (new RecordingClient(envelope($endpoint), 201))
    ->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => ['payment.succeeded'], 'idempotencyKey' => WEBHOOK_KEY]);
check('a replay without the secret reads it as null', var_export($replayed['secret'], true), 'NULL');
check('a replay still answers the endpoint', (string) $replayed['id'], WEBHOOK_ID);

foreach ([[], ['verification.succeeded', 'verification.failed', 'agreement.past_due'], ['', 'x']] as $events) {
    $client = new RecordingClient(envelope($endpoint + ['secret' => WEBHOOK_SECRET]), 201);
    check('the SDK sends events ' . json_encode($events) . ' without checking them',
        thrownBy(static function () use ($client, $events): void {
            $client->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => $events, 'idempotencyKey' => WEBHOOK_KEY]);
        }) . ' ' . count($client->calls), 'no exception 1');
}

$client = new RecordingClient(envelope($endpoint), 201);
check('createWebhookEndpoint without a key is refused locally',
    thrownBy(static function () use ($client): void {
        $client->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => ['payment.succeeded']]);
    }), \InvalidArgumentException::class);
check('createWebhookEndpoint without a key never reaches the transport', (string) count($client->calls), '0');

$refusals = [
    [400, 'WEBHOOK_URL_REJECTED'], [409, 'WEBHOOK_LIMIT_REACHED'], [409, 'DUPLICATE_REQUEST'],
    [422, 'IDEMPOTENCY_KEY_REUSED'], [400, 'IDEMPOTENCY_KEY_REQUIRED'], [400, 'VALIDATION_ERROR'],
];
foreach ($refusals as [$status, $code]) {
    check("createWebhookEndpoint $status $code",
        refusal(caught(static function () use ($status, $code): void {
            (new RecordingClient(failure($status, $code), $status))
                ->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => ['x'], 'idempotencyKey' => WEBHOOK_KEY]);
        })), ApiException::class . " $status $code");
}

// WEBHOOK_ALREADY_REGISTERED names the endpoint the key already holds, in error.params.
$registered = caught(static function (): void {
    (new RecordingClient(['success' => false, 'error' => ['code' => 'WEBHOOK_ALREADY_REGISTERED', 'message' => 'Registered.',
        'statusCode' => 409, 'params' => ['webhookId' => WEBHOOK_ID]]], 409))
        ->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => ['x'], 'idempotencyKey' => WEBHOOK_KEY]);
});
check('WEBHOOK_ALREADY_REGISTERED is an ApiException', refusal($registered), ApiException::class . ' 409 WEBHOOK_ALREADY_REGISTERED');
check('WEBHOOK_ALREADY_REGISTERED names the existing endpoint',
    (string) ($registered instanceof ApiException ? ($registered->getResult()['error']['params']['webhookId'] ?? '') : ''), WEBHOOK_ID);

// A payments key cannot hold webhooks:manage: the 403 says so, not "check your clock".
$scope = caught(static function (): void {
    (new RecordingClient(['success' => false, 'error' => ['code' => 'INSUFFICIENT_SCOPE', 'statusCode' => 403,
        'message' => "This API key does not have the 'webhooks:manage' scope this route requires"]], 403))
        ->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => ['x'], 'idempotencyKey' => WEBHOOK_KEY]);
});
check('a key without webhooks:manage is a 403 AuthenticationException', refusal($scope),
    AuthenticationException::class . ' 403 INSUFFICIENT_SCOPE');
check('the 403 names the missing scope', $scope !== null && strpos($scope->getMessage(), "'webhooks:manage'") !== false ? 'named' : 'not named', 'named');
check('the 403 does not blame the clock', $scope !== null && stripos($scope->getMessage(), 'server clock') === false ? 'clean' : 'clock hint', 'clean');

$client = new RecordingClient(failure(503, 'MERCHANT_API_UNAVAILABLE'), 503);
check('a 503 on createWebhookEndpoint is a retryable transport error',
    thrownBy(static function () use ($client): void {
        $client->createWebhookEndpoint(['url' => 'https://shop.example/hooks', 'events' => ['x'], 'idempotencyKey' => WEBHOOK_KEY]);
    }), TransportException::class);
check('after a 503 the endpoint key to retry with is readable', (string) $client->getLastIdempotencyKey(), WEBHOOK_KEY);

// listWebhookEndpoints: a bare array, newest first, never a secret.
$older = array_merge($endpoint, ['id' => '00000000-0000-4000-8000-000000000001', 'status' => 'inactive']);
$client = new RecordingClient(envelope([$endpoint, $older]));
$listed = $client->listWebhookEndpoints();
check('listWebhookEndpoints is a GET on the webhooks path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'GET /merchant-api/webhooks');
check('listWebhookEndpoints signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
check('listWebhookEndpoints keeps the API order', implode(',', array_column($listed, 'status')), 'active,inactive');
check('a listed endpoint has no secret', keysOf($listed[0]), 'createdAt,events,id,status,url');
check('no endpoints is an empty array', (string) json_encode((new RecordingClient(envelope([])))->listWebhookEndpoints()), '[]');
check('listWebhookEndpoints leaves the last key untouched', var_export($client->getLastIdempotencyKey(), true), 'NULL');

// deleteWebhookEndpoint: the id is signed lowercase and hyphenated, however it was given.
$client = new RecordingClient([], 204);
$client->deleteWebhookEndpoint(' ' . strtoupper(WEBHOOK_ID) . ' ');
check('deleteWebhookEndpoint is a DELETE on the lowercase id', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'DELETE /merchant-api/webhooks/' . WEBHOOK_ID);
check('deleteWebhookEndpoint signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
check('deleteWebhookEndpoint 404 WEBHOOK_NOT_FOUND',
    refusal(caught(static function (): void {
        (new RecordingClient(failure(404, 'WEBHOOK_NOT_FOUND'), 404))->deleteWebhookEndpoint(WEBHOOK_ID);
    })), ApiException::class . ' 404 WEBHOOK_NOT_FOUND');
foreach (['', 'wh_123', '3f2c9a1e0b7d4c559a8e1d2f3a4b5c6d', '{' . WEBHOOK_ID . '}', WEBHOOK_ID . '/x'] as $bad) {
    $client = new RecordingClient([], 204);
    check('deleteWebhookEndpoint refuses id ' . var_export($bad, true),
        thrownBy(static function () use ($client, $bad): void {
            $client->deleteWebhookEndpoint($bad);
        }) . ' ' . count($client->calls), \InvalidArgumentException::class . ' 0');
}

// --- listTransactions -----------------------------------------------------------------------
// As the gateway sends a page: a payment with nothing optional, a refunded and settled charge, and a
// verification, which the API added after this SDK knew only payments and charges.
$page = ['items' => [
    ['transactionId' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d', 'type' => 'payment', 'status' => 'pending', 'amount' => 2500,
        'currency' => 'EUR', 'createdAt' => '2026-10-01T08:00:00Z'],
    ['transactionId' => '2a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d', 'orderReference' => 'order-1043', 'type' => 'charge',
        'status' => 'partially_refunded', 'amount' => 2500, 'currency' => 'EUR', 'createdAt' => '2026-10-01T09:00:00Z',
        'completedAt' => '2026-10-01T09:00:02Z', 'storedPaymentMethodId' => 'pm_0123456789abcdef0123456789abcdef',
        'refundedAmount' => 500, 'fee' => 63, 'netAmount' => 2437, 'settledAt' => '2026-10-02T00:00:00Z',
        'payoutId' => '7d1e2f3a-4b5c-4d6e-8f70-8192a3b4c5d6'],
    ['transactionId' => '3a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d', 'type' => 'verification', 'status' => 'cancelled',
        'amount' => 30, 'currency' => 'EUR', 'createdAt' => '2026-10-01T10:00:00Z', 'futureField' => 'kept'],
], 'nextCursor' => 'eyJjIjoiMjAyNi0xMC0wMVQxMDowMDowMFoifQ'];
const TX_ITEM_FIELDS = 'amount,completedAt,createdAt,currency,fee,netAmount,orderReference,payoutId,refundedAmount,settledAt,status,'
    . 'storedPaymentMethodId,transactionId,type';

$client = new RecordingClient(envelope($page));
$listed = $client->listTransactions(['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-08T00:00:00Z',
    'status' => 'succeeded', 'limit' => 100, 'cursor' => null, 'futureFilter' => 'x']);
check('the transactions path is the published one', DominaiteClient::TRANSACTIONS_PATH, '/merchant-api/transactions');
check('listTransactions sends the params in the query, a null cursor left out', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'GET /merchant-api/transactions?from=2026-10-01T00%3A00%3A00Z&to=2026-10-08T00%3A00%3A00Z&status=succeeded&limit=100'
    . '&futureFilter=x');
check('listTransactions signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
check('listTransactions leaves the last key untouched', var_export($client->getLastIdempotencyKey(), true), 'NULL');
check('the page keeps the API order', implode(',', array_column($listed['items'], 'type')), 'payment,charge,verification');
check('every item has every field',
    implode(' | ', array_map('keysOf', array_map(static function (array $item): array {
        unset($item['futureField']);
        return $item;
    }, $listed['items']))), TX_ITEM_FIELDS . ' | ' . TX_ITEM_FIELDS . ' | ' . TX_ITEM_FIELDS);
foreach (['orderReference', 'completedAt', 'storedPaymentMethodId', 'refundedAmount', 'fee', 'netAmount', 'settledAt',
    'payoutId'] as $nullable) {
    check("an item reads the omitted $nullable as null", var_export($listed['items'][0][$nullable], true), 'NULL');
}
check('a settled charge carries its fee, net amount, settlement date and payout',
    var_export([$listed['items'][1]['fee'], $listed['items'][1]['netAmount'], $listed['items'][1]['settledAt'],
        $listed['items'][1]['payoutId']], true),
    var_export([63, 2437, '2026-10-02T00:00:00Z', '7d1e2f3a-4b5c-4d6e-8f70-8192a3b4c5d6'], true));

// A settlement day: settledFrom and settledTo go in the query, the signed path stays bare.
$client = new RecordingClient(envelope(['items' => [], 'nextCursor' => null]));
$client->listTransactions(['from' => '2026-09-13T00:00:00Z', 'to' => '2026-10-14T00:00:00Z',
    'settledFrom' => '2026-10-13', 'settledTo' => '2026-10-14', 'cursor' => null]);
check('listTransactions sends the settlement window in the query', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'GET /merchant-api/transactions?from=2026-09-13T00%3A00%3A00Z&to=2026-10-14T00%3A00%3A00Z'
    . '&settledFrom=2026-10-13&settledTo=2026-10-14');
$client = new RecordingClient(envelope(['items' => [], 'nextCursor' => null]));
$client->listTransactions(['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-08T00:00:00Z', 'settledFrom' => null,
    'settledTo' => null]);
check('a null settlement window is left out', $client->calls[0]['path'],
    '/merchant-api/transactions?from=2026-10-01T00%3A00%3A00Z&to=2026-10-08T00%3A00%3A00Z');
check('a refunded charge carries its card and refunded amount',
    $listed['items'][1]['storedPaymentMethodId'] . ' ' . $listed['items'][1]['refundedAmount'], 'pm_0123456789abcdef0123456789abcdef 500');
check('an item field the SDK does not model rides through', (string) ($listed['items'][2]['futureField'] ?? 'missing'), 'kept');
check('the page carries the next cursor', (string) $listed['nextCursor'], 'eyJjIjoiMjAyNi0xMC0wMVQxMDowMDowMFoifQ');

// The last page has a null cursor; an offset's + goes out encoded, never as a space.
$client = new RecordingClient(envelope(['items' => [], 'nextCursor' => null]));
$last = $client->listTransactions(['from' => '2026-10-01T02:00:00+02:00', 'to' => '2026-10-02']);
check('an offset + is sent as %2B', $client->calls[0]['path'],
    '/merchant-api/transactions?from=2026-10-01T02%3A00%3A00%2B02%3A00&to=2026-10-02');
check('the last page has a null cursor', var_export($last['nextCursor'], true), 'NULL');
check('an empty window is an empty items array', (string) json_encode($last['items']), '[]');

// Values that cannot go into a query string are refused before anything is signed.
foreach (['a DateTime' => new \DateTimeImmutable('2026-10-01T00:00:00Z'), 'an array' => ['succeeded'], 'a bool' => true,
    'a float' => 50.0] as $label => $value) {
    $client = new RecordingClient(envelope($page));
    check("listTransactions refuses $label",
        thrownBy(static function () use ($client, $value): void {
            $client->listTransactions(['from' => $value, 'to' => '2026-10-08T00:00:00Z']);
        }) . ' ' . count($client->calls), \InvalidArgumentException::class . ' 0');
}

check('listTransactions 400 VALIDATION_ERROR',
    refusal(caught(static function (): void {
        (new RecordingClient(failure(400, 'VALIDATION_ERROR'), 400))->listTransactions(['from' => 'yesterday']);
    })), ApiException::class . ' 400 VALIDATION_ERROR');
check('listTransactions with a payments key is a 403',
    refusal(caught(static function (): void {
        (new RecordingClient(failure(403, 'INSUFFICIENT_SCOPE'), 403))
            ->listTransactions(['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-08T00:00:00Z']);
    })), AuthenticationException::class . ' 403 INSUFFICIENT_SCOPE');
check('a 200 without items is an ApiException',
    refusal(caught(static function (): void {
        (new RecordingClient(envelope(['nextCursor' => null])))->listTransactions(['from' => 'a', 'to' => 'b']);
    })), ApiException::class . ' 200 UNEXPECTED_RESPONSE');

exit($failures === 0 ? 0 : 1);
