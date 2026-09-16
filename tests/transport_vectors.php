<?php
// End-to-end tests for the transport rules, against a real HTTP server on loopback.
// Run: php tests/transport_vectors.php - exits non-zero on any mismatch.
//
// tests/hardening_vectors.php checks the same classification by calling the response
// handler directly. This file goes through curl, so it also proves the pieces that only
// exist on the wire: that the size cap actually aborts a transfer, and that Retry-After
// is read off a real response header rather than a hand-built array.
//
// The server is PHP's built-in one (tests/fixtures/hardening_server.php) bound to
// 127.0.0.1, which is also why the loopback exemption in the https-only rule matters:
// without it there would be no way to test the transport without a certificate.

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

/** Lets a test drive an arbitrary path through the real transport. */
final class LiveClient extends DominaiteClient
{
    /** @return array<string,mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path, null, '');
    }
}

/** A free port on loopback, claimed and released so the server can bind it. */
function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        echo "FAIL cannot open a loopback socket: $errstr\n";
        exit(1);
    }
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

$port = freePort();
$router = __DIR__ . '/fixtures/hardening_server.php';
$command = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($router));

$pipes = [];
$server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (!is_resource($server)) {
    echo "FAIL could not start the fixture server\n";
    exit(1);
}

// Give the server a moment to bind. Polling the port beats a fixed sleep: a slow CI box
// gets the time it needs, a fast one does not pay for it.
$ready = false;
for ($attempt = 0; $attempt < 100; $attempt++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }
    usleep(50000);
}

register_shutdown_function(static function () use ($server): void {
    proc_terminate($server);
    proc_close($server);
});

if (!$ready) {
    echo "FAIL fixture server never accepted a connection on 127.0.0.1:$port\n";
    exit(1);
}

$client = new LiveClient(
    'dmk_0123456789abcdef',
    'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
    "http://127.0.0.1:$port"
);

/** @return string The class of the thrown exception, or 'no exception'. */
function thrownBy(callable $fn): string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return get_class($e);
    }

    return 'no exception';
}

// A sanity call first: if this fails, every result below is about the harness, not the SDK.
$pong = $client->get('/ok');
check('the fixture server answers a normal request', var_export($pong['pong'] ?? null, true), 'true');

// --- A5 -------------------------------------------------------------------------------
check('an HTML 503 is a retryable transport error',
    thrownBy(static function () use ($client): void { $client->get('/html-503'); }),
    TransportException::class);
check('an empty 502 is a retryable transport error',
    thrownBy(static function () use ($client): void { $client->get('/empty-502'); }),
    TransportException::class);

// --- A11 ------------------------------------------------------------------------------
$limited = null;
try {
    $client->get('/html-429');
} catch (RateLimitException $e) {
    $limited = $e;
}
check('an HTML 429 raises RateLimitException', $limited === null ? 'no exception' : get_class($limited),
    RateLimitException::class);
check('Retry-After is read off the real response header',
    var_export($limited === null ? null : $limited->getRetryAfterSeconds(), true), '90');

$dated = null;
try {
    $client->get('/json-429-dated');
} catch (RateLimitException $e) {
    $dated = $e;
}
check('a dated Retry-After leaves the caller to its own backoff',
    var_export($dated === null ? 'no exception' : $dated->getRetryAfterSeconds(), true), 'NULL');

// --- A13 ------------------------------------------------------------------------------
// 64MB of body against a 10MB cap. The margin is what makes the memory assertion mean
// something: a client that reads to the end lands near 64MB, one that stops at the cap
// stays in the teens (the buffer reallocates as it grows, so it is not exactly 10).
$before = memory_get_peak_usage(true);
check('an oversized response is a retryable transport error',
    thrownBy(static function () use ($client): void { $client->get('/oversized'); }),
    TransportException::class);
check('the oversized body was not read past the cap',
    memory_get_peak_usage(true) - $before <= 24 * 1024 * 1024
        ? 'bounded' : 'grew to ' . (memory_get_peak_usage(true) - $before) . ' bytes',
    'bounded');

// The connection is not left in a broken state: the next call still works.
$after = $client->get('/ok');
check('the client still works after an aborted transfer', var_export($after['pong'] ?? null, true), 'true');

// --- stored payment methods on the wire ------------------------------------------------
// The fixture server records each request; the signature is recomputed over the bytes
// it saw. Same secret and inputs as the charge and revoke vectors in tests/vectors.php,
// so with the vector timestamp the header would be the vector signature itself.
const SECRET = 'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const PAYMENT_METHOD_ID = 'pm_0123456789abcdef0123456789abcdef';
$recordFile = sys_get_temp_dir() . '/dominaite-sdk-last-request-' . $port . '.json';

/** @return array{method:string,path:string,body:string,headers:array<string,string>} */
function lastRequest(string $file): array
{
    $raw = file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        echo "FAIL the fixture server did not record the request\n";
        exit(1);
    }

    return $decoded;
}

$charge = $client->chargePaymentMethod(PAYMENT_METHOD_ID, [
    'amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-1043',
    'idempotencyKey' => '00000000-0000-4000-8000-000000000003',
]);
check('a live charge answers with the unwrapped 201 body', (string) ($charge['chargeId'] ?? ''), 'ch_1a2b3c4d5e6f4a7b8c9d0e1f2a3b4c5d');
check('a live charge reads the omitted declineClass as null, present',
    array_key_exists('declineClass', $charge) ? var_export($charge['declineClass'], true) : 'absent', 'NULL');
$seen = lastRequest($recordFile);
check('the charge went out as POST on the canonical path', $seen['method'] . ' ' . $seen['path'],
    'POST /merchant-api/payment-methods/' . PAYMENT_METHOD_ID . '/charges');
check('the charge body on the wire is the vector body', $seen['body'],
    '{"amount":2500,"currency":"EUR","orderReference":"order-1043"}');
check('the charge carries the Idempotency-Key header', $seen['headers']['idempotency-key'] ?? '', '00000000-0000-4000-8000-000000000003');
check('the charge signature covers method, path, key and body bytes', $seen['headers']['x-signature'] ?? '',
    DominaiteClient::signRequest(SECRET, $seen['headers']['x-timestamp'] ?? '', 'POST', $seen['path'],
        '00000000-0000-4000-8000-000000000003', $seen['body']));
check('the charge recipe reproduces the published vector',
    DominaiteClient::signRequest(SECRET, '1755302400', 'POST', $seen['path'], '00000000-0000-4000-8000-000000000003', $seen['body']),
    '9ce9f54efa2533a46aa4493b97b56aeb657f41d6a18f1c008c7fd412029aebf9');

unlink($recordFile);
$revoked = 'threw';
try {
    $client->revokePaymentMethod(PAYMENT_METHOD_ID);
    $revoked = 'returned';
} catch (\Throwable $e) {
    $revoked = get_class($e);
}
check('a live revoke resolves on the 204', $revoked, 'returned');
$seen = lastRequest($recordFile);
check('the revoke went out as DELETE on the canonical path', $seen['method'] . ' ' . $seen['path'],
    'DELETE /merchant-api/payment-methods/' . PAYMENT_METHOD_ID);
check('the revoke sent no body', $seen['body'], '');
check('the revoke sent no Idempotency-Key header', array_key_exists('idempotency-key', $seen['headers']) ? 'sent' : 'absent', 'absent');
check('the revoke signature covers an empty key and an empty body', $seen['headers']['x-signature'] ?? '',
    DominaiteClient::signRequest(SECRET, $seen['headers']['x-timestamp'] ?? '', 'DELETE', $seen['path'], '', ''));
check('the revoke recipe reproduces the published vector',
    DominaiteClient::signRequest(SECRET, '1755302400', 'DELETE', $seen['path'], '', ''),
    '9330100343c4b820504890a09829a193d5815ca39e92160fdfc13d320a802a02');
unlink($recordFile);

// An id the server does not know is a 404 through the real transport, not a transport error.
$missing = null;
try {
    $client->revokePaymentMethod('pm_unknown');
} catch (ApiException $e) {
    $missing = $e;
}
check('an unknown payment method is an ApiException', $missing === null ? 'no exception' : get_class($missing), ApiException::class);
check('the unknown payment method keeps its 404', (string) ($missing === null ? 0 : $missing->getHttpStatus()), '404');

exit($failures === 0 ? 0 : 1);
