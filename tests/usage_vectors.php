<?php
// End-to-end tests for what the client tells the API and the caller about itself: the
// User-Agent, against a real HTTP server on loopback (tests/fixtures/usage_server.php).
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

// --- Version -----------------------------------------------------------------------------

$changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
preg_match('/^## (\S+)/m', $changelog, $heading);
check(
    'DominaiteClient::VERSION is the first CHANGELOG.md heading',
    ($heading[1] ?? '') === DominaiteClient::VERSION,
    'CHANGELOG ' . ($heading[1] ?? 'none') . ', VERSION ' . DominaiteClient::VERSION
);

if ($failures > 0) {
    echo "$failures failure(s)\n";
    exit(1);
}
echo "all usage vectors pass\n";
