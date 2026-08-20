<?php

declare(strict_types=1);

namespace Dominaite;

use Dominaite\Exception\ApiException;
use Dominaite\Exception\AuthenticationException;
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\TransportException;

/**
 * Server-side client for the Dominaite merchant API.
 *
 * Keep your API secret on the server. Never ship it to a browser, never commit it,
 * never log it. Card details never touch your backend or this SDK - the payer enters
 * them inside the hosted checkout widget.
 *
 * Usage:
 *
 *   $client = new DominaiteClient('dmk_...', 'dms_...');
 *   $session = $client->createCheckoutSession([
 *       'amount'         => 2500,          // minor units: 25.00 EUR
 *       'currency'       => 'EUR',
 *       'orderReference' => 'order-1042',  // your own order id
 *       'customer'       => ['firstName' => 'Ana', 'lastName' => 'K', 'email' => 'ana@example.com'],
 *   ]);
 *   // Hand $session['cashierKey'] + $session['cashierToken'] to the embed snippet.
 */
final class DominaiteClient
{
    private const DEFAULT_BASE_URL = 'https://api.dominaite.com/payments';
    private const SESSIONS_PATH = '/merchant-api/bridgerpay/checkout/sessions';
    public const PING_PATH = '/merchant-api/ping';
    private const USER_AGENT = 'dominaite-php/0.1.2 (php ' . PHP_VERSION . ')';
    private const TIMEOUT_SECONDS = 15;

    private string $keyId;
    private string $secret;
    private string $baseUrl;

    /**
     * @param string $keyId   Your API key id (dmk_...), from the Dominaite dashboard/operator.
     * @param string $secret  Your API secret (dms_...). Server-side only.
     * @param string $baseUrl Override for non-production environments.
     */
    public function __construct(string $keyId, string $secret, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        if (strpos($keyId, 'dmk_') !== 0) {
            throw new \InvalidArgumentException('keyId must start with dmk_');
        }
        if (strpos($secret, 'dms_') !== 0) {
            throw new \InvalidArgumentException('secret must start with dms_');
        }
        $this->keyId = $keyId;
        $this->secret = $secret;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Checks your credentials, your signing and your clock without creating anything.
     *
     * Make this your first live call: it tells you whether the setup is right before
     * a real payment is on the line. A failure here is the key id, the secret, the
     * signature, the clock or an IP allowlist, and never the payment itself.
     *
     * Watch clockSkewSeconds - the gateway rejects requests once it passes 300.
     *
     * @return array{pong:bool,merchantId:string,serverTime?:string,serverUnixTime?:int,clockSkewSeconds:int}
     *
     * @throws AuthenticationException Wrong/revoked credentials, bad signature, clock off, IP not allowlisted.
     * @throws ApiException            Unexpected API response.
     * @throws TransportException      Network-level failure.
     */
    public function ping(): array
    {
        // GET signs an EMPTY idempotency key and an EMPTY body, and sends no
        // Idempotency-Key header - the same signed shape getStatus() uses.
        return $this->request('GET', self::PING_PATH, null, '');
    }

    /**
     * Creates a hosted checkout session for one payment.
     *
     * Required params: amount (int, MINOR units - cents), currency (ISO 4217),
     * orderReference (your order id, <= 100 chars).
     * Optional: customer{firstName,lastName,email,phone}, country (ISO 3166-1 alpha-2),
     * language (ISO 639-1), theme ('light'|'dark'|'bright'), description,
     * idempotencyKey (auto-generated when omitted - retrying with the same key never
     * creates a second payment).
     *
     * @param array<string,mixed> $params
     * @return array{transactionId:string,orderId:string,cashierKey:string,cashierToken:string,amount:int,currency:string,expiresAt:string}
     *
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws CheckoutRefusedException The gateway refused the session (inspect getErrorCode()).
     * @throws ApiException            Unexpected API response.
     * @throws TransportException      Network-level failure (safe to retry WITH the same idempotencyKey).
     */
    public function createCheckoutSession(array $params): array
    {
        foreach (['amount', 'currency', 'orderReference'] as $required) {
            if (!isset($params[$required])) {
                throw new \InvalidArgumentException("Missing required parameter: {$required}");
            }
        }
        if (!is_int($params['amount']) || $params['amount'] <= 0) {
            throw new \InvalidArgumentException('amount must be a positive integer in MINOR units (e.g. 2500 for 25.00 EUR)');
        }

        $idempotencyKey = $params['idempotencyKey'] ?? bin2hex(random_bytes(16));
        unset($params['idempotencyKey']);
        if (!is_string($idempotencyKey) || $idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new \InvalidArgumentException('idempotencyKey must be a non-empty string of at most 100 characters');
        }

        $response = $this->request('POST', self::SESSIONS_PATH, $params, $idempotencyKey);

        if (($response['success'] ?? false) !== true || !isset($response['checkout'])) {
            // A replay refusal names the transaction the key collided with. Carry it
            // (and the whole payload) so the caller can reconcile with getStatus()
            // instead of minting a second payment for the same order.
            $transactionId = $response['transactionId'] ?? null;

            throw new CheckoutRefusedException(
                (string) ($response['errorCode'] ?? 'UNKNOWN'),
                (string) ($response['errorMessage'] ?? 'The checkout session was refused.'),
                is_string($transactionId) && $transactionId !== '' ? $transactionId : null,
                $response
            );
        }

        return $response['checkout'];
    }

    /**
     * Reads the payment status of one of your checkout sessions.
     *
     * Status values: pending, processing, succeeded, failed, refunded, partially_refunded,
     * cancelled, disputed, requires_capture, abandoned. While a session is still payable the
     * response carries expiresAt; amounts are integers in MINOR units.
     *
     * succeeded is the only value that means the payment is complete. Keep polling on
     * pending, processing and requires_capture - none of them is terminal.
     *
     * requires_capture is NOT "unpaid": the payer has already paid and the funds are held
     * awaiting capture. Never treat it as an abandoned order.
     *
     * Treat any status you do not recognise as still-open too: a value the API adds later
     * should make you keep polling, never silently close an order that is still live.
     *
     * @param string $transactionId The transactionId returned by createCheckoutSession().
     * @return array{transactionId:string,orderId:string,orderReference?:string,status:string,amount:int,currency:string,refundedAmount?:int,createdAt:string,updatedAt?:string,expiresAt?:string}
     *
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            Unknown transaction id (HTTP 404) or unexpected response.
     * @throws TransportException      Network-level failure (safe to retry).
     */
    public function getStatus(string $transactionId): array
    {
        $normalized = strtolower(trim($transactionId));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $normalized) !== 1) {
            throw new \InvalidArgumentException('transactionId must be the UUID returned by createCheckoutSession()');
        }

        return $this->request('GET', self::SESSIONS_PATH . '/' . $normalized, null, '');
    }

    /**
     * Verifies the signature on an incoming webhook delivery.
     *
     * Call this BEFORE you parse the body or trust anything in it, on the RAW request
     * body - read it with file_get_contents('php://input') and do not re-encode it, do
     * not use the already-decoded $_POST. A single re-serialised byte fails the check.
     *
     * The header looks like "t=1755700000,v1=<64 lowercase hex>". v1 is HMAC-SHA256 over
     * "{t}.{raw body}" keyed with the endpoint's whsec_ secret. A delivery whose
     * timestamp is more than $toleranceSeconds away from now is rejected even when the
     * MAC is valid, so a captured delivery cannot be replayed at leisure.
     *
     * Returns false - never throws - for anything an attacker controls: a bad MAC, a
     * stale timestamp, a missing or malformed header. Answer 400 and stop.
     *
     * @param string   $payload          Raw request body, exactly as received.
     * @param string   $signatureHeader  The X-Webhook-Signature header value.
     * @param string   $secret           The endpoint's signing secret (whsec_...).
     * @param int      $toleranceSeconds Max clock difference to accept, in seconds.
     * @param int|null $now              Unix seconds to compare against; defaults to time().
     *
     * @throws \InvalidArgumentException Empty secret or negative tolerance - your own bug, not the sender's.
     */
    public static function verifyWebhook(
        string $payload,
        string $signatureHeader,
        string $secret,
        int $toleranceSeconds = 300,
        ?int $now = null
    ): bool {
        if ($secret === '') {
            throw new \InvalidArgumentException('secret must not be empty - use the endpoint secret shown when the webhook was created');
        }
        if ($toleranceSeconds < 0) {
            throw new \InvalidArgumentException('toleranceSeconds must not be negative');
        }

        $timestamp = null;
        $received = null;
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            // Unknown keys are ignored on purpose: a future v2= scheme must not break v1 readers.
            if ($pair[0] === 't' && $timestamp === null) {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1' && $received === null) {
                $received = $pair[1];
            }
        }

        if ($timestamp === null || $received === null) {
            return false;
        }
        if (preg_match('/^[0-9]{1,10}$/', $timestamp) !== 1 || preg_match('/^[0-9a-f]{64}$/', $received) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        if (!hash_equals($expected, $received)) {
            return false;
        }

        // Age last: a valid MAC on a stale delivery is a replay, not a rejection to log as tampering.
        return abs(($now ?? time()) - (int) $timestamp) <= $toleranceSeconds;
    }

    /**
     * @param array<string,mixed>|null $body Null for GET: an empty body (and empty
     *                                       idempotency key) is what gets signed.
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        if ($body === null) {
            $json = '';
        } else {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new \InvalidArgumentException('Request parameters are not JSON-encodable');
            }
        }

        $timestamp = (string) time();
        $signature = $this->sign($timestamp, $method, $path, $idempotencyKey, $json);

        $headers = [
            'Content-Type: application/json',
            'X-Api-Key-Id: ' . $this->keyId,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
        ];
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $ch = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // Some edges block requests without a real User-Agent - always send one.
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new TransportException("Could not reach the Dominaite API: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new ApiException($status, 'The API returned a non-JSON response');
        }

        // The gateway wraps responses as { success, data, ... }; unwrap when present.
        // Error responses carry the machine-readable code at error.code.
        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        $envelopeError = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];

        if ($status === 401 || $status === 403) {
            throw new AuthenticationException(
                (string) ($payload['errorCode'] ?? $envelopeError['code'] ?? 'UNAUTHORIZED'),
                'Authentication failed - check your key id, secret, and server clock.'
            );
        }
        if ($status >= 500) {
            throw new TransportException("The Dominaite API is unavailable (HTTP {$status}); retry with the same idempotency key.");
        }
        if ($status >= 400) {
            throw new ApiException($status, (string) ($payload['errorMessage'] ?? $envelopeError['message'] ?? 'Request rejected'));
        }

        return $payload;
    }

    /**
     * Request signature: hex HMAC-SHA256 over
     * "{timestamp}\n{METHOD}\n{path}\n{idempotencyKey}\n{sha256hex(body)}".
     * The idempotency key is INSIDE the signature, so a captured request cannot be replayed
     * with a different key to mint extra sessions. The server rejects timestamps more than
     * 5 minutes off - keep your server clock on NTP.
     */
    private function sign(string $timestamp, string $method, string $path, string $idempotencyKey, string $body): string
    {
        return self::signRequest($this->secret, $timestamp, $method, $path, $idempotencyKey, $body);
    }

    /**
     * Builds the X-Signature value for one request: lowercase hex HMAC-SHA256 over
     * "{timestamp}\n{METHOD}\n{path}\n{idempotencyKey}\n{sha256hex(body)}".
     *
     * Public so you can pin the published known-answer vectors in your own tests
     * before ever calling the live API.
     */
    public static function signRequest(string $secret, string $timestamp, string $method, string $path, string $idempotencyKey, string $body): string
    {
        $payload = $timestamp . "\n" . strtoupper($method) . "\n" . $path . "\n" . $idempotencyKey . "\n" . hash('sha256', $body);
        return hash_hmac('sha256', $payload, $secret);
    }
}
