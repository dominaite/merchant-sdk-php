<?php
// Router for `php -S`, used by tests/usage_vectors.php. Records each request (method, path
// and headers) and answers with the status, notice and request id the test asked for in a
// control file, so the SDK's curl path is exercised for real rather than mocked.
//
// Not part of the SDK. Never referenced from src/.

declare(strict_types=1);

$port = (string) ($_SERVER['SERVER_PORT'] ?? '0');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (strpos($name, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}
file_put_contents(
    sys_get_temp_dir() . '/dominaite-usage-last-request-' . $port . '.json',
    json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'path' => $path, 'headers' => $headers])
);

$control = json_decode((string) @file_get_contents(sys_get_temp_dir() . '/dominaite-usage-control-' . $port . '.json'), true);
$control = is_array($control) ? $control : [];

if (isset($control['notice']) && is_string($control['notice'])) {
    header('Dominaite-Sdk-Notice: ' . $control['notice']);
}
if (isset($control['requestId']) && is_string($control['requestId'])) {
    header('Dominaite-Request-Id: ' . $control['requestId']);
}
header('Content-Type: application/json');

$status = (int) ($control['status'] ?? 200);
http_response_code($status);

if ($status >= 400) {
    echo json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'BODY-FRAGMENT-IN-ERROR'],
    ]);
    return true;
}

if ($path === '/merchant-api/ping') {
    echo json_encode([
        'success' => true,
        'data' => [
            'pong' => true,
            'merchantId' => 'm_1',
            'serverTime' => '2026-10-17T10:00:00Z',
            'serverUnixTime' => 1792231200,
            'clockSkewSeconds' => (int) ($control['skew'] ?? 0),
        ],
    ]);
    return true;
}

if ($path === '/merchant-api/checkout/sessions') {
    echo json_encode([
        'success' => true,
        'data' => [
            'success' => true,
            'checkout' => [
                'transactionId' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
                'cashierKey' => 'ck_live',
                'cashierToken' => 'CASHIER-TOKEN-VALUE-0123456789',
                'orderReference' => 'order-1042',
            ],
        ],
    ]);
    return true;
}

echo json_encode(['success' => true, 'data' => ['path' => $path]]);
return true;
