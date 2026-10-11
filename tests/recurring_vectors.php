<?php
// Dependency-free tests for subscription plans and agreements: the signed method, path,
// key and body of every route, the passthrough of fields the SDK does not model, the
// answer as one shape whatever the wire omits, and the failures the API sends.
// Run: php tests/recurring_vectors.php - exits non-zero on any mismatch.

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
const PLAN_ID = 'plan_0123456789abcdef0123456789ABCDEF';
const PLAN_KEY = 'plan-gold-monthly-1';

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

/** @return \Throwable|null */
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

const PLAN_FIELDS = 'amount,archivedAt,cancelUrl,createdAt,currency,id,intervalCount,intervalUnit,name,periodCount,status,trialDays';

// --- plans --------------------------------------------------------------------------------
// As the gateway sends a plan: null fields omitted.
$plan = ['id' => PLAN_ID, 'name' => 'Gold', 'amount' => 2500, 'currency' => 'EUR', 'intervalUnit' => 'month',
    'intervalCount' => 1, 'trialDays' => 0, 'status' => 'active', 'createdAt' => '2026-10-01T08:00:00Z'];
$archived = array_merge($plan, ['id' => 'plan_00000000000000000000000000000002', 'status' => 'archived',
    'periodCount' => 12, 'cancelUrl' => 'https://shop.example/cancel', 'archivedAt' => '2026-10-02T08:00:00Z']);

// createPlan: the key is signed and sent, never in the body; every other key is the body.
$client = new RecordingClient(envelope($plan), 201);
$created = $client->createPlan(['name' => 'Gold', 'amount' => 2500, 'currency' => 'EUR', 'intervalUnit' => 'month',
    'trialDays' => 0, 'futureField' => ['x' => 1], 'idempotencyKey' => PLAN_KEY]);
check('createPlan is a POST on the plans path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'POST /merchant-api/plans');
check('the plans path is the published one', DominaiteClient::PLANS_PATH, '/merchant-api/plans');
check('createPlan signs and sends the caller key', $client->calls[0]['key'], PLAN_KEY);
check('the plan key is readable afterwards', (string) $client->getLastIdempotencyKey(), PLAN_KEY);
check('createPlan sends every other key as given, in order', encode($client->calls[0]['body']),
    '{"name":"Gold","amount":2500,"currency":"EUR","intervalUnit":"month","trialDays":0,"futureField":{"x":1}}');
check('the 201 is returned as the plan', (string) $created['id'], PLAN_ID);
check('the plan has every field', keysOf($created), PLAN_FIELDS);
foreach (['periodCount', 'cancelUrl', 'archivedAt'] as $nullable) {
    check("an active plan reads the omitted $nullable as null", var_export($created[$nullable], true), 'NULL');
}

// A field the API adds later rides through, and a replay (200) is the same plan.
$replayed = (new RecordingClient(envelope($plan + ['futureField' => 'kept']), 200))
    ->createPlan(['name' => 'Gold', 'idempotencyKey' => PLAN_KEY]);
check('a replayed create answers the plan', (string) $replayed['id'], PLAN_ID);
check('a plan field the SDK does not model rides through', (string) ($replayed['futureField'] ?? 'missing'), 'kept');

$client = new RecordingClient(envelope($plan), 201);
$missing = caught(static function () use ($client): void {
    $client->createPlan(['name' => 'Gold', 'amount' => 2500, 'currency' => 'EUR', 'intervalUnit' => 'month']);
});
check('createPlan without a key is refused locally', $missing === null ? 'no exception' : get_class($missing),
    \InvalidArgumentException::class);
check('the missing-key message does not point at an order',
    $missing !== null && strpos($missing->getMessage(), 'orderIdempotencyKey') === false ? 'record' : 'order', 'record');
check('createPlan without a key never reaches the transport', (string) count($client->calls), '0');
check('createPlan without a key leaves no key behind', var_export($client->getLastIdempotencyKey(), true), 'NULL');

$failuresByStatus = [
    [400, 'VALIDATION_ERROR', ApiException::class],
    [422, 'IDEMPOTENCY_KEY_REUSED', ApiException::class],
    [400, 'IDEMPOTENCY_KEY_REQUIRED', ApiException::class],
    [403, 'INSUFFICIENT_SCOPE', AuthenticationException::class],
];
foreach ($failuresByStatus as [$status, $code, $class]) {
    $thrown = caught(static function () use ($status, $code): void {
        (new RecordingClient(failure($status, $code), $status))->createPlan(['name' => 'Gold', 'idempotencyKey' => PLAN_KEY]);
    });
    check("createPlan $status $code", refusal($thrown), "$class $status $code");
}
$client = new RecordingClient(failure(503, 'MERCHANT_API_UNAVAILABLE'), 503);
check('a 503 on createPlan is a retryable transport error',
    thrownBy(static function () use ($client): void {
        $client->createPlan(['name' => 'Gold', 'idempotencyKey' => PLAN_KEY]);
    }), TransportException::class);
check('after a 503 the plan key to retry with is readable', (string) $client->getLastIdempotencyKey(), PLAN_KEY);
check('a 201 without a plan is an ApiException',
    refusal(caught(static function (): void {
        (new RecordingClient(envelope(['name' => 'Gold']), 201))->createPlan(['idempotencyKey' => PLAN_KEY]);
    })), ApiException::class . ' 201 UNEXPECTED_RESPONSE');

// listPlans: a bare array in the API's order, each plan in one shape.
$client = new RecordingClient(envelope([$plan, $archived]));
$plans = $client->listPlans();
check('listPlans is a GET on the plans path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'GET /merchant-api/plans');
check('listPlans signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
check('listPlans keeps the API order', implode(',', array_column($plans, 'status')), 'active,archived');
check('every listed plan has every field', keysOf($plans[0]) . ' | ' . keysOf($plans[1]), PLAN_FIELDS . ' | ' . PLAN_FIELDS);
check('a listed archived plan keeps its archive time', (string) $plans[1]['archivedAt'], '2026-10-02T08:00:00Z');
check('a listed plan keeps its periodCount', var_export($plans[1]['periodCount'], true), '12');
check('listPlans leaves the last key untouched', var_export($client->getLastIdempotencyKey(), true), 'NULL');
check('no plans is an empty array', (string) json_encode((new RecordingClient(envelope([])))->listPlans()), '[]');
check('a 200 that is not a list is an ApiException',
    refusal(caught(static function () use ($plan): void {
        (new RecordingClient(envelope($plan)))->listPlans();
    })), ApiException::class . ' 200 UNEXPECTED_RESPONSE');

// getPlan: the id goes into the path verbatim. It is case-sensitive.
$client = new RecordingClient(envelope($archived));
$read = $client->getPlan(PLAN_ID);
check('getPlan is a GET on the plan path, the id verbatim', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'GET /merchant-api/plans/' . PLAN_ID);
check('getPlan signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
check('getPlan answers the plan', keysOf($read), PLAN_FIELDS);
check('getPlan 404 PLAN_NOT_FOUND',
    refusal(caught(static function (): void {
        (new RecordingClient(failure(404, 'PLAN_NOT_FOUND'), 404))->getPlan(PLAN_ID);
    })), ApiException::class . ' 404 PLAN_NOT_FOUND');

// updatePlan: PATCH needs no key. Without one it signs an empty key; with one it signs and sends it.
$client = new RecordingClient(envelope(array_merge($plan, ['name' => 'Gold+'])));
$updated = $client->updatePlan(PLAN_ID, ['name' => 'Gold+', 'cancelUrl' => '', 'futureField' => true]);
check('updatePlan is a PATCH on the plan path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'PATCH /merchant-api/plans/' . PLAN_ID);
check('updatePlan without a key signs an empty key', $client->calls[0]['key'], '');
check('updatePlan sends every key as given, an empty cancelUrl included', encode($client->calls[0]['body']),
    '{"name":"Gold+","cancelUrl":"","futureField":true}');
check('updatePlan answers the plan', (string) $updated['name'], 'Gold+');
check('updatePlan without a key leaves the last key untouched', var_export($client->getLastIdempotencyKey(), true), 'NULL');

$client = new RecordingClient(envelope($plan));
$client->updatePlan(PLAN_ID, ['trialDays' => 14, 'idempotencyKey' => 'plan-gold-trial-14']);
check('updatePlan with a key signs and sends it', $client->calls[0]['key'], 'plan-gold-trial-14');
check('the update key never rides the body', encode($client->calls[0]['body']), '{"trialDays":14}');
check('the update key is readable afterwards', (string) $client->getLastIdempotencyKey(), 'plan-gold-trial-14');

$client = new RecordingClient(envelope($plan));
check('updatePlan refuses a key with a space',
    thrownBy(static function () use ($client): void {
        $client->updatePlan(PLAN_ID, ['name' => 'Gold', 'idempotencyKey' => 'two words']);
    }), \InvalidArgumentException::class);
check('a bad update key never reaches the transport', (string) count($client->calls), '0');
check('updatePlan 400 VALIDATION_ERROR',
    refusal(caught(static function (): void {
        (new RecordingClient(failure(400, 'VALIDATION_ERROR'), 400))->updatePlan(PLAN_ID, []);
    })), ApiException::class . ' 400 VALIDATION_ERROR');

// deletePlan archives: a 204 with no body, also on a repeat.
$client = new RecordingClient([], 204);
$client->deletePlan(PLAN_ID);
check('deletePlan is a DELETE on the plan path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'DELETE /merchant-api/plans/' . PLAN_ID);
check('deletePlan signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
check('deletePlan 404 PLAN_NOT_FOUND',
    refusal(caught(static function (): void {
        (new RecordingClient(failure(404, 'PLAN_NOT_FOUND'), 404))->deletePlan(PLAN_ID);
    })), ApiException::class . ' 404 PLAN_NOT_FOUND');
check('a 503 on deletePlan is a retryable transport error',
    thrownBy(static function (): void {
        (new RecordingClient(failure(503, 'MERCHANT_API_UNAVAILABLE'), 503))->deletePlan(PLAN_ID);
    }), TransportException::class);

// A plan id that is not one path segment is refused before anything is signed.
foreach (['', ' ', 'plan_1/x', 'plan_1?x=1', 'plan 1', '../plans', str_repeat('p', 101)] as $bad) {
    $client = new RecordingClient(envelope($plan));
    foreach (['getPlan', 'updatePlan', 'deletePlan'] as $route) {
        check("$route refuses plan id " . var_export($bad, true),
            thrownBy(static function () use ($client, $route, $bad): void {
                $route === 'updatePlan' ? $client->updatePlan($bad, ['name' => 'x']) : $client->$route($bad);
            }), \InvalidArgumentException::class);
    }
    check('the bad plan id ' . var_export($bad, true) . ' never reached the transport', (string) count($client->calls), '0');
}

exit($failures === 0 ? 0 : 1);
