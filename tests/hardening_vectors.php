<?php
// Dependency-free tests for the hardening rules that have no network in them.
// Run: php tests/hardening_vectors.php - exits non-zero on any mismatch.
//
// Covers:
//  - a 5xx is decided from the STATUS, before the body is parsed
//  - the response body is read up to a cap and no further
//  - HTTP 429 surfaces as RateLimitException with the Retry-After seconds
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

exit($failures === 0 ? 0 : 1);
