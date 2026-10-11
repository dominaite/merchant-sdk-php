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

exit($failures === 0 ? 0 : 1);
