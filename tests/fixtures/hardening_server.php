<?php
// Router for `php -S`, used by tests/transport_vectors.php. Serves the responses a real
// edge sends when things go wrong - an HTML 503, a 429 with Retry-After, an oversized
// body - so the SDK's curl path is exercised for real rather than mocked.
//
// Not part of the SDK. Never referenced from src/.

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// The stored-payment-method routes record the request exactly as it arrived - method,
// path, body bytes and the signed headers - so the transport test can recompute the
// signature over what actually went on the wire. Written to a file because the revoke
// answer is a 204 with no body to echo into.
if (strpos($path, '/merchant-api/payment-methods/') === 0) {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (strpos($name, 'HTTP_') === 0) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        }
    }
    file_put_contents(
        sys_get_temp_dir() . '/dominaite-sdk-last-request-' . (string) ($_SERVER['SERVER_PORT'] ?? '0') . '.json',
        json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'path' => $path,
            'body' => (string) file_get_contents('php://input'),
            'headers' => $headers,
        ])
    );

    // Only the vector's id exists; any other id is the 404 at the bottom, like the API.
    $known = '/merchant-api/payment-methods/pm_0123456789abcdef0123456789abcdef';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $path === $known . '/charges') {
        // The gateway's envelope as it goes over the wire: null fields omitted.
        http_response_code(201);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => [
                'chargeId' => 'ch_1a2b3c4d5e6f4a7b8c9d0e1f2a3b4c5d',
                'status' => 'succeeded',
                'transactionId' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
            ],
            'metadata' => ['requestId' => 'live', 'timestamp' => '2026-09-15T18:02:11Z', 'apiVersion' => '1.0', 'processingTimeMs' => 1],
        ]);
        return true;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE' && $path === $known) {
        http_response_code(204);
        return true;
    }
}

switch ($path) {
    case '/merchant-api/checkout/sessions':
        // The gateway's answer to a mint for a storefront whose domain the provider has
        // not whitelisted yet: a 409 in the standard error envelope.
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => null,
            'error' => [
                'code' => 'STOREFRONT_NOT_WHITELISTED',
                'message' => "This storefront's domain is not yet whitelisted with the payment provider",
                'statusCode' => 409,
            ],
            'metadata' => ['requestId' => 'live', 'timestamp' => '2026-09-24T10:00:00Z', 'apiVersion' => '1.0', 'processingTimeMs' => 1],
        ]);
        return true;

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

    case '/html-429':
        http_response_code(429);
        header('Content-Type: text/html');
        header('Retry-After: 90');
        echo "<html><body>Too Many Requests</body></html>\n";
        return true;

    case '/json-429-dated':
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: Wed, 21 Oct 2026 07:28:00 GMT');
        echo json_encode(['success' => false, 'error' => ['code' => 'RATE_LIMITED']]);
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
