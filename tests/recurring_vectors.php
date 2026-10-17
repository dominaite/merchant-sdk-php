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
const AGREEMENT_ID = 'agr_0123456789abcdef0123456789ABCDEF';
const AGREEMENT_KEY = 'subscription-customer-42-gold';
const AGREEMENT_FIELDS = 'activatedAt,amount,cancelledAt,consent,createdAt,currency,customerReference,id,intervalCount,'
    . 'intervalUnit,maxAmountPerPeriod,nextChargeAt,pastDueAt,pastDueReason,periodCount,planId,sequence,status,'
    . 'storedPaymentMethodId,trialDays,version';

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

// --- agreements ---------------------------------------------------------------------------
// A new agreement as the gateway sends it: pending, null fields omitted.
$pending = ['id' => AGREEMENT_ID, 'planId' => PLAN_ID, 'customerReference' => 'customer-42', 'status' => 'pending',
    'amount' => 2500, 'currency' => 'EUR', 'intervalUnit' => 'month', 'intervalCount' => 1, 'trialDays' => 0,
    'version' => 1, 'sequence' => 0, 'createdAt' => '2026-10-01T08:00:00Z'];
// A past-due one with a card and the recorded consent, still without the platform's receipt.
$pastDue = array_merge($pending, ['status' => 'past_due', 'storedPaymentMethodId' => 'pm_0123456789abcdef0123456789abcdef',
    'maxAmountPerPeriod' => 5000, 'nextChargeAt' => '2026-11-01T08:00:00Z', 'pastDueReason' => 'card_expired',
    'pastDueAt' => '2026-11-01T08:05:00Z', 'consent' => ['textVersion' => 'terms-2026-09',
        'acceptedAt' => '2026-10-01T07:59:00Z', 'ip' => '203.0.113.7'],
    'sequence' => 2, 'activatedAt' => '2026-10-01T08:10:00Z']);

$client = new RecordingClient(envelope($pending), 201);
$agreement = $client->createAgreement(['planId' => PLAN_ID, 'customerReference' => 'customer-42',
    'consent' => ['textVersion' => 'terms-2026-09', 'acceptedAt' => '2026-10-01T07:59:00Z', 'ip' => '203.0.113.7'],
    'maxAmountPerPeriod' => 5000, 'futureField' => 'x', 'idempotencyKey' => AGREEMENT_KEY]);
check('createAgreement is a POST on the agreements path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'POST /merchant-api/agreements');
check('the agreements path is the published one', DominaiteClient::AGREEMENTS_PATH, '/merchant-api/agreements');
check('createAgreement signs and sends the caller key', $client->calls[0]['key'], AGREEMENT_KEY);
check('the agreement key is readable afterwards', (string) $client->getLastIdempotencyKey(), AGREEMENT_KEY);
check('createAgreement sends every other key as given, consent nested', encode($client->calls[0]['body']),
    '{"planId":"' . PLAN_ID . '","customerReference":"customer-42","consent":{"textVersion":"terms-2026-09",'
    . '"acceptedAt":"2026-10-01T07:59:00Z","ip":"203.0.113.7"},"maxAmountPerPeriod":5000,"futureField":"x"}');
check('the 201 is returned as the agreement', (string) $agreement['id'], AGREEMENT_ID);
check('the agreement has every field', keysOf($agreement), AGREEMENT_FIELDS);
foreach (['storedPaymentMethodId', 'maxAmountPerPeriod', 'periodCount', 'nextChargeAt', 'pastDueReason', 'pastDueAt',
    'consent', 'activatedAt', 'cancelledAt'] as $nullable) {
    check("a pending agreement reads the omitted $nullable as null", var_export($agreement[$nullable], true), 'NULL');
}

$client = new RecordingClient(envelope($pending), 201);
check('createAgreement without a key is refused locally',
    thrownBy(static function () use ($client): void {
        $client->createAgreement(['planId' => PLAN_ID, 'customerReference' => 'customer-42']);
    }), \InvalidArgumentException::class);
check('createAgreement without a key never reaches the transport', (string) count($client->calls), '0');

$refusals = [
    [404, 'PLAN_NOT_FOUND'], [404, 'PAYMENT_METHOD_NOT_FOUND'], [409, 'PLAN_NOT_ACTIVE'], [409, 'PAYMENT_METHOD_NOT_ACTIVE'],
    [422, 'PAYMENT_METHOD_CURRENCY_MISMATCH'], [422, 'MAX_AMOUNT_BELOW_PLAN_AMOUNT'], [422, 'AGREEMENT_CONSENT_REQUIRED'],
    [422, 'PAYMENT_METHOD_CURRENCY_UNKNOWN'], [422, 'IDEMPOTENCY_KEY_REUSED'], [400, 'INVALID_CURRENCY'],
];
foreach ($refusals as [$status, $code]) {
    check("createAgreement $status $code",
        refusal(caught(static function () use ($status, $code): void {
            (new RecordingClient(failure($status, $code), $status))
                ->createAgreement(['planId' => PLAN_ID, 'customerReference' => 'c', 'idempotencyKey' => AGREEMENT_KEY]);
        })), ApiException::class . " $status $code");
}
$below = caught(static function (): void {
    (new RecordingClient(['success' => false, 'error' => ['code' => 'MAX_AMOUNT_BELOW_PLAN_AMOUNT', 'message' => 'Too low.',
        'statusCode' => 422], 'validationErrors' => [['field' => 'maxAmountPerPeriod', 'params' => ['min' => 2500]]]], 422))
        ->createAgreement(['planId' => PLAN_ID, 'customerReference' => 'c', 'idempotencyKey' => AGREEMENT_KEY]);
});
check('a refusal keeps validationErrors params in the envelope',
    var_export($below instanceof ApiException ? ($below->getResult()['validationErrors'][0]['params']['min'] ?? null) : null, true),
    '2500');
$client = new RecordingClient(failure(503, 'MERCHANT_API_UNAVAILABLE'), 503);
check('a 503 on createAgreement is a retryable transport error',
    thrownBy(static function () use ($client): void {
        $client->createAgreement(['planId' => PLAN_ID, 'customerReference' => 'c', 'idempotencyKey' => AGREEMENT_KEY]);
    }), TransportException::class);
check('after a 503 the agreement key to retry with is readable', (string) $client->getLastIdempotencyKey(), AGREEMENT_KEY);

// getAgreement: the id verbatim; a past-due agreement carries why and since when.
$client = new RecordingClient(envelope($pastDue));
$read = $client->getAgreement(AGREEMENT_ID);
check('getAgreement is a GET on the agreement path, the id verbatim', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'GET /merchant-api/agreements/' . AGREEMENT_ID);
check('getAgreement signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
check('getAgreement leaves the last key untouched', var_export($client->getLastIdempotencyKey(), true), 'NULL');
check('a read agreement has every field', keysOf($read), AGREEMENT_FIELDS);
check('a past-due agreement says why and since when', $read['status'] . ' ' . $read['pastDueReason'] . ' ' . $read['pastDueAt'],
    'past_due card_expired 2026-11-01T08:05:00Z');
check('the agreement keeps its sequence and version', $read['sequence'] . ' ' . $read['version'], '2 1');
check('the recorded consent has every field', keysOf((array) $read['consent']), 'acceptedAt,ip,receivedAt,receivedFromIp,textVersion');
check('the consent reads the omitted receipt as null',
    var_export($read['consent']['receivedAt'], true) . ' ' . var_export($read['consent']['receivedFromIp'], true), 'NULL NULL');
check('the consent keeps what was sent', $read['consent']['textVersion'] . ' ' . $read['consent']['ip'], 'terms-2026-09 203.0.113.7');
check('getAgreement 404 AGREEMENT_NOT_FOUND',
    refusal(caught(static function (): void {
        (new RecordingClient(failure(404, 'AGREEMENT_NOT_FOUND'), 404))->getAgreement(AGREEMENT_ID);
    })), ApiException::class . ' 404 AGREEMENT_NOT_FOUND');
check('a 200 without an agreement is an ApiException',
    refusal(caught(static function (): void {
        (new RecordingClient(envelope(['status' => 'active']), 200))->getAgreement(AGREEMENT_ID);
    })), ApiException::class . ' 200 UNEXPECTED_RESPONSE');

// cancelAgreement: a 204, also for an agreement that is cancelled already.
$client = new RecordingClient([], 204);
$client->cancelAgreement(AGREEMENT_ID);
check('cancelAgreement is a DELETE on the agreement path', $client->calls[0]['method'] . ' ' . $client->calls[0]['path'],
    'DELETE /merchant-api/agreements/' . AGREEMENT_ID);
check('cancelAgreement signs an empty key and an empty body',
    var_export($client->calls[0]['key'], true) . ' ' . var_export($client->calls[0]['body'], true), "'' NULL");
foreach ([[409, 'AGREEMENT_ENDED'], [404, 'AGREEMENT_NOT_FOUND'], [403, 'INSUFFICIENT_SCOPE']] as [$status, $code]) {
    check("cancelAgreement $status $code",
        refusal(caught(static function () use ($status, $code): void {
            (new RecordingClient(failure($status, $code), $status))->cancelAgreement(AGREEMENT_ID);
        })), ($status === 403 ? AuthenticationException::class : ApiException::class) . " $status $code");
}

// The status read of a subscription session says whether the subscription started. The
// agreement's nextChargeAt is left out while it is pending, and reads as null here.
$sessionStatus = ['transactionId' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d', 'orderId' => 'ord_1',
    'orderReference' => 'subscription-42', 'status' => 'failed', 'amount' => 2500, 'currency' => 'EUR',
    'createdAt' => '2026-10-01T08:00:00Z', 'declineClass' => 'soft_funds', 'isRecurring' => false, 'sequence' => 0,
    'agreement' => ['id' => AGREEMENT_ID, 'status' => 'pending']];
$read = (new RecordingClient(envelope($sessionStatus)))->getStatus('1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d');
check('a subscription session read carries its agreement', keysOf((array) ($read['agreement'] ?? [])), 'id,nextChargeAt,status');
check('a declined subscription session leaves the agreement pending', (string) $read['agreement']['status'], 'pending');
check('a pending agreement reads the omitted nextChargeAt as null', var_export($read['agreement']['nextChargeAt'], true), 'NULL');
check('the read carries why the payment was declined', (string) $read['declineClass'], 'soft_funds');
check('a checkout payment is not recurring and has sequence 0',
    var_export($read['isRecurring'], true) . ' ' . var_export($read['sequence'], true), 'false 0');

$charged = array_merge($sessionStatus, ['status' => 'succeeded', 'isRecurring' => true, 'sequence' => 3,
    'agreement' => ['id' => AGREEMENT_ID, 'status' => 'active', 'nextChargeAt' => '2026-12-01T08:00:00Z']]);
unset($charged['declineClass']);
$read = (new RecordingClient(envelope($charged)))->getStatus('1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d');
check('a recurring charge read carries isRecurring and its sequence',
    var_export($read['isRecurring'], true) . ' ' . var_export($read['sequence'], true), 'true 3');
check('an active agreement keeps its next charge', (string) $read['agreement']['nextChargeAt'], '2026-12-01T08:00:00Z');
check('a read without declineClass does not invent it', array_key_exists('declineClass', $read) ? 'invented' : 'absent', 'absent');
unset($charged['agreement']);
$read = (new RecordingClient(envelope($charged)))->getStatus('1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d');
check('a one-off payment read has no agreement key', array_key_exists('agreement', $read) ? 'invented' : 'absent', 'absent');

foreach (['', 'agr_1/x', 'agr_1?x=1', 'agr 1', str_repeat('a', 101)] as $bad) {
    $client = new RecordingClient(envelope($pending));
    foreach (['getAgreement', 'cancelAgreement'] as $route) {
        check("$route refuses agreement id " . var_export($bad, true),
            thrownBy(static function () use ($client, $route, $bad): void {
                $client->$route($bad);
            }), \InvalidArgumentException::class);
    }
    check('the bad agreement id ' . var_export($bad, true) . ' never reached the transport', (string) count($client->calls), '0');
}

exit($failures === 0 ? 0 : 1);
