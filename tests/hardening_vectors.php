<?php
// Dependency-free tests for the hardening rules that have no network in them.
// Run: php tests/hardening_vectors.php - exits non-zero on any mismatch.
//
// Covers:
//  - a 5xx is decided from the STATUS, before the body is parsed
//  - HTTP 429 surfaces as RateLimitException with the Retry-After seconds
//  - the base URL must be https:// unless it is loopback
//  - length limits count characters, not bytes
//  - the webhook signature is compared with hash_equals (asserted against the source)
//
// tests/transport_vectors.php covers the same rules end to end through real curl.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/AuthenticationException.php';
require __DIR__ . '/../src/Exception/ChargeException.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/StorefrontException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ApiException;
use Dominaite\Exception\AuthenticationException;
use Dominaite\Exception\ChargeException;
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\RateLimitException;
use Dominaite\Exception\StorefrontException;
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

/** Exposes the response handling so a canned status + body can be pushed through it. */
final class ResponseClient extends DominaiteClient
{
    /**
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public function handle(int $status, string $raw, array $headers = []): array
    {
        return $this->handleResponse($status, $raw, $headers);
    }
}

/** Records what reached the transport, so validation can be tested without a network. */
final class RecordingClient extends DominaiteClient
{
    /** @var array<string,mixed>|null */
    public ?array $sentBody = null;

    protected function request(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        $this->sentBody = $body;

        return [
            'success' => true,
            'checkout' => [
                'transactionId' => '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0',
                'orderId' => 'ord_1',
                'cashierKey' => 'ck', 'cashierToken' => 'ct',
                'amount' => 8440, 'currency' => 'EUR',
                'expiresAt' => '2026-01-01T00:00:00Z',
            ],
        ];
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

// --- A5: the status decides before the body is parsed -----------------------------------
// The bug this pins: a 502/503/504 from a load balancer carries an HTML error page or
// nothing at all. Parsing first turns "the platform is down" into a non-retryable
// ApiException, and callers stop instead of retrying with the same idempotency key.
$responseClient = new ResponseClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL);

$html = "<html><head><title>503 Service Unavailable</title></head><body>\n"
    . "<h1>Service Unavailable</h1><p>No server is available to handle this request.</p>\n"
    . "</body></html>\n";

foreach ([500, 502, 503, 504] as $status) {
    check("HTML $status is a retryable transport error",
        thrownBy(static function () use ($responseClient, $status, $html): void {
            $responseClient->handle($status, $html);
        }),
        TransportException::class);
}
check('empty-body 503 is a retryable transport error',
    thrownBy(static function () use ($responseClient): void { $responseClient->handle(503, ''); }),
    TransportException::class);
check('JSON 500 is a retryable transport error too',
    thrownBy(static function () use ($responseClient): void {
        $responseClient->handle(500, '{"success":false,"error":{"code":"INTERNAL"}}');
    }),
    TransportException::class);

// A non-JSON body below 500 is still an ApiException: that one really is "the API said
// something we cannot read", and retrying it changes nothing.
check('HTML 400 stays a non-retryable API error',
    thrownBy(static function () use ($responseClient): void { $responseClient->handle(400, '<html>nope</html>'); }),
    ApiException::class);

// --- A11: rate limiting -----------------------------------------------------------------
$limit = null;
try {
    $responseClient->handle(429, '{"success":false,"error":{"code":"RATE_LIMIT_EXCEEDED","message":"Too many requests"}}', ['retry-after' => '120']);
} catch (RateLimitException $e) {
    $limit = $e;
}
check('429 raises RateLimitException', $limit === null ? 'no exception' : get_class($limit),
    RateLimitException::class);
check('429 carries Retry-After seconds', var_export($limit === null ? null : $limit->getRetryAfterSeconds(), true), '120');
check('429 keeps the HTTP status', (string) ($limit === null ? 0 : $limit->getHttpStatus()), '429');
check('429 carries the code the API sent', var_export($limit === null ? null : $limit->getErrorCode(), true),
    var_export(DominaiteClient::RATE_LIMIT_EXCEEDED, true));

// The charge route has its own limits. Each is still a RateLimitException, told apart by
// its code, and a code the SDK has never seen is a rate limit too.
foreach ([DominaiteClient::CHARGE_VELOCITY_EXCEEDED, DominaiteClient::CHARGE_DECLINE_RATE_EXCEEDED, 'SOME_FUTURE_LIMIT'] as $code) {
    $seen = 'no exception';
    try {
        $responseClient->handle(429, (string) json_encode(['success' => false, 'error' => ['code' => $code]]), ['retry-after' => '30']);
    } catch (RateLimitException $e) {
        $seen = (string) $e->getErrorCode() . ' after ' . var_export($e->getRetryAfterSeconds(), true);
    }
    check("429 $code is a RateLimitException with its code", $seen, "$code after 30");
}
check('the charge limit codes keep their wire spelling',
    DominaiteClient::CHARGE_VELOCITY_EXCEEDED . ',' . DominaiteClient::CHARGE_DECLINE_RATE_EXCEEDED,
    'CHARGE_VELOCITY_EXCEEDED,CHARGE_DECLINE_RATE_EXCEEDED');

// An HTML 429 comes from an edge that never reached us; it must classify the same way.
check('HTML 429 is still a RateLimitException',
    thrownBy(static function () use ($responseClient, $html): void { $responseClient->handle(429, $html); }),
    RateLimitException::class);
$edgeLimit = null;
try {
    $responseClient->handle(429, $html);
} catch (RateLimitException $e) {
    $edgeLimit = $e;
}
check('an HTML 429 has no code', var_export($edgeLimit === null ? 'no exception' : $edgeLimit->getErrorCode(), true), 'NULL');
check('an HTML 429 has no envelope', $edgeLimit === null ? 'no exception' : (string) json_encode($edgeLimit->getResult()), '[]');
check('a JSON 429 keeps the whole envelope', $limit === null ? 'no exception' : implode(',', array_keys($limit->getResult())), 'success,error');

// --- 401 and 403 get different messages -------------------------------------------------
// A 403 comes after the signature verified. The 401 hint about the key id, secret and
// clock would send the caller to fix the wrong thing.
/** @return array{class:string,code:string,status:int,message:string} */
function refusal(ResponseClient $client, int $status, string $raw): array
{
    try {
        $client->handle($status, $raw);
    } catch (AuthenticationException $e) {
        return ['class' => get_class($e), 'code' => $e->getErrorCode(), 'status' => $e->getHttpStatus(), 'message' => $e->getMessage()];
    }

    return ['class' => 'no exception', 'code' => '', 'status' => 0, 'message' => ''];
}

$unauthorized = refusal($responseClient, 401,
    '{"success":false,"error":{"code":"INVALID_SIGNATURE","message":"Request signature does not match"}}');
check('401 is an AuthenticationException', $unauthorized['class'], AuthenticationException::class);
check('401 keeps its code', $unauthorized['code'], 'INVALID_SIGNATURE');
check('401 reports HTTP 401', (string) $unauthorized['status'], '401');
check('401 points at the key id, secret and clock',
    strpos($unauthorized['message'], 'check your key id, secret, and server clock') !== false ? 'yes' : $unauthorized['message'], 'yes');

$scope = refusal($responseClient, 403, (string) json_encode(['success' => false, 'error' => [
    'code' => 'INSUFFICIENT_SCOPE',
    'message' => "This API key does not have the 'webhooks:manage' scope this route requires",
]]));
check('403 INSUFFICIENT_SCOPE is an AuthenticationException', $scope['class'], AuthenticationException::class);
check('403 INSUFFICIENT_SCOPE keeps its code', $scope['code'], DominaiteClient::INSUFFICIENT_SCOPE);
check('403 INSUFFICIENT_SCOPE reports HTTP 403', (string) $scope['status'], '403');
check('403 INSUFFICIENT_SCOPE does not blame the clock',
    strpos($scope['message'], 'server clock') === false ? 'no clock hint' : $scope['message'], 'no clock hint');
check('403 INSUFFICIENT_SCOPE says the key lacks the scope',
    strpos($scope['message'], 'does not hold the scope this route needs') !== false ? 'yes' : $scope['message'], 'yes');
check('403 INSUFFICIENT_SCOPE names the scope the API named',
    strpos($scope['message'], "'webhooks:manage'") !== false ? 'yes' : $scope['message'], 'yes');

$verifyOnly = refusal($responseClient, 403, (string) json_encode(['success' => false, 'error' => [
    'code' => 'VERIFY_ONLY_NOT_ENABLED',
    'message' => 'Verify-only sessions are not enabled for this account.',
]]));
check('403 VERIFY_ONLY_NOT_ENABLED keeps its code', $verifyOnly['code'], DominaiteClient::VERIFY_ONLY_NOT_ENABLED);
check('403 VERIFY_ONLY_NOT_ENABLED reports HTTP 403', (string) $verifyOnly['status'], '403');
check('403 VERIFY_ONLY_NOT_ENABLED says how it gets switched on',
    strpos($verifyOnly['message'], 'We switch it on for your account.') !== false ? 'yes' : $verifyOnly['message'], 'yes');
check('403 VERIFY_ONLY_NOT_ENABLED does not blame the clock',
    strpos($verifyOnly['message'], 'server clock') === false ? 'no clock hint' : $verifyOnly['message'], 'no clock hint');

$otherForbidden = refusal($responseClient, 403, '{"success":false,"error":{"code":"SOME_FUTURE_REFUSAL","message":"Not for you"}}');
check('an unknown 403 keeps its code', $otherForbidden['code'], 'SOME_FUTURE_REFUSAL');
check('an unknown 403 carries the API message without the clock hint',
    strpos($otherForbidden['message'], 'Not for you') !== false && strpos($otherForbidden['message'], 'server clock') === false
        ? 'yes' : $otherForbidden['message'], 'yes');

// Retry-After in the HTTP-date form is not converted: a wrong number is worse than none,
// because the caller would sleep on it.
$retryAfterCases = [
    'absent'      => [[], 'NULL'],
    'http-date'   => [['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'], 'NULL'],
    'garbage'     => [['retry-after' => 'soon'], 'NULL'],
    'negative'    => [['retry-after' => '-5'], 'NULL'],
    'fractional'  => [['retry-after' => '1.5'], 'NULL'],
    'zero'        => [['retry-after' => '0'], '0'],
    'padded'      => [['retry-after' => ' 30 '], '30'],
];
foreach ($retryAfterCases as $label => [$headers, $expected]) {
    $seen = 'no exception';
    try {
        $responseClient->handle(429, '{}', $headers);
    } catch (RateLimitException $e) {
        $seen = var_export($e->getRetryAfterSeconds(), true);
    }
    check("Retry-After parsed: $label", $seen, $expected);
}

// It is not auto-retried, so it must not be a TransportException - those are the ones
// callers loop on. Catching ApiException still works for code written before 429 existed.
check('RateLimitException is not a transport error',
    var_export($limit instanceof TransportException, true), 'false');
check('RateLimitException is catchable as ApiException',
    var_export($limit instanceof ApiException, true), 'true');

// --- A6: https-only base URL -----------------------------------------------------------
// A signed request over http:// hands the key id and signature to anything on the path,
// and the signature stays replayable for the gateway's whole clock window.
$rejected = [
    'plain http'          => 'http://api.dominaite.com/payments',
    'http on a lookalike' => 'http://localhost.evil.example/payments',
    'http with an IP'     => 'http://10.0.0.5/payments',
    'no scheme at all'    => 'api.dominaite.com/payments',
    'ftp'                 => 'ftp://api.dominaite.com/payments',
    'empty'               => '',
];
foreach ($rejected as $label => $url) {
    check("baseUrl rejected: $label",
        thrownBy(static function () use ($url): void { new DominaiteClient(KEY_ID, SECRET, $url); }),
        'InvalidArgumentException');
}

$accepted = [
    'https'            => 'https://api.dominaite.com/payments',
    'https uppercase'  => 'HTTPS://api.dominaite.com/payments',
    'http localhost'   => 'http://localhost:8080',
    'http 127.0.0.1'   => 'http://127.0.0.1:8080/payments',
    'http ::1'         => 'http://[::1]:8080/payments',
];
foreach ($accepted as $label => $url) {
    check("baseUrl accepted: $label",
        thrownBy(static function () use ($url): void { new DominaiteClient(KEY_ID, SECRET, $url); }),
        'no exception');
}

// --- The base URL is required --------------------------------------------------------
// There is no default. A default of production turned an unset environment variable into
// sandbox credentials sent to production, which fail authentication and can get the
// caller's IP blocked.
const BASE_URL_REQUIRED = 'baseUrl is required. Use https://api.staging.dominaite.com/payments'
    . ' for the sandbox or https://api.dominaite.com/payments for production.';

/** @return string The class and message of the thrown exception, or 'no exception'. */
function failureOf(callable $fn): string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }

    return 'no exception';
}

check('baseUrl missing',
    failureOf(static function (): void { new DominaiteClient(KEY_ID, SECRET); }),
    'InvalidArgumentException: ' . BASE_URL_REQUIRED);
$blank = [
    'null'            => null,
    'empty'           => '',
    'spaces'          => '   ',
    'tabs and breaks' => "\t\n",
    'only slashes'    => '///',
];
foreach ($blank as $label => $url) {
    check("baseUrl blank: $label",
        failureOf(static function () use ($url): void { new DominaiteClient(KEY_ID, SECRET, $url); }),
        'InvalidArgumentException: ' . BASE_URL_REQUIRED);
}
// getenv() returns false for an unset variable; PHP passes it on as an empty string.
check('baseUrl from an unset environment variable',
    failureOf(static function (): void {
        new DominaiteClient(KEY_ID, SECRET, getenv('DOMINAITE_SDK_TEST_UNSET_VARIABLE'));
    }),
    'InvalidArgumentException: ' . BASE_URL_REQUIRED);

check('SANDBOX_BASE_URL', DominaiteClient::SANDBOX_BASE_URL, 'https://api.staging.dominaite.com/payments');
check('PRODUCTION_BASE_URL', DominaiteClient::PRODUCTION_BASE_URL, 'https://api.dominaite.com/payments');
foreach (['sandbox' => DominaiteClient::SANDBOX_BASE_URL, 'production' => DominaiteClient::PRODUCTION_BASE_URL] as $label => $url) {
    check("baseUrl $label is the one the client uses",
        (string) (new DominaiteClient(KEY_ID, SECRET, $url))->__debugInfo()['baseUrl'], $url);
}
check('baseUrl is trimmed of whitespace and trailing slashes',
    (string) (new DominaiteClient(KEY_ID, SECRET, "  https://api.staging.dominaite.com/payments//\n"))
        ->__debugInfo()['baseUrl'],
    'https://api.staging.dominaite.com/payments');

// A restored client keeps its base URL, and a snapshot without one does not fall back to
// production either.
$snapshot = serialize(new DominaiteClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL));
check('unserialize keeps the base URL',
    (string) unserialize($snapshot)->__debugInfo()['baseUrl'], DominaiteClient::SANDBOX_BASE_URL);
$withoutBaseUrl = 'O:' . strlen(DominaiteClient::class) . ':"' . DominaiteClient::class . '":1:{s:5:"keyId";s:'
    . strlen(KEY_ID) . ':"' . KEY_ID . '";}';
check('unserialize without a base URL',
    failureOf(static function () use ($withoutBaseUrl): void { unserialize($withoutBaseUrl); }),
    'InvalidArgumentException: ' . BASE_URL_REQUIRED);

// --- Storefront refusals are a typed exception with the code on it ---------------------
// The gateway refuses a mint for a storefront that is not whitelisted (409), inactive
// (409) or not the one the key is bound to (400). Those are onboarding states the
// merchant acts on, so they must be catchable by type and carry the code.
$storefrontCases = [
    [409, DominaiteClient::STOREFRONT_NOT_WHITELISTED, "This storefront's domain is not yet whitelisted with the payment provider"],
    [409, DominaiteClient::STOREFRONT_INACTIVE, 'This storefront is inactive'],
    [400, DominaiteClient::STOREFRONT_MISMATCH, 'This API key is bound to a different storefront than the request names.'],
];
foreach ($storefrontCases as [$status, $code, $message]) {
    $body = json_encode(['success' => false, 'data' => null,
        'error' => ['code' => $code, 'message' => $message, 'statusCode' => $status]]);
    $thrown = null;
    try {
        $responseClient->handle($status, (string) $body);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    check("$status $code is a StorefrontException", $thrown === null ? 'no exception' : get_class($thrown), StorefrontException::class);
    check("$status $code is still an ApiException for existing catch blocks",
        $thrown instanceof ApiException ? 'ApiException' : 'not', 'ApiException');
    check("$status $code carries its code", $thrown instanceof ApiException ? (string) $thrown->getErrorCode() : '', $code);
    check("$status $code carries its status", $thrown instanceof ApiException ? (string) $thrown->getHttpStatus() : '', (string) $status);
    check("$status $code carries the gateway message", $thrown === null ? '' : $thrown->getMessage(), $message);
}
// A 5xx in the gateway's envelope stays the retryable TransportException, and keeps the
// status, the code and the envelope, so a caller can tell MERCHANT_API_UNAVAILABLE from a crash.
$unavailable = null;
try {
    $responseClient->handle(503, '{"success":false,"error":{"code":"MERCHANT_API_UNAVAILABLE","message":"Try again.","statusCode":503}}');
} catch (\Throwable $e) {
    $unavailable = $e;
}
check('a coded 503 is a TransportException', $unavailable === null ? 'no exception' : get_class($unavailable), TransportException::class);
check('the coded 503 keeps status and code',
    $unavailable instanceof TransportException ? $unavailable->getHttpStatus() . ' ' . $unavailable->getErrorCode() : '',
    '503 MERCHANT_API_UNAVAILABLE');
check('the coded 503 keeps the envelope',
    $unavailable instanceof TransportException ? (string) ($unavailable->getResult()['error']['message'] ?? '') : '', 'Try again.');
$bare = null;
try {
    $responseClient->handle(502, '<html>bad gateway</html>');
} catch (\Throwable $e) {
    $bare = $e;
}
check('an HTML 502 is a TransportException with the status and no code',
    $bare instanceof TransportException ? $bare->getHttpStatus() . ' ' . var_export($bare->getErrorCode(), true) : 'not',
    '502 NULL');

// A rejection keeps the whole envelope: error.params is where the API names the record a
// conflict is about, and the SDK does not model every code's params.
$conflict = null;
try {
    $responseClient->handle(409, '{"success":false,"error":{"code":"WEBHOOK_ALREADY_REGISTERED","message":"x",'
        . '"statusCode":409,"params":{"webhookId":"3f2c9a1e-0b7d-4c55-9a8e-1d2f3a4b5c6d"}}}');
} catch (ApiException $e) {
    $conflict = $e;
}
check('a rejection keeps error.params in the envelope',
    (string) ($conflict === null ? 'no exception' : ($conflict->getResult()['error']['params']['webhookId'] ?? 'missing')),
    '3f2c9a1e-0b7d-4c55-9a8e-1d2f3a4b5c6d');
check('a storefront refusal keeps the envelope too',
    implode(',', array_keys($thrown instanceof ApiException ? $thrown->getResult() : [])), 'success,data,error');
$nonJson = null;
try {
    $responseClient->handle(404, '<html>not found</html>');
} catch (ApiException $e) {
    $nonJson = $e;
}
check('a non-JSON rejection has an empty envelope', $nonJson === null ? 'no exception' : (string) json_encode($nonJson->getResult()), '[]');
check('a 409 with another code stays a plain ApiException',
    thrownBy(static function () use ($responseClient): void {
        $responseClient->handle(409, '{"success":false,"error":{"code":"CONFLICT","message":"x"}}');
    }),
    ApiException::class);
check('a 5xx carrying a storefront code stays the retryable transport error',
    thrownBy(static function () use ($responseClient): void {
        $responseClient->handle(503, '{"success":false,"error":{"code":"STOREFRONT_INACTIVE"}}');
    }),
    TransportException::class);

// --- A12: length limits count characters, not bytes -------------------------------------
// 100 Cyrillic characters are 200 bytes in UTF-8. A byte-counting check rejects an
// orderReference the API accepts, and the merchant cannot tell why.
$cyrillic = str_repeat('д', 100);
check('the Cyrillic fixture is 100 characters', (string) mb_strlen($cyrillic, 'UTF-8'), '100');
check('the Cyrillic fixture is 200 bytes', (string) strlen($cyrillic), '200');

$unicodeClient = new RecordingClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL);
check('100-character Cyrillic orderReference is accepted',
    thrownBy(static function () use ($unicodeClient, $cyrillic): void {
        $unicodeClient->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => $cyrillic,
            'idempotencyKey' => 'order-1042',
        ]);
    }),
    'no exception');
check('the Cyrillic orderReference reaches the transport untouched',
    (string) ($unicodeClient->sentBody['orderReference'] ?? ''), $cyrillic);

check('101-character orderReference is rejected',
    thrownBy(static function () use ($cyrillic): void {
        (new RecordingClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL))->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => $cyrillic . 'д',
            'idempotencyKey' => 'order-1042',
        ]);
    }),
    'InvalidArgumentException');
check('empty orderReference is rejected',
    thrownBy(static function (): void {
        (new RecordingClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL))->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => '',
            'idempotencyKey' => 'order-1042',
        ]);
    }),
    'InvalidArgumentException');

// The idempotency key is ASCII-only by the header-safety rule, so this only proves the
// same character-counting path is used for it - a 100-char key must still pass.
check('100-character idempotency key is accepted',
    thrownBy(static function (): void {
        (new RecordingClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL))->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1042',
            'idempotencyKey' => str_repeat('k', 100),
        ]);
    }),
    'no exception');

// --- A8: the webhook MAC is compared in constant time -----------------------------------
// PHP gives a test no way to observe the timing of a comparison, so this asserts against
// the SOURCE instead. Crude, but a silent regression to == or === - which leaks the
// signature a byte at a time to anyone who can time the endpoint - fails the suite.
$source = file_get_contents(__DIR__ . '/../src/DominaiteClient.php');
check('client source is readable', $source === false ? 'unreadable' : 'read', 'read');

$verify = new ReflectionMethod(DominaiteClient::class, 'verifyWebhook');
$lines = explode("\n", (string) $source);
$verifyBody = implode("\n", array_slice(
    $lines,
    $verify->getStartLine() - 1,
    $verify->getEndLine() - $verify->getStartLine() + 1
));

check('verifyWebhook compares the MAC with hash_equals',
    preg_match('/hash_equals\(\s*\$expected\s*,\s*\$received\s*\)/', $verifyBody) === 1 ? 'hash_equals' : 'missing',
    'hash_equals');

// $expected is the computed MAC. It may be assigned and it may be handed to hash_equals;
// any ==, ===, != or !== touching it is the regression this guards against, whether or
// not a hash_equals call still exists somewhere else in the function.
// ($received is deliberately not covered by this rule - it is legitimately compared to
// null while the header is being parsed, before any MAC exists.)
check('verifyWebhook never compares the computed MAC with == or ===',
    preg_match('/\$expected\s*[!=]==?|[!=]==?\s*\$expected/', $verifyBody) === 1
        ? 'raw comparison found' : 'none',
    'none');
check('verifyWebhook never compares the two signatures directly',
    preg_match('/\$received\s*[!=]==?\s*\$expected/', $verifyBody) === 1 ? 'raw comparison found' : 'none',
    'none');

// in_array without strict, strcmp and strcasecmp are the other non-constant-time ways
// this comparison has been written by mistake.
foreach (['strcmp', 'strcasecmp', 'in_array', 'substr_compare'] as $unsafe) {
    check("verifyWebhook does not use $unsafe",
        strpos($verifyBody, $unsafe . '(') === false ? 'absent' : 'present', 'absent');
}

// The source assertion is only worth something if the function it guards still works.
$payload = '{"event":"payment.succeeded"}';
$now = 1755700000;
$good = hash_hmac('sha256', $now . '.' . $payload, 'whsec_test');
check('verifyWebhook still accepts a valid signature',
    var_export(DominaiteClient::verifyWebhook($payload, "t={$now},v1={$good}", 'whsec_test', 300, $now), true),
    'true');
check('verifyWebhook still rejects a forged signature',
    var_export(DominaiteClient::verifyWebhook($payload, 't=' . $now . ',v1=' . str_repeat('a', 64), 'whsec_test', 300, $now), true),
    'false');

// --- The request id: the header first, then metadata.requestId ---------------------------
// Production sends only the body field so far; an edge or a later gateway can send the
// header. Both name the same request, so the header wins when both are present.
$withBoth = '{"success":true,"data":{"ok":true},"metadata":{"requestId":"req_body"}}';
$idClient = new ResponseClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL);
check('no request id before the first answer', var_export($idClient->getLastRequestId(), true), 'NULL');
$idClient->handle(200, $withBoth, ['dominaite-request-id' => 'req_header']);
check('the header wins over the body requestId', (string) $idClient->getLastRequestId(), 'req_header');
$idClient->handle(200, $withBoth);
check('the body requestId is the fallback', (string) $idClient->getLastRequestId(), 'req_body');
$idClient->handle(200, $withBoth, ['dominaite-request-id' => '   ']);
check('a blank header falls back to the body', (string) $idClient->getLastRequestId(), 'req_body');
$idClient->handle(200, '{"success":true,"data":{"ok":true}}');
check('an answer without an id resets the last one', var_export($idClient->getLastRequestId(), true), 'NULL');
$idClient->handle(204, '', ['dominaite-request-id' => 'req_void']);
check('a 204 reads the header', (string) $idClient->getLastRequestId(), 'req_void');
$idClient->handle(200, '{"success":true,"data":{},"metadata":{"requestId":42}}');
check('a non-string body requestId is ignored', var_export($idClient->getLastRequestId(), true), 'NULL');

/** @return string The request id on the exception the call threw, or what went wrong. */
function requestIdOf(callable $fn): string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!method_exists($e, 'getRequestId')) {
            return get_class($e) . ' has no getRequestId()';
        }
        $id = $e->getRequestId();

        return get_class($e) . ' ' . ($id === null ? 'null' : $id);
    }

    return 'no exception';
}

$refusedBody = '{"success":false,"error":{"code":"VALIDATION_ERROR","message":"bad"},"metadata":{"requestId":"req_err"}}';
check('an ApiException carries the body request id',
    requestIdOf(static function () use ($idClient, $refusedBody): void { $idClient->handle(400, $refusedBody); }),
    ApiException::class . ' req_err');
check('an ApiException prefers the header request id',
    requestIdOf(static function () use ($idClient, $refusedBody): void {
        $idClient->handle(400, $refusedBody, ['dominaite-request-id' => 'req_h']);
    }),
    ApiException::class . ' req_h');
check('a 401 carries the request id',
    requestIdOf(static function () use ($idClient): void {
        $idClient->handle(401, '{"success":false,"error":{"code":"INVALID_SIGNATURE","message":"x"},"metadata":{"requestId":"req_401"}}');
    }),
    AuthenticationException::class . ' req_401');
check('a 429 carries the request id',
    requestIdOf(static function () use ($idClient): void {
        $idClient->handle(429, '{"success":false,"error":{"code":"RATE_LIMITED","message":"x"},"metadata":{"requestId":"req_429"}}');
    }),
    RateLimitException::class . ' req_429');
check('a coded 503 carries the request id',
    requestIdOf(static function () use ($idClient): void {
        $idClient->handle(503, '{"success":false,"error":{"code":"MERCHANT_API_UNAVAILABLE","message":"x"},"metadata":{"requestId":"req_503"}}');
    }),
    TransportException::class . ' req_503');
check('an HTML 502 carries the header request id',
    requestIdOf(static function () use ($idClient, $html): void {
        $idClient->handle(502, $html, ['dominaite-request-id' => 'req_edge']);
    }),
    TransportException::class . ' req_edge');
check('an HTML 400 carries the header request id',
    requestIdOf(static function () use ($idClient, $html): void {
        $idClient->handle(400, $html, ['dominaite-request-id' => 'req_html']);
    }),
    ApiException::class . ' req_html');
check('the last request id is the failed answer',
    (string) $idClient->getLastRequestId(), 'req_html');

/** Pushes a canned answer through the real response reading, below every route. */
final class CannedClient extends DominaiteClient
{
    /** @var array{0:int,1:string,2:array<string,string>} */
    public array $answer = [200, '{}', []];

    protected function send(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        return $this->readResponse($this->answer[0], $this->answer[1], $this->answer[2]);
    }
}

$canned = new CannedClient(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL);
$canned->answer = [200, '{"success":false,"transactionId":"0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0",'
    . '"error":{"code":"AMOUNT_TOO_LOW","message":"x"},"metadata":{"requestId":"req_refused"}}', []];
check('a CheckoutRefusedException carries the request id',
    requestIdOf(static function () use ($canned): void {
        $canned->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => 'order-1', 'idempotencyKey' => 'order-1',
        ]);
    }),
    CheckoutRefusedException::class . ' req_refused');
$canned->answer = [409, '{"success":false,"error":{"code":"PAYMENT_METHOD_REVOKED","message":"x"},'
    . '"metadata":{"requestId":"req_charge"}}', []];
check('a ChargeException carries the request id',
    requestIdOf(static function () use ($canned): void {
        $canned->chargePaymentMethod('pm_' . str_repeat('a', 32), [
            'amount' => 100, 'currency' => 'EUR', 'orderReference' => 'order-2', 'idempotencyKey' => 'charge-1',
        ]);
    }),
    ChargeException::class . ' req_charge');
$canned->answer = [200, '{"success":true,"data":{"items":[],"page":1,"pageSize":20,"totalCount":0},'
    . '"metadata":{"requestId":"req_list"}}', ['dominaite-request-id' => 'req_list_h']];
$canned->listTransactions([]);
check('a list result reads its request id on the client', (string) $canned->getLastRequestId(), 'req_list_h');

exit($failures === 0 ? 0 : 1);
