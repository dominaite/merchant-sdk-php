<?php
// Router for `php -S`, used by tests/transport_vectors.php. Serves the responses a real
// edge sends when things go wrong - an HTML 503, a 429 with Retry-After, an oversized
// body - so the SDK's curl path is exercised for real rather than mocked.
//
// Not part of the SDK. Never referenced from src/.

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

switch ($path) {
    case '/html-503':
        http_response_code(503);
        header('Content-Type: text/html');
        echo "<html><head><title>503 Service Unavailable</title></head>\n"
            . "<body><h1>Service Unavailable</h1></body></html>\n";
        return true;

    case '/empty-502':
        http_response_code(502);
        header('Content-Type: text/html');
        return true;

    case '/oversized':
        // Far more than the SDK's 10MB cap, so a client that reads to the end is
        // unmistakably heavier than one that stops. Flushed in chunks: the client aborts
        // partway through and this loop dies on the broken pipe, which is the point.
        http_response_code(200);
        header('Content-Type: application/json');
        $chunk = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 64; $i++) {
            echo $chunk;
            flush();
        }
        return true;

    case '/ok':
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'pong' => true,
            'merchantId' => 'mer_1',
            'serverTime' => '2026-01-01T00:00:00Z',
            'serverUnixTime' => 1767225600,
            'clockSkewSeconds' => 2,
        ]);
        return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'no such route']]);
return true;
