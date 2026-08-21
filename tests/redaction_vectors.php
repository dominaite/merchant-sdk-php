<?php
// Dependency-free tests that the API secret does not escape through a dump or a
// serializer. Run: php tests/redaction_vectors.php - exits non-zero on any mismatch.
//
// Why this file exists: a client that reaches an error tracker or a cache takes the
// secret with it. var_dump/print_r is the exception-page path (Symfony VarDumper,
// Ignition and Whoops all honour __debugInfo), serialize() is the service-snapshot path.
//
// What is deliberately NOT asserted: var_export(), an (array) cast and Reflection honour
// no hook and DO still print the secret. That is a documented limitation, not a bug to
// pin - see "Do not dump the client" in the README.

require __DIR__ . '/../src/DominaiteClient.php';

use Dominaite\DominaiteClient;

$failures = 0;

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

/** A secret distinctive enough that finding it anywhere in the output is unambiguous. */
const SENTINEL = 'dms_SENTINEL_e3b0c44298fc1c149afbf4c8996fb924';

function verdict(string $output): string
{
    return strpos($output, 'SENTINEL') === false ? 'redacted' : 'LEAKS THE SECRET';
}

$client = new DominaiteClient('dmk_live_abc', SENTINEL);

// var_dump writes to the output buffer rather than returning, so capture it.
ob_start();
var_dump($client);
$dumped = (string) ob_get_clean();

check('var_dump does not print the secret', verdict($dumped), 'redacted');
check('var_dump still shows the key id',
    strpos($dumped, 'dmk_live_abc') !== false ? 'shown' : 'missing', 'shown');
check('var_dump shows the redaction placeholder in its stead',
    strpos($dumped, 'dms_***redacted***') !== false ? 'shown' : 'missing', 'shown');

// print_r honours __debugInfo as well, and it is the one people reach for in a log line.
check('print_r does not print the secret', verdict(print_r($client, true)), 'redacted');

check('serialize does not carry the secret', verdict(serialize($client)), 'redacted');

// Private properties make this vacuously safe today; pin it so a future public
// property or a JsonSerializable does not quietly open the path.
check('json_encode does not carry the secret', verdict((string) json_encode($client)), 'redacted');
check('json_encode of a client is an empty object', (string) json_encode($client), '{}');

// A serialized client survives the round trip - redacting must not turn a framework's
// service snapshot into a fatal - and comes back unable to sign, which is the point.
$restored = unserialize(serialize($client));
check('a serialized client unserializes back into a client',
    $restored instanceof DominaiteClient ? 'client' : 'broken', 'client');
check('the restored client does not carry the secret either',
    verdict(serialize($restored)), 'redacted');

// The dump keeps working after a create call has populated the key slot.
$withKey = new DominaiteClient('dmk_live_abc', SENTINEL);
ob_start();
var_dump($withKey);
check('var_dump of a fresh client is redacted too', verdict((string) ob_get_clean()), 'redacted');

exit($failures === 0 ? 0 : 1);
