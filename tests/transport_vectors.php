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

exit($failures === 0 ? 0 : 1);
