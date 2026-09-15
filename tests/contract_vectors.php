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
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\CheckoutRefusedException;

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
 * A client whose transport is replaced by canned gateway responses, so the response
 * handling runs for real without a network call. Records the request line the SDK
 * would have signed, which pins the method and path per endpoint too.
 */
final class CannedClient extends DominaiteClient
{
    /** @var array<string,mixed> */
    private array $canned;

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> The idempotency key the SDK signed, per call. */
    public array $idempotencyKeys = [];

    /** @param array<string,mixed> $canned */
    public function __construct(array $canned)
    {
        parent::__construct(
            'dmk_0123456789abcdef',
            'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'
        );
        $this->canned = $canned;
    }

    protected function request(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $this->calls[] = $method . ' ' . $path;
        $this->idempotencyKeys[] = $idempotencyKey;
        return $this->canned;
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
        $client->createCheckoutSession(['amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042']);
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

// IDEMPOTENCY_KEY_REQUIRED is a gateway 400 this SDK must never provoke: a POST always
// carries a generated key of legal length, and a GET signs the empty key by design.
$generated = $successClient->idempotencyKeys[0];
check('POST generates an idempotency key when the caller omits one',
    $generated !== '' ? 'generated' : 'empty', 'generated');
check('generated idempotency key is within the 1-100 char limit',
    strlen($generated) >= 1 && strlen($generated) <= 100 ? 'in range' : 'len ' . strlen($generated), 'in range');
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
check('payment method status vocabulary matches the fixture',
    listOf(DominaiteClient::PAYMENT_METHOD_STATUS_VOCABULARY), listOf($fixture['paymentMethodStatusVocabulary']));
check('charge status vocabulary matches the fixture',
    listOf(DominaiteClient::CHARGE_STATUS_VOCABULARY), listOf($fixture['chargeStatusVocabulary']));
check('decline class vocabulary matches the fixture',
    listOf(DominaiteClient::DECLINE_CLASS_VOCABULARY), listOf($fixture['declineClassVocabulary']));

// getStatus() with a saved card: the payment method comes through with exactly the
// fixture's fields, and the plain example keeps paymentMethod PRESENT and null.
check('getStatus keeps paymentMethod present when null',
    array_key_exists('paymentMethod', $result) ? 'present' : 'absent', 'present');
check('getStatus paymentMethod is null in the fixture example', var_export($result['paymentMethod'], true), 'NULL');

$savedClient = new CannedClient($status['savedCardExample']);
$saved = $savedClient->getStatus($status['savedCardExample']['transactionId']);
check('getStatus accepts the fixture saved-card example', keysOf($saved), sortedList($status['fields']));
check('saved-card example field set matches the fixture', keysOf($status['savedCardExample']), sortedList($status['fields']));
check('paymentMethod field set matches the fixture',
    keysOf($saved['paymentMethod']), sortedList($status['paymentMethodFields']));
check('paymentMethod status is in the vocabulary',
    in_array($saved['paymentMethod']['status'], DominaiteClient::PAYMENT_METHOD_STATUS_VOCABULARY, true) ? 'in vocabulary' : $saved['paymentMethod']['status'],
    'in vocabulary');
check('paymentMethod expiry stays an int', var_export($saved['paymentMethod']['expiryYear'], true), '2029');
$paymentMethodId = (string) $saved['paymentMethod']['id'];

// The getStatus() docblock is where an integrator reads about the saved card too.
check('getStatus() documents paymentMethod', strpos($getStatusDoc, 'paymentMethod') !== false ? 'documented' : 'missing', 'documented');

// --- chargePaymentMethod --------------------------------------------------------------
$charge = $endpoints['chargePaymentMethod'];
check('charge path matches the fixture',
    DominaiteClient::PAYMENT_METHODS_PATH . '/{paymentMethodId}/charges', (string) $charge['path']);
check('charge is a POST that answers 201', $charge['method'] . ' ' . $charge['httpStatus'], 'POST 201');
$chargeParams = ['amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042'];

$chargeClient = new CannedClient($charge['successExample']);
$chargeResult = $chargeClient->chargePaymentMethod($paymentMethodId, $chargeParams);
check('chargePaymentMethod accepts the fixture success example', keysOf($chargeResult), keysOf($charge['successExample']));
check('charge field set matches the fixture', keysOf($chargeResult), sortedList($charge['fields']));
check('chargePaymentMethod is a POST on the fixture path', listOf($chargeClient->calls),
    'POST ' . str_replace('{paymentMethodId}', $paymentMethodId, (string) $charge['path']));
check('chargePaymentMethod generates an idempotency key when the caller omits one',
    $chargeClient->idempotencyKeys[0] !== '' ? 'generated' : 'empty', 'generated');
check('charge reads a vocabulary status',
    in_array($chargeResult['status'], DominaiteClient::CHARGE_STATUS_VOCABULARY, true) ? 'in vocabulary' : $chargeResult['status'],
    'in vocabulary');
foreach (['declineClass', 'declineCode'] as $nullable) {
    check("succeeded charge keeps $nullable present when null",
        array_key_exists($nullable, $chargeResult) ? 'present' : 'absent', 'present');
    check("succeeded charge $nullable is null in the fixture example", var_export($chargeResult[$nullable], true), 'NULL');
}

// A decline is a 201 result with a class, not an exception.
$declinedClient = new CannedClient($charge['declinedExample']);
$declined = $declinedClient->chargePaymentMethod($paymentMethodId, $chargeParams);
check('declined example field set matches the fixture', keysOf($charge['declinedExample']), sortedList($charge['fields']));
check('a declined charge is returned, not thrown', (string) $declined['status'], 'failed');
check('declined charge carries a vocabulary declineClass',
    in_array($declined['declineClass'], DominaiteClient::DECLINE_CLASS_VOCABULARY, true) ? 'in vocabulary' : (string) $declined['declineClass'],
    'in vocabulary');
check('declined charge carries the raw declineCode', (string) $declined['declineCode'], '51');

// Every charge status and every decline class deserializes the same way.
foreach (DominaiteClient::CHARGE_STATUS_VOCABULARY as $value) {
    $example = $charge['successExample'];
    $example['status'] = $value;
    $read = (new CannedClient($example))->chargePaymentMethod($paymentMethodId, $chargeParams);
    check("chargePaymentMethod accepts status: $value", (string) $read['status'], $value);
}
foreach (DominaiteClient::DECLINE_CLASS_VOCABULARY as $value) {
    $example = $charge['declinedExample'];
    $example['declineClass'] = $value;
    $read = (new CannedClient($example))->chargePaymentMethod($paymentMethodId, $chargeParams);
    check("chargePaymentMethod accepts declineClass: $value", (string) $read['declineClass'], $value);
}

// A refusal reuses the create endpoint's success=false shape and must raise.
foreach (DominaiteClient::REFUSAL_ERROR_CODES as $code) {
    $client = new CannedClient([
        'success' => false,
        'transactionId' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
        'errorCode' => $code,
        'errorMessage' => 'Refused.',
    ]);
    try {
        $client->chargePaymentMethod($paymentMethodId, $chargeParams);
        check("charge refusal surfaces errorCode: $code", 'returned normally', $code);
    } catch (CheckoutRefusedException $refusal) {
        check("charge refusal surfaces errorCode: $code", $refusal->getErrorCode(), $code);
        check("charge refusal names the transaction: $code", (string) $refusal->getTransactionId(), '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d');
    }
}

// --- revokePaymentMethod --------------------------------------------------------------
$revoke = $endpoints['revokePaymentMethod'];
check('revoke path matches the fixture',
    DominaiteClient::PAYMENT_METHODS_PATH . '/{paymentMethodId}', (string) $revoke['path']);
check('revoke is a DELETE that answers 204 with no fields',
    $revoke['method'] . ' ' . $revoke['httpStatus'] . ' ' . count($revoke['fields']), 'DELETE 204 0');

$revokeClient = new CannedClient([]);
$revokeClient->revokePaymentMethod($paymentMethodId);
check('revokePaymentMethod is a DELETE on the fixture path', listOf($revokeClient->calls),
    'DELETE ' . str_replace('{paymentMethodId}', $paymentMethodId, (string) $revoke['path']));
check('DELETE signs an empty idempotency key', listOf($revokeClient->idempotencyKeys), '');

exit($failures === 0 ? 0 : 1);
