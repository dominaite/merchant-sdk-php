<?php
// Dependency-free contract tests for the merchant-API RESPONSE shape.
// Run: php tests/contract_vectors.php - exits non-zero on any mismatch.
//
// tests/merchant-api-contract.json is the canonical cross-SDK fixture, vendored
// byte-identical into every Dominaite SDK. It is the source of truth: when this file
// and the fixture disagree, the SDK is what changes. Never edit the fixture to make a
// test pass - get the gateway DTO changed and re-vendor it everywhere.
//
// What this pins: the status vocabulary, the refusal error codes, and the exact field
// set of every response the SDK hands back. A field the gateway silently adds, drops
// or renames fails here instead of surfacing as a missing array key in a merchant's
// production code.

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
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\RevokeException;
use Dominaite\Exception\TransportException;

$failures = 0;

// A contract break often lands as a TypeError rather than a false assertion - the SDK
// returning null where its signature promises an array, say. Report those as a named
// failure instead of a bare PHP fatal, so CI output says what broke.
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

/** Field sets are compared as sorted, comma-joined key lists so a diff names the field. */
function keysOf(array $value): string
{
    $keys = array_keys($value);
    sort($keys);
    return implode(',', $keys);
}

function listOf(array $value): string
{
    return implode(',', $value);
}

/** The fixture lists fields in wire order; compare them sorted, the way keysOf() reports. */
function sortedList(array $value): string
{
    sort($value);
    return implode(',', $value);
}

/**
 * The fixture spells nullable fields out as null; the gateway omits them on the wire
 * (System.Text.Json with WhenWritingNull). Every example is pushed through in both
 * forms, and the SDK must read them identically.
 *
 * @param array<mixed> $value
 * @return array<mixed>
 */
function withoutNulls(array $value): array
{
    $out = [];
    foreach ($value as $key => $item) {
        if ($item === null) {
            continue;
        }
        $out[$key] = is_array($item) ? withoutNulls($item) : $item;
    }
    return $out;
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

/**
 * A client whose transport is replaced by canned gateway responses, so the response
 * handling runs for real without a network call: the canned body goes through the
 * real parser with the canned HTTP status. Records the request line the SDK would
 * have signed, which pins the method and path per endpoint too.
 */
final class CannedClient extends DominaiteClient
{
    /** @var array<string,mixed> */
    private array $canned;
    private int $status;

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> The idempotency key the SDK signed, per call. */
    public array $idempotencyKeys = [];

    /** @param array<string,mixed> $canned */
    public function __construct(array $canned, int $status = 200)
    {
        parent::__construct(
            'dmk_0123456789abcdef',
            'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
        );
        $this->canned = $canned;
        $this->status = $status;
    }

    protected function send(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $this->calls[] = $method . ' ' . $path;
        $this->idempotencyKeys[] = $idempotencyKey;
        $raw = $this->status === 204 ? '' : (string) json_encode($this->canned);
        return $this->readResponse($this->status, $raw, []);
    }
}

$raw = file_get_contents(__DIR__ . '/merchant-api-contract.json');
if ($raw === false) {
    echo "FAIL cannot read tests/merchant-api-contract.json\n";
    exit(1);
}
$fixture = json_decode($raw, true);
if (!is_array($fixture)) {
    echo "FAIL tests/merchant-api-contract.json is not valid JSON\n";
    exit(1);
}

check('fixture version', (string) $fixture['version'], 'v1');

// 1. The status vocabulary is EXACTLY the fixture's, in the same order. Order is part of
//    the pin: it makes an accidental reordering during a merge visible.
check('status vocabulary matches the fixture',
    listOf(DominaiteClient::STATUS_VOCABULARY), listOf($fixture['statusVocabulary']));
check('status vocabulary size', (string) count(DominaiteClient::STATUS_VOCABULARY), '10');

// The docblock on getStatus() is where an integrator actually reads the vocabulary, so it
// has to carry every value too - a status documented nowhere is a status nobody handles.
$getStatusDoc = (string) (new ReflectionMethod(DominaiteClient::class, 'getStatus'))->getDocComment();
foreach ($fixture['statusVocabulary'] as $status) {
    check("getStatus() documents status: $status",
        strpos($getStatusDoc, $status) !== false ? 'documented' : 'missing', 'documented');
}

// 2. Every refusal code the fixture names is recognised by the SDK, and the SDK invents
//    none of its own - a code nobody can receive is as much a bug as a missing one.
foreach ($fixture['sessionRefusalErrorCodes'] as $code) {
    check("refusal code recognised: $code",
        in_array($code, DominaiteClient::REFUSAL_ERROR_CODES, true) ? 'known' : 'unknown', 'known');
}
check('refusal codes match the fixture exactly',
    sortedList(DominaiteClient::REFUSAL_ERROR_CODES), sortedList($fixture['sessionRefusalErrorCodes']));

// Validation codes are the HTTP 400 family, kept separate from the success=false refusals
// because a caller must not retry them the same way.
check('validation codes match the fixture exactly',
    sortedList(DominaiteClient::VALIDATION_ERROR_CODES), sortedList($fixture['validationErrorCodes']));
check('validation and refusal codes do not overlap',
    listOf(array_intersect(DominaiteClient::VALIDATION_ERROR_CODES, DominaiteClient::REFUSAL_ERROR_CODES)), '');

// Each code survives the round trip into the exception the caller branches on.
foreach (DominaiteClient::REFUSAL_ERROR_CODES as $code) {
    $client = new CannedClient([
        'success' => false,
        'checkout' => null,
        'transactionId' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
        'errorCode' => $code,
        'errorMessage' => 'Refused.',
    ]);
    try {
        $client->createCheckoutSession(['amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042',
            'idempotencyKey' => 'order-1042']);
        check("refusal surfaces errorCode: $code", 'returned normally', $code);
    } catch (CheckoutRefusedException $refusal) {
        check("refusal surfaces errorCode: $code", $refusal->getErrorCode(), $code);
    }
}

// 3. The endpoints. Each canned response is the fixture's own example object.
$endpoints = $fixture['endpoints'];

// --- ping ---------------------------------------------------------------------------
check('ping path matches the fixture', DominaiteClient::PING_PATH, (string) $endpoints['ping']['path']);

$pingClient = new CannedClient($endpoints['ping']['example']);
$ping = $pingClient->ping();
check('ping accepts the fixture example', keysOf($ping), keysOf($endpoints['ping']['example']));
check('ping field set matches the fixture', keysOf($ping), sortedList($endpoints['ping']['fields']));
check('ping is a GET on the fixture path', listOf($pingClient->calls),
    'GET ' . $endpoints['ping']['path']);
check('ping reads pong', var_export($ping['pong'], true), 'true');
check('ping reads clockSkewSeconds', (string) $ping['clockSkewSeconds'], '2');

// --- createCheckoutSession, success ---------------------------------------------------
$session = $endpoints['createCheckoutSession'];
check('sessions path matches the fixture', DominaiteClient::SESSIONS_PATH, (string) $session['path']);

$successClient = new CannedClient($session['successExample']);
$checkout = $successClient->createCheckoutSession([
    'amount' => 8440,
    'currency' => 'EUR',
    'orderReference' => 'order-1042',
    'idempotencyKey' => 'order-1042',
]);
check('createCheckoutSession accepts the fixture success example',
    keysOf($checkout), keysOf($session['successExample']['checkout']));
check('checkout field set matches the fixture', keysOf($checkout), sortedList($session['checkoutFields']));
check('createCheckoutSession is a POST on the fixture path', listOf($successClient->calls),
    'POST ' . $session['path']);
check('checkout carries the transaction id', (string) $checkout['transactionId'],
    (string) $session['successExample']['checkout']['transactionId']);
check('checkout amount stays an int in minor units',
    var_export($checkout['amount'], true), '8440');

// IDEMPOTENCY_KEY_REQUIRED is a gateway 400 this SDK must never provoke: a POST without
// a key is refused locally, before anything is signed, and a GET signs the empty key by
// design. There is no generated fallback - a random key per call is a second payment on
// every reload.
$keyless = new CannedClient($session['successExample']);
$outcome = 'accepted';
try {
    $keyless->createCheckoutSession(['amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042']);
} catch (\InvalidArgumentException $e) {
    $outcome = 'rejected locally';
}
check('POST without an idempotency key is rejected before the call', $outcome, 'rejected locally');
check('the keyless POST never reached the transport', (string) count($keyless->calls), '0');
check('GET signs an empty idempotency key', listOf($pingClient->idempotencyKeys), '');

// A caller-supplied key is passed through untouched, and an illegal one is the caller's
// bug - raised locally rather than spent on a round trip that can only 400.
$echoClient = new CannedClient($session['successExample']);
$echoClient->createCheckoutSession([
    'amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042',
    'idempotencyKey' => 'order-1042-attempt-1',
]);
check('caller idempotency key is used verbatim',
    listOf($echoClient->idempotencyKeys), 'order-1042-attempt-1');

foreach (['empty' => '', 'too long' => str_repeat('k', 101)] as $label => $bad) {
    $badClient = new CannedClient($session['successExample']);
    $outcome = 'accepted';
    try {
        $badClient->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042',
            'idempotencyKey' => $bad,
        ]);
    } catch (\InvalidArgumentException $e) {
        $outcome = 'rejected locally';
    }
    check("illegal idempotency key rejected before the call: $label", $outcome, 'rejected locally');
}

// The envelope itself: whatever the SDK unwraps, the top-level refusal fields the fixture
// names have to be the ones it branches on. Assert the fixture example carries exactly them.
check('session response field set matches the fixture',
    keysOf($session['successExample']), sortedList($session['fields']));

// --- createCheckoutSession, refusal ---------------------------------------------------
// A refusal is HTTP 200 with success=false. The SDK must raise, not return a half-session.
$refusalClient = new CannedClient($session['refusalExample']);
$verdict = 'returned normally';
$refusalCode = '';
$refusalTxn = '';
try {
    $refusalClient->createCheckoutSession([
        'amount' => 8440,
        'currency' => 'EUR',
        'orderReference' => 'order-1042',
        'idempotencyKey' => 'order-1042',
    ]);
} catch (CheckoutRefusedException $refusal) {
    $verdict = 'threw CheckoutRefusedException';
    $refusalCode = $refusal->getErrorCode();
    $refusalTxn = (string) $refusal->getTransactionId();
    check('refusal payload field set matches the fixture',
        keysOf($refusal->getResult()), sortedList($session['fields']));
}
check('createCheckoutSession refuses on success=false', $verdict, 'threw CheckoutRefusedException');
check('refusal carries the fixture errorCode', $refusalCode, (string) $session['refusalExample']['errorCode']);
check('refusal names the colliding transaction', $refusalTxn,
    (string) $session['refusalExample']['transactionId']);
check('refusal errorCode is one the SDK knows',
    in_array($refusalCode, DominaiteClient::REFUSAL_ERROR_CODES, true) ? 'known' : 'unknown', 'known');

// --- getStatus ------------------------------------------------------------------------
$status = $endpoints['getStatus'];
$statusClient = new CannedClient($status['example']);
$result = $statusClient->getStatus($status['example']['transactionId']);
check('getStatus accepts the fixture example', keysOf($result), keysOf($status['example']));
check('getStatus field set matches the fixture', keysOf($result), sortedList($status['fields']));
check('getStatus is a GET on the fixture path', listOf($statusClient->calls),
    'GET ' . str_replace('{transactionId}', (string) $status['example']['transactionId'], (string) $status['path']));
check('getStatus reads a vocabulary status',
    in_array($result['status'], DominaiteClient::STATUS_VOCABULARY, true) ? 'in vocabulary' : $result['status'],
    'in vocabulary');

// Nullable fields are PRESENT and null, not absent - a caller reading $r['expiresAt']
// must not trip an undefined-key warning just because the payer's window is over.
foreach (['refundedAmount', 'expiresAt'] as $nullable) {
    check("getStatus keeps $nullable present when null",
        array_key_exists($nullable, $result) ? 'present' : 'absent', 'present');
    check("getStatus $nullable is null in the fixture example",
        var_export($result[$nullable], true), 'NULL');
}

// Every status in the vocabulary deserializes the same way - the example with its status
// swapped is still a well-formed response, so no value is special-cased.
foreach (DominaiteClient::STATUS_VOCABULARY as $value) {
    $example = $status['example'];
    $example['status'] = $value;
    $client = new CannedClient($example);
    $read = $client->getStatus($example['transactionId']);
    check("getStatus accepts status: $value", (string) $read['status'], $value);
}

// --- stored payment methods -----------------------------------------------------------
// The vocabularies, in the fixture's order, like STATUS_VOCABULARY above.
check('stored payment method status vocabulary matches the fixture',
    listOf(DominaiteClient::STORED_PAYMENT_METHOD_STATUS_VOCABULARY), listOf($fixture['storedPaymentMethodStatusVocabulary']));
check('charge status vocabulary matches the fixture',
    listOf(DominaiteClient::CHARGE_STATUS_VOCABULARY), listOf($fixture['chargeStatusVocabulary']));
check('decline class vocabulary matches the fixture',
    listOf(DominaiteClient::DECLINE_CLASS_VOCABULARY), listOf($fixture['declineClassVocabulary']));
check('charge error codes match the fixture',
    listOf(DominaiteClient::CHARGE_ERROR_CODES), listOf($fixture['chargeErrorCodes']));
check('revoke error codes match the fixture',
    listOf(DominaiteClient::REVOKE_ERROR_CODES), listOf($fixture['revokeErrorCodes']));
check('CHARGE_DECLINED is a result, never a charge error code',
    in_array('CHARGE_DECLINED', DominaiteClient::CHARGE_ERROR_CODES, true) ? 'listed' : 'not listed', 'not listed');

// getStatus() without a saved card: the fixture spells storedPaymentMethod out as null,
// the wire omits it. Both read as "no card on file"; the SDK adds no key of its own.
check('getStatus keeps storedPaymentMethod present when the gateway sent null',
    array_key_exists('storedPaymentMethod', $result) ? 'present' : 'absent', 'present');
check('getStatus storedPaymentMethod is null in the fixture example', var_export($result['storedPaymentMethod'], true), 'NULL');
$wire = (new CannedClient(withoutNulls($status['example'])))->getStatus($status['example']['transactionId']);
check('getStatus adds no storedPaymentMethod key when the gateway omitted it',
    array_key_exists('storedPaymentMethod', $wire) ? 'present' : 'absent', 'absent');
check('an omitted storedPaymentMethod reads as null with ??', var_export($wire['storedPaymentMethod'] ?? null, true), 'NULL');

// With a saved card: the card comes through with exactly the fixture's fields.
$savedClient = new CannedClient($status['savedCardExample']);
$saved = $savedClient->getStatus($status['savedCardExample']['transactionId']);
check('getStatus accepts the fixture saved-card example', keysOf($saved), sortedList($status['fields']));
check('saved-card example field set matches the fixture', keysOf($status['savedCardExample']), sortedList($status['fields']));
check('storedPaymentMethod field set matches the fixture',
    keysOf($saved['storedPaymentMethod']), sortedList($status['storedPaymentMethodFields']));
check('storedPaymentMethod status is in the vocabulary',
    in_array($saved['storedPaymentMethod']['status'], DominaiteClient::STORED_PAYMENT_METHOD_STATUS_VOCABULARY, true) ? 'in vocabulary' : $saved['storedPaymentMethod']['status'],
    'in vocabulary');
check('storedPaymentMethod id is pm_ plus 32 hex characters',
    preg_match('/^pm_[0-9a-f]{32}$/', (string) $saved['storedPaymentMethod']['id']) === 1 ? 'well-formed' : (string) $saved['storedPaymentMethod']['id'], 'well-formed');
check('storedPaymentMethod expiry stays an int', var_export($saved['storedPaymentMethod']['expiryYear'], true), '2029');
$paymentMethodId = (string) $saved['storedPaymentMethod']['id'];

// The card's own nullable fields: absent on the wire when the provider did not report
// them, present and null once read.
$bare = $status['savedCardExample'];
foreach (['brand', 'last4', 'expiryMonth', 'expiryYear'] as $field) {
    $bare['storedPaymentMethod'][$field] = null;
}
$bareRead = (new CannedClient(withoutNulls($bare)))->getStatus($bare['transactionId']);
check('a card without brand, last4 and expiry on the wire still has every field',
    keysOf($bareRead['storedPaymentMethod']), sortedList($status['storedPaymentMethodFields']));
foreach (['brand', 'last4', 'expiryMonth', 'expiryYear'] as $field) {
    check("unreported card $field reads as null", var_export($bareRead['storedPaymentMethod'][$field], true), 'NULL');
}
check('the bare card keeps its id', (string) $bareRead['storedPaymentMethod']['id'], $paymentMethodId);

// Every stored payment method status deserializes the same way.
foreach (DominaiteClient::STORED_PAYMENT_METHOD_STATUS_VOCABULARY as $value) {
    $example = $status['savedCardExample'];
    $example['storedPaymentMethod']['status'] = $value;
    $read = (new CannedClient($example))->getStatus($example['transactionId']);
    check("getStatus accepts storedPaymentMethod status: $value", (string) $read['storedPaymentMethod']['status'], $value);
}

// The getStatus() docblock is where an integrator reads about the saved card too.
check('getStatus() documents storedPaymentMethod', strpos($getStatusDoc, 'storedPaymentMethod') !== false ? 'documented' : 'missing', 'documented');

// --- chargePaymentMethod --------------------------------------------------------------
$charge = $endpoints['chargePaymentMethod'];
check('charge path matches the fixture',
    DominaiteClient::PAYMENT_METHODS_PATH . '/{paymentMethodId}/charges', (string) $charge['path']);
check('charge is a POST that answers 201 and declines with 402',
    $charge['method'] . ' ' . $charge['httpStatus'] . ' ' . $charge['declinedHttpStatus'], 'POST 201 402');
$chargeParams = ['amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042',
    'idempotencyKey' => 'renewal-order-1042-8440-EUR'];

// 201: the envelope is unwrapped and the charge is exactly the fixture's fields.
$chargeClient = new CannedClient($charge['successExample'], 201);
$chargeResult = $chargeClient->chargePaymentMethod($paymentMethodId, $chargeParams);
check('chargePaymentMethod accepts the fixture success example', keysOf($chargeResult), keysOf($charge['successExample']['data']));
check('charge field set matches the fixture', keysOf($chargeResult), sortedList($charge['fields']));
check('chargePaymentMethod is a POST on the fixture path', listOf($chargeClient->calls),
    'POST ' . str_replace('{paymentMethodId}', $paymentMethodId, (string) $charge['path']));
check('chargePaymentMethod sends the caller idempotency key verbatim',
    listOf($chargeClient->idempotencyKeys), 'renewal-order-1042-8440-EUR');
check('charge reads a vocabulary status',
    in_array($chargeResult['status'], DominaiteClient::CHARGE_STATUS_VOCABULARY, true) ? 'in vocabulary' : $chargeResult['status'],
    'in vocabulary');
check('chargeId is ch_ plus 32 hex characters',
    preg_match('/^ch_[0-9a-f]{32}$/', (string) $chargeResult['chargeId']) === 1 ? 'well-formed' : (string) $chargeResult['chargeId'], 'well-formed');
foreach (['declineClass', 'declineCode'] as $nullable) {
    check("succeeded charge keeps $nullable present when null",
        array_key_exists($nullable, $chargeResult) ? 'present' : 'absent', 'present');
    check("succeeded charge $nullable is null in the fixture example", var_export($chargeResult[$nullable], true), 'NULL');
}

// The wire form of a 201 has no declineClass or declineCode at all; the result still does.
$wireCharge = (new CannedClient(withoutNulls($charge['successExample']), 201))->chargePaymentMethod($paymentMethodId, $chargeParams);
check('a 201 without declineClass on the wire still has every field', keysOf($wireCharge), sortedList($charge['fields']));
check('a 201 without declineClass on the wire reads it as null', var_export($wireCharge['declineClass'], true), 'NULL');
check('a 201 without declineCode on the wire reads it as null', var_export($wireCharge['declineCode'], true), 'NULL');

// A durable replay answers 200 with the same body; still a charge.
$replay = (new CannedClient($charge['successExample'], 200))->chargePaymentMethod($paymentMethodId, $chargeParams);
check('a 200 replay is returned as the charge', (string) $replay['chargeId'], (string) $charge['successExample']['data']['chargeId']);

// 402: a decline is a result with a class, not an exception, even though the envelope
// says success=false and carries CHARGE_DECLINED.
check('declined example field set matches the fixture', keysOf($charge['declinedExample']['data']), sortedList($charge['fields']));
check('the declined example carries CHARGE_DECLINED', (string) $charge['declinedExample']['error']['code'], 'CHARGE_DECLINED');
$declinedClient = new CannedClient($charge['declinedExample'], 402);
$declined = $declinedClient->chargePaymentMethod($paymentMethodId, $chargeParams);
check('a 402 decline is returned, not thrown', (string) $declined['status'], 'failed');
check('declined charge field set matches the fixture', keysOf($declined), sortedList($charge['fields']));
check('declined charge carries a vocabulary declineClass',
    in_array($declined['declineClass'], DominaiteClient::DECLINE_CLASS_VOCABULARY, true) ? 'in vocabulary' : (string) $declined['declineClass'],
    'in vocabulary');
check('declined charge carries the raw declineCode', (string) $declined['declineCode'], '51');
check('declined charge names its transaction', (string) $declined['transactionId'], (string) $charge['declinedExample']['data']['transactionId']);

// Every charge status and every decline class deserializes the same way.
foreach (DominaiteClient::CHARGE_STATUS_VOCABULARY as $value) {
    $example = $charge['successExample'];
    $example['data']['status'] = $value;
    $read = (new CannedClient($example, 201))->chargePaymentMethod($paymentMethodId, $chargeParams);
    check("chargePaymentMethod accepts status: $value", (string) $read['status'], $value);
}
foreach (DominaiteClient::DECLINE_CLASS_VOCABULARY as $value) {
    $example = $charge['declinedExample'];
    $example['data']['declineClass'] = $value;
    $read = (new CannedClient($example, 402))->chargePaymentMethod($paymentMethodId, $chargeParams);
    check("chargePaymentMethod accepts declineClass: $value", (string) $read['declineClass'], $value);
}

// 409, 422, 502, 503: every error example is a ChargeException keeping the status, the
// code, the message and whatever charge row the gateway attached - in both wire forms.
$seenCodes = [];
foreach ($charge['errorExamples'] as $index => $example) {
    $label = $example['httpStatus'] . ' ' . $example['code'] . (isset($example['body']['data']) ? ' with data' : ' without data');
    $seenCodes[] = $example['code'];
    check("charge error example $label names a known code",
        in_array($example['code'], DominaiteClient::CHARGE_ERROR_CODES, true) ? 'known' : 'unknown', 'known');
    check("charge error example $label carries its code at error.code", (string) $example['body']['error']['code'], (string) $example['code']);
    check("charge error example $label carries its status at error.statusCode", (string) $example['body']['error']['statusCode'], (string) $example['httpStatus']);

    foreach (['as spelled' => $example['body'], 'on the wire' => withoutNulls($example['body'])] as $form => $body) {
        $thrown = null;
        try {
            (new CannedClient($body, (int) $example['httpStatus']))->chargePaymentMethod($paymentMethodId, $chargeParams);
        } catch (ChargeException $e) {
            $thrown = $e;
        }
        check("charge $label $form throws ChargeException", $thrown === null ? 'no exception' : get_class($thrown), ChargeException::class);
        if ($thrown === null) {
            continue;
        }
        check("charge $label $form keeps the HTTP status", (string) $thrown->getHttpStatus(), (string) $example['httpStatus']);
        check("charge $label $form keeps error.code", $thrown->getErrorCode(), (string) $example['code']);
        check("charge $label $form keeps error.message", $thrown->getMessage(), (string) $example['body']['error']['message']);
        check("charge $label $form keeps the whole envelope", keysOf($thrown->getResult()), keysOf($body));
        if (isset($example['body']['data'])) {
            $attached = $thrown->getCharge();
            check("charge $label $form attaches the charge row", $attached === null ? 'null' : keysOf($attached), sortedList($charge['fields']));
            check("charge $label $form attaches the charge id", (string) ($attached['chargeId'] ?? ''), (string) $example['body']['data']['chargeId']);
            check("charge $label $form names the transaction to poll", (string) $thrown->getTransactionId(), (string) $example['body']['data']['transactionId']);
            check("charge $label $form reads declineClass as null",
                is_array($attached) && array_key_exists('declineClass', $attached) ? var_export($attached['declineClass'], true) : 'missing', 'NULL');
        } else {
            check("charge $label $form attaches no charge row", $thrown->getCharge() === null ? 'null' : 'attached', 'null');
            check("charge $label $form has no transaction to poll", $thrown->getTransactionId() === null ? 'null' : 'set', 'null');
        }
    }
}
check('the fixture exercises every charge error code',
    sortedList(array_values(array_unique($seenCodes))), sortedList(DominaiteClient::CHARGE_ERROR_CODES));
check('CHARGE_OUTCOME_UNKNOWN is the example that carries a transaction to poll',
    (string) $charge['errorExamples'][0]['code'] . ' ' . (isset($charge['errorExamples'][0]['body']['data']['transactionId']) ? 'with transactionId' : 'without'),
    'CHARGE_OUTCOME_UNKNOWN with transactionId');

// 404: an id that is not yours stays the generic ApiException, code and all.
$notFound = $charge['notFoundExample'];
$missing = null;
try {
    (new CannedClient($notFound['body'], (int) $notFound['httpStatus']))->chargePaymentMethod($paymentMethodId, $chargeParams);
} catch (ApiException $e) {
    $missing = $e;
}
check('a charge on an id that is not yours is an ApiException', $missing === null ? 'no exception' : get_class($missing), ApiException::class);
check('the charge 404 keeps its status', (string) ($missing === null ? 0 : $missing->getHttpStatus()), (string) $notFound['httpStatus']);
check('the charge 404 keeps its code', (string) ($missing === null ? '' : $missing->getErrorCode()), (string) $notFound['code']);
check('the charge 404 keeps its message', $missing === null ? '' : $missing->getMessage(), (string) $notFound['body']['error']['message']);

// A 5xx without the gateway's envelope is the platform being down, not a charge error.
check('a codeless 502 on a charge is a retryable transport error',
    thrownBy(static function () use ($paymentMethodId, $chargeParams): void {
        (new CannedClient(['success' => false], 502))->chargePaymentMethod($paymentMethodId, $chargeParams);
    }), TransportException::class);
// A 2xx without a charge body is not a charge, whatever success says.
check('a 201 without a charge body is an ApiException',
    thrownBy(static function () use ($paymentMethodId, $chargeParams): void {
        (new CannedClient(['success' => true], 201))->chargePaymentMethod($paymentMethodId, $chargeParams);
    }), ApiException::class);

// --- revokePaymentMethod --------------------------------------------------------------
$revoke = $endpoints['revokePaymentMethod'];
check('revoke path matches the fixture',
    DominaiteClient::PAYMENT_METHODS_PATH . '/{paymentMethodId}', (string) $revoke['path']);
check('revoke is a DELETE that answers 204 with no fields',
    $revoke['method'] . ' ' . $revoke['httpStatus'] . ' ' . count($revoke['fields']), 'DELETE 204 0');

$revokeClient = new CannedClient([], 204);
$revokeClient->revokePaymentMethod($paymentMethodId);
check('revokePaymentMethod is a DELETE on the fixture path', listOf($revokeClient->calls),
    'DELETE ' . str_replace('{paymentMethodId}', $paymentMethodId, (string) $revoke['path']));
check('DELETE signs an empty idempotency key', listOf($revokeClient->idempotencyKeys), '');

// 502 and 503: a RevokeException keeping the status, the code and the message.
$seenCodes = [];
foreach ($revoke['errorExamples'] as $example) {
    $label = $example['httpStatus'] . ' ' . $example['code'];
    $seenCodes[] = $example['code'];
    check("revoke error example $label names a known code",
        in_array($example['code'], DominaiteClient::REVOKE_ERROR_CODES, true) ? 'known' : 'unknown', 'known');
    $thrown = null;
    try {
        (new CannedClient($example['body'], (int) $example['httpStatus']))->revokePaymentMethod($paymentMethodId);
    } catch (RevokeException $e) {
        $thrown = $e;
    }
    check("revoke $label throws RevokeException", $thrown === null ? 'no exception' : get_class($thrown), RevokeException::class);
    if ($thrown === null) {
        continue;
    }
    check("revoke $label keeps the HTTP status", (string) $thrown->getHttpStatus(), (string) $example['httpStatus']);
    check("revoke $label keeps error.code", $thrown->getErrorCode(), (string) $example['code']);
    check("revoke $label keeps error.message", $thrown->getMessage(), (string) $example['body']['error']['message']);
    check("revoke $label keeps the whole envelope", keysOf($thrown->getResult()), keysOf($example['body']));
}
check('the fixture exercises every revoke error code',
    sortedList(array_values(array_unique($seenCodes))), sortedList(DominaiteClient::REVOKE_ERROR_CODES));

// 404: the gateway's validation shape, still the generic ApiException.
$notFound = $revoke['notFoundExample'];
$missing = null;
try {
    (new CannedClient($notFound['body'], (int) $notFound['httpStatus']))->revokePaymentMethod($paymentMethodId);
} catch (ApiException $e) {
    $missing = $e;
}
check('a revoke on an id that is not yours is an ApiException', $missing === null ? 'no exception' : get_class($missing), ApiException::class);
check('the revoke 404 keeps its status', (string) ($missing === null ? 0 : $missing->getHttpStatus()), (string) $notFound['httpStatus']);
check('the revoke 404 keeps its code', (string) ($missing === null ? '' : $missing->getErrorCode()), (string) $notFound['code']);
check('a codeless 503 on a revoke is a retryable transport error',
    thrownBy(static function () use ($paymentMethodId): void {
        (new CannedClient(['success' => false], 503))->revokePaymentMethod($paymentMethodId);
    }), TransportException::class);

exit($failures === 0 ? 0 : 1);
