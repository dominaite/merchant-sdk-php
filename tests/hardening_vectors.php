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
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ApiException;
use Dominaite\Exception\RateLimitException;
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
$responseClient = new ResponseClient(KEY_ID, SECRET);

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
    $responseClient->handle(429, '{"error":{"code":"RATE_LIMITED"}}', ['retry-after' => '120']);
} catch (RateLimitException $e) {
    $limit = $e;
}
check('429 raises RateLimitException', $limit === null ? 'no exception' : get_class($limit),
    RateLimitException::class);
check('429 carries Retry-After seconds', var_export($limit === null ? null : $limit->getRetryAfterSeconds(), true), '120');
check('429 keeps the HTTP status', (string) ($limit === null ? 0 : $limit->getHttpStatus()), '429');

// An HTML 429 comes from an edge that never reached us; it must classify the same way.
check('HTML 429 is still a RateLimitException',
    thrownBy(static function () use ($responseClient, $html): void { $responseClient->handle(429, $html); }),
    RateLimitException::class);

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

// The default base URL must itself survive the check - a constructor nobody can call
// with no arguments is the loudest possible way to get this wrong.
check('default baseUrl is accepted',
    thrownBy(static function (): void { new DominaiteClient(KEY_ID, SECRET); }), 'no exception');

// --- A12: length limits count characters, not bytes -------------------------------------
// 100 Cyrillic characters are 200 bytes in UTF-8. A byte-counting check rejects an
// orderReference the API accepts, and the merchant cannot tell why.
$cyrillic = str_repeat('д', 100);
check('the Cyrillic fixture is 100 characters', (string) mb_strlen($cyrillic, 'UTF-8'), '100');
check('the Cyrillic fixture is 200 bytes', (string) strlen($cyrillic), '200');

$unicodeClient = new RecordingClient(KEY_ID, SECRET);
check('100-character Cyrillic orderReference is accepted',
    thrownBy(static function () use ($unicodeClient, $cyrillic): void {
        $unicodeClient->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => $cyrillic,
        ]);
    }),
    'no exception');
check('the Cyrillic orderReference reaches the transport untouched',
    (string) ($unicodeClient->sentBody['orderReference'] ?? ''), $cyrillic);

check('101-character orderReference is rejected',
    thrownBy(static function () use ($cyrillic): void {
        (new RecordingClient(KEY_ID, SECRET))->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => $cyrillic . 'д',
        ]);
    }),
    'InvalidArgumentException');
check('empty orderReference is rejected',
    thrownBy(static function (): void {
        (new RecordingClient(KEY_ID, SECRET))->createCheckoutSession([
            'amount' => 8440, 'currency' => 'EUR', 'orderReference' => '',
        ]);
    }),
    'InvalidArgumentException');

// The idempotency key is ASCII-only by the header-safety rule, so this only proves the
// same character-counting path is used for it - a 100-char key must still pass.
check('100-character idempotency key is accepted',
    thrownBy(static function (): void {
        (new RecordingClient(KEY_ID, SECRET))->createCheckoutSession([
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

exit($failures === 0 ? 0 : 1);
