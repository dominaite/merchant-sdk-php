<?php
// End-to-end tests for what the client tells the API and the caller about itself: the
// User-Agent, the warnings (Dominaite-Sdk-Notice, clock skew) and the debug log, against a
// real HTTP server on loopback (tests/fixtures/usage_server.php).
// Run: php tests/usage_vectors.php - exits non-zero on any mismatch.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/AuthenticationException.php';
require __DIR__ . '/../src/Exception/CheckoutRefusedException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\ApiException;

$failures = 0;

set_exception_handler(static function (\Throwable $e): void {
    echo 'FAIL uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "ok  $name\n";
    } else {
        echo "FAIL $name" . ($detail !== '' ? "\n  $detail" : '') . "\n";
        $failures++;
    }
}

/** Records what the SDK logs, PSR-3 style. */
final class RecordingLogger
{
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<string> */
    public array $debugs = [];

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = $message;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->debugs[] = $message;
    }
}

/** A logger that fails on every call. */
final class ThrowingLogger
{
    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        throw new \RuntimeException('logger down');
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        throw new \RuntimeException('logger down');
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

const KEY_ID = 'dmk_0123456789abcdef';
const SECRET = 'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

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
$controlFile = sys_get_temp_dir() . '/dominaite-usage-control-' . $port . '.json';
$lastRequestFile = sys_get_temp_dir() . '/dominaite-usage-last-request-' . $port . '.json';
$baseUrl = "http://127.0.0.1:$port";

$router = __DIR__ . '/fixtures/usage_server.php';
$command = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($router));
$pipes = [];
$server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (!is_resource($server)) {
    echo "FAIL could not start the fixture server\n";
    exit(1);
}
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
register_shutdown_function(static function () use ($server, $controlFile, $lastRequestFile): void {
    proc_terminate($server);
    proc_close($server);
    @unlink($controlFile);
    @unlink($lastRequestFile);
});
if (!$ready) {
    echo "FAIL fixture server never accepted a connection on 127.0.0.1:$port\n";
    exit(1);
}

/** What the next response carries. @param array<string,mixed> $control */
function respondWith(array $control): void
{
    global $controlFile;
    file_put_contents($controlFile, json_encode($control));
}

/** @return array{method:string,path:string,headers:array<string,string>} */
function lastRequest(): array
{
    global $lastRequestFile;

    return json_decode((string) file_get_contents($lastRequestFile), true);
}

// --- User-Agent ------------------------------------------------------------------------

respondWith([]);
(new DominaiteClient(KEY_ID, SECRET, $baseUrl))->ping();
$userAgent = lastRequest()['headers']['user-agent'] ?? '';
echo "    User-Agent sent: $userAgent\n";
check(
    'User-Agent is dominaite-php/<version> (php <version>)',
    $userAgent === 'dominaite-php/' . DominaiteClient::VERSION . ' (php ' . PHP_VERSION . ')'
        && preg_match('/^dominaite-php\/\d+\.\d+\.\d+ \(php [^ ()]+\)$/', $userAgent) === 1,
    $userAgent
);

(new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['userAgent' => '  shop/1.2 ']))->ping();
$userAgent = lastRequest()['headers']['user-agent'] ?? '';
check(
    'the userAgent option is appended after one space, trimmed',
    $userAgent === 'dominaite-php/' . DominaiteClient::VERSION . ' (php ' . PHP_VERSION . ') shop/1.2',
    $userAgent
);

(new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['userAgent' => '   ']))->ping();
$userAgent = lastRequest()['headers']['user-agent'] ?? '';
check('a blank userAgent adds nothing', $userAgent === 'dominaite-php/' . DominaiteClient::VERSION . ' (php ' . PHP_VERSION . ')', $userAgent);

foreach (["shop\r\nX-Injected: 1", "shop\n", "shop\x00", "shop\x7F", "shop\t1"] as $bad) {
    $refused = false;
    try {
        new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['userAgent' => $bad]);
    } catch (\InvalidArgumentException $e) {
        $refused = strpos($e->getMessage(), 'control characters') !== false;
    }
    check('a userAgent with a control character is refused: ' . json_encode($bad), $refused);
}

$refused = false;
try {
    new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['useragent' => 'shop']);
} catch (\InvalidArgumentException $e) {
    $refused = strpos($e->getMessage(), 'useragent') !== false;
}
check('an unknown option is refused and named', $refused);

// --- Dominaite-Sdk-Notice ------------------------------------------------------------------

$version = DominaiteClient::VERSION;
$logger = new RecordingLogger();
$client = new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => $logger]);

respondWith([]);
$client->ping();
check('no Dominaite-Sdk-Notice header gives no warning', $logger->warnings === [], json_encode($logger->warnings));

respondWith(['notice' => 'deprecated; current="0.7.0"; sunset="2027-01-31"; info="https://example.test/sdk"']);
$client->ping();
$client->ping();
$client->ping();
check(
    'the same notice on three responses warns once, with the sentence',
    $logger->warnings === ["dominaite-php $version is deprecated; upgrade to 0.7.0 (sunset 2027-01-31). https://example.test/sdk"],
    json_encode($logger->warnings)
);

respondWith(['notice' => 'deprecated; current="0.7.0"']);
$client->ping();
check(
    'a second distinct notice warns a second time; sunset and info are optional',
    count($logger->warnings) === 2 && $logger->warnings[1] === "dominaite-php $version is deprecated; upgrade to 0.7.0.",
    json_encode($logger->warnings)
);

respondWith(['notice' => 'Deprecated', 'status' => 400]);
$threw = false;
try {
    $client->ping();
} catch (ApiException $e) {
    $threw = true;
}
check(
    'a notice on an error response warns too, and the error still throws',
    $threw && ($logger->warnings[2] ?? '') === "dominaite-php $version is deprecated.",
    json_encode($logger->warnings)
);

respondWith(['notice' => 'maintenance; info="https://example.test/status"']);
$client->ping();
check(
    'a notice that is not "deprecated" is passed on as the SDK version and the raw value',
    ($logger->warnings[3] ?? '') === "dominaite-php $version: maintenance; info=\"https://example.test/status\"",
    json_encode($logger->warnings)
);

$capped = new RecordingLogger();
$cappedClient = new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => $capped]);
for ($i = 0; $i < 40; $i++) {
    respondWith(['notice' => 'deprecated; current="0.7.' . $i . '"']);
    $cappedClient->ping();
}
check('one client warns about at most 32 distinct notices', count($capped->warnings) === 32, (string) count($capped->warnings));

$other = new RecordingLogger();
respondWith(['notice' => 'deprecated; current="0.7.0"']);
(new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => $other]))->ping();
check('the once-per-value memory belongs to one client', count($other->warnings) === 1, json_encode($other->warnings));

// Without a logger the warning is an E_USER_DEPRECATED: frameworks log it, none throw on it.
$raised = [];
set_error_handler(static function (int $level, string $message) use (&$raised): bool {
    $raised[] = [$level, $message];
    return true;
});
respondWith(['notice' => 'deprecated; current="0.7.0"']);
(new DominaiteClient(KEY_ID, SECRET, $baseUrl))->ping();
restore_error_handler();
check(
    'without a logger the notice goes to trigger_error(E_USER_DEPRECATED)',
    $raised === [[E_USER_DEPRECATED, "dominaite-php $version is deprecated; upgrade to 0.7.0."]],
    json_encode($raised)
);

set_error_handler(static function (): bool {
    throw new \RuntimeException('handler turns every error into an exception');
});
respondWith(['notice' => 'deprecated; current="0.7.0"']);
$survived = false;
try {
    $survived = (new DominaiteClient(KEY_ID, SECRET, $baseUrl))->ping()['pong'] ?? false;
} catch (\Throwable $e) {
    $survived = false;
}
restore_error_handler();
check('an error handler that throws on the notice does not fail the request', $survived === true);

respondWith(['notice' => 'deprecated; current="0.7.0"', 'skew' => 120]);
$survived = false;
try {
    $survived = (new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => new ThrowingLogger(), 'debug' => true]))->ping()['pong'] ?? false;
} catch (\Throwable $e) {
    $survived = false;
}
check('a logger that throws does not fail the request', $survived === true);

$refused = false;
try {
    new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => new \stdClass()]);
} catch (\InvalidArgumentException $e) {
    $refused = true;
}
check('a logger without warning() is refused', $refused);

// --- Clock skew ----------------------------------------------------------------------------

foreach ([61 => true, -61 => true, 60 => false, -60 => false, 0 => false] as $skew => $warns) {
    $skewLogger = new RecordingLogger();
    respondWith(['skew' => $skew]);
    (new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => $skewLogger]))->ping();
    $expected = $warns ? ["dominaite-php: clock skew is $skew s; requests fail at 300 s. Sync this server's clock."] : [];
    check("ping with clockSkewSeconds $skew " . ($warns ? 'warns once' : 'does not warn'), $skewLogger->warnings === $expected, json_encode($skewLogger->warnings));
}

// --- Debug log -----------------------------------------------------------------------------

$quiet = new RecordingLogger();
respondWith(['requestId' => 'req-123']);
$quietClient = new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => $quiet]);
$quietClient->ping();
$quietClient->createCheckoutSession([
    'amount' => 2500,
    'currency' => 'EUR',
    'orderReference' => 'order-1042',
    'idempotencyKey' => 'checkout-order-1042-2500-EUR',
]);
check('debug is off by default: no debug line, and none in the warnings', $quiet->debugs === [] && $quiet->warnings === [], json_encode($quiet));

$debug = new RecordingLogger();
$debugClient = new LiveClient(KEY_ID, SECRET, $baseUrl, ['logger' => $debug, 'debug' => true]);
respondWith(['requestId' => 'req-123']);
$debugClient->createCheckoutSession([
    'amount' => 2500,
    'currency' => 'EUR',
    'orderReference' => 'order-1042',
    'idempotencyKey' => 'checkout-order-1042-2500-EUR',
]);
check(
    'debug on: one line with method, path, status, time, request id and idempotency key',
    count($debug->debugs) === 1
        && preg_match('#^dominaite-php: POST /merchant-api/checkout/sessions 200 \d+ms request_id=req-123 idempotency_key=checkout-order-1042-2500-EUR$#', $debug->debugs[0]) === 1,
    json_encode($debug->debugs)
);

respondWith([]);
$debugClient->get('/merchant-api/transactions?from=2026-10-01T00%3A00%3A00Z');
check(
    'a debug line has no query string, and no request_id or idempotency_key when there is none',
    preg_match('#^dominaite-php: GET /merchant-api/transactions 200 \d+ms$#', $debug->debugs[1] ?? '') === 1,
    json_encode($debug->debugs)
);
check('debug lines never go to the warning sink', $debug->warnings === [], json_encode($debug->warnings));

$errorLog = tempnam(sys_get_temp_dir(), 'dominaite-usage-log');
$previousLog = ini_set('error_log', $errorLog);
respondWith(['requestId' => 'req-9']);
(new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['debug' => true]))->ping();
ini_set('error_log', (string) $previousLog);
$logged = (string) file_get_contents($errorLog);
@unlink($errorLog);
check('debug without a logger writes the line to error_log()', preg_match('#dominaite-php: GET /merchant-api/ping 200 \d+ms request_id=req-9#', $logged) === 1, $logged);

$closedUrl = 'http://127.0.0.1:' . freePort();
$closed = new LiveClient(KEY_ID, SECRET, $closedUrl, ['logger' => $debug, 'debug' => true, 'maxRetries' => 0]);
try {
    $closed->ping();
} catch (\Dominaite\Exception\TransportException $e) {
}
check('a transport failure logs status "error"', preg_match('#^dominaite-php: GET /merchant-api/ping error \d+ms$#', (string) end($debug->debugs)) === 1, (string) end($debug->debugs));

$retried = new RecordingLogger();
$retrying = new LiveClient(KEY_ID, SECRET, $closedUrl, ['logger' => $retried, 'debug' => true, 'maxRetries' => 2]);
try {
    $retrying->ping();
} catch (\Dominaite\Exception\TransportException $e) {
}
check('each retry logs its own line with the attempt number',
    count($retried->debugs) === 3
        && preg_match('#^dominaite-php: GET /merchant-api/ping error \d+ms$#', $retried->debugs[0]) === 1
        && preg_match('#^dominaite-php: GET /merchant-api/ping error \d+ms attempt=2$#', $retried->debugs[1]) === 1
        && preg_match('#^dominaite-php: GET /merchant-api/ping error \d+ms attempt=3$#', $retried->debugs[2]) === 1,
    json_encode($retried->debugs));

// --- Nothing secret in any line ---------------------------------------------------------------

$all = new RecordingLogger();
$allClient = new DominaiteClient(KEY_ID, SECRET, $baseUrl, ['logger' => $all, 'debug' => true]);
$signatures = [];
respondWith(['requestId' => 'req-1', 'notice' => 'deprecated; current="9.9.9"']);
$allClient->createCheckoutSession([
    'amount' => 2500,
    'currency' => 'EUR',
    'orderReference' => 'order-1042',
    'idempotencyKey' => 'checkout-order-1042-2500-EUR',
    'description' => 'REQUEST-BODY-FRAGMENT',
]);
$signatures[] = lastRequest()['headers']['x-signature'] ?? '';
respondWith(['requestId' => 'req-2', 'status' => 400, 'skew' => 500]);
try {
    $allClient->ping();
} catch (ApiException $e) {
}
$signatures[] = lastRequest()['headers']['x-signature'] ?? '';
respondWith(['skew' => 500]);
$allClient->ping();
$signatures[] = lastRequest()['headers']['x-signature'] ?? '';

$lines = array_merge($all->warnings, $all->debugs);
$forbidden = array_merge([SECRET, 'CASHIER-TOKEN-VALUE', 'BODY-FRAGMENT', 'REQUEST-BODY-FRAGMENT', '"amount"'], array_filter($signatures));
$leaks = [];
foreach ($lines as $line) {
    foreach ($forbidden as $needle) {
        if (strpos($line, $needle) !== false) {
            $leaks[] = "$needle in: $line";
        }
    }
}
check(
    'no warning or debug line holds the secret, a signature, the cashierToken or a body',
    count($lines) >= 5 && count(array_filter($signatures)) === 3 && $leaks === [],
    json_encode(['lines' => $lines, 'leaks' => $leaks])
);

// --- Version -----------------------------------------------------------------------------

$changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
// A section for the next version sits on top as "## x.y.z (unreleased)" until its tag; the
// first released heading is the one VERSION must match.
preg_match('/^## (\S+)$/m', $changelog, $heading);
check(
    'DominaiteClient::VERSION is the first released CHANGELOG.md heading',
    ($heading[1] ?? '') === DominaiteClient::VERSION,
    'CHANGELOG ' . ($heading[1] ?? 'none') . ', VERSION ' . DominaiteClient::VERSION
);

if ($failures > 0) {
    echo "$failures failure(s)\n";
    exit(1);
}
echo "all usage vectors pass\n";
