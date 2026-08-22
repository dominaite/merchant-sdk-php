<?php

declare(strict_types=1);

namespace Dominaite;

use Dominaite\Exception\ApiException;
use Dominaite\Exception\AuthenticationException;
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\RateLimitException;
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
// Not final: the contract test substitutes the transport by overriding request().
// Everything a merchant should call is public and documented below; the protected
// members are an internal seam and can change without a major version.
class DominaiteClient
{
    private const DEFAULT_BASE_URL = 'https://api.dominaite.com/payments';
    public const SESSIONS_PATH = '/merchant-api/checkout/sessions';
    public const PING_PATH = '/merchant-api/ping';
    private const USER_AGENT = 'dominaite-php/0.1.2 (php ' . PHP_VERSION . ')';
    private const TIMEOUT_SECONDS = 15;

    /**
     * Hard cap on how much response body we will buffer, in bytes.
     *
     * A real merchant-API response is a few kilobytes. Anything approaching this is a
     * misrouted request, a captive portal, or an edge serving something that is not us,
     * and reading it to the end would grow a PHP-FPM worker's memory without limit.
     * The transfer is aborted and surfaces as TransportException (retryable) rather
     * than as a parse error, because the reason is never the merchant's request body.
     */
    private const MAX_RESPONSE_BYTES = 10 * 1024 * 1024;

    /**
     * Hosts allowed to be reached over plain http://, for local development only.
     * Anything else must be https:// - see the constructor.
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /** Stands in for the secret wherever the client is dumped or serialized. */
    private const REDACTED = 'dms_***redacted***';

    /**
     * Every value getStatus() can return in `status`, in the API's own order.
     *
     * Pinned against the canonical cross-SDK contract fixture
     * (tests/merchant-api-contract.json) so a value cannot ship in one SDK and be
     * mirrored wrong into the others. Do NOT extend this list to "support" a status
     * you saw in the wild - treat unknown values as still-open (see getStatus()) and
     * get the gateway contract changed first.
     */
    public const STATUS_VOCABULARY = [
        'pending',
        'processing',
        'succeeded',
        'failed',
        'refunded',
        'partially_refunded',
        'cancelled',
        'disputed',
        'requires_capture',
        'abandoned',
    ];

    /**
     * Every errorCode a refused createCheckoutSession() can carry, as pinned by the
     * canonical contract fixture. These arrive as HTTP 200 with success=false and reach
     * the caller as a CheckoutRefusedException - branch on getErrorCode().
     */
    public const REFUSAL_ERROR_CODES = [
        'PAYMENT_PROCESSING_UNAVAILABLE',
        'DUPLICATE_REQUEST',
        'ALREADY_PROCESSED',
        'IDEMPOTENCY_KEY_REUSED',
        'PRIOR_ATTEMPT_FAILED',
    ];

    /**
     * Input-validation codes on the create endpoint. Unlike the refusals above these are
     * HTTP 400, not the success=false shape, and surface as ApiException. This SDK
     * generates and length-checks the idempotency key itself, so a correct integration
     * never sees IDEMPOTENCY_KEY_REQUIRED.
     */
    public const VALIDATION_ERROR_CODES = [
        'IDEMPOTENCY_KEY_REQUIRED',
    ];

    private string $keyId;
    private string $secret;
    private string $baseUrl;
    private ?string $lastIdempotencyKey = null;

    /**
     * @param string $keyId   Your API key id (dmk_...), from the Dominaite dashboard/operator.
     * @param string $secret  Your API secret (dms_...). Server-side only.
     * @param string $baseUrl Override for non-production environments. Must be https://,
     *                        except on localhost / 127.0.0.1 / ::1 for local development.
     */
    public function __construct(string $keyId, string $secret, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        if (strpos($keyId, 'dmk_') !== 0) {
            throw new \InvalidArgumentException('keyId must start with dmk_');
        }
        if (strpos($secret, 'dms_') !== 0) {
            throw new \InvalidArgumentException('secret must start with dms_');
        }
        self::assertHeaderSafe('keyId', $keyId);
        self::assertTransportIsEncrypted($baseUrl);
        $this->keyId = $keyId;
        $this->secret = $secret;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Refuses a base URL that would put the signed request on the wire in clear text.
     *
     * Every call carries X-Api-Key-Id and a signature derived from the secret. Over
     * http:// those are readable by anything on the path, and a captured signed request
     * can be replayed for the five minutes the gateway's clock window allows. A typo'd
     * or copy-pasted http:// endpoint is the realistic way that happens, so it is
     * rejected at construction rather than on the first live payment.
     *
     * Loopback is exempt: a local mock or a tunnel endpoint on localhost never leaves
     * the machine, and forcing TLS there only pushes people to disable verification.
     */
    private static function assertTransportIsEncrypted(string $baseUrl): void
    {
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return;
        }

        // parse_url keeps the brackets on an IPv6 literal ("[::1]"); compare without them.
        $host = strtolower(trim((string) parse_url($baseUrl, PHP_URL_HOST), '[]'));
        if ($scheme === 'http' && in_array($host, self::LOOPBACK_HOSTS, true)) {
            return;
        }

        throw new \InvalidArgumentException(
            'baseUrl must use https:// (http:// is allowed only for localhost, 127.0.0.1 and ::1)'
        );
    }

    /**
     * Keeps the API secret out of var_dump() and out of error-tracker output.
     *
     * var_dump() and print_r() honour this hook, and so do the dumpers built on them -
     * Symfony VarDumper, Ignition, Whoops - which is the path that puts a dumped client
     * into a bug report or an exception page. Verified on 7.4 and 8.3.
     *
     * NOT a general guarantee. var_export(), an (array) cast and Reflection honour no hook
     * and still show the secret in full. Do not reach for them on a client.
     * See "Do not dump the client" in the README.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'keyId' => $this->keyId,
            'secret' => self::REDACTED,
            'baseUrl' => $this->baseUrl,
            'lastIdempotencyKey' => $this->lastIdempotencyKey,
        ];
    }

    /**
     * Keeps the API secret out of serialize() output.
     *
     * A client is not session data and there is no reason to serialize one, but frameworks
     * do snapshot their service container, and a serialized blob tends to end up in a cache
     * or a log. Redacting rather than throwing keeps that snapshot from turning into an
     * outage; the restored client cannot sign, which is the intended outcome.
     *
     * @return array<string,mixed>
     */
    public function __serialize(): array
    {
        return [
            'keyId' => $this->keyId,
            'secret' => self::REDACTED,
            'baseUrl' => $this->baseUrl,
            'lastIdempotencyKey' => $this->lastIdempotencyKey,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->keyId = (string) ($data['keyId'] ?? '');
        $this->secret = (string) ($data['secret'] ?? self::REDACTED);
        $this->baseUrl = (string) ($data['baseUrl'] ?? self::DEFAULT_BASE_URL);
        $this->lastIdempotencyKey = isset($data['lastIdempotencyKey']) ? (string) $data['lastIdempotencyKey'] : null;
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
     * @return array{pong:bool,merchantId:string,serverTime:string,serverUnixTime:int,clockSkewSeconds:int}
     *
     * @throws AuthenticationException Wrong/revoked credentials, bad signature, clock off, IP not allowlisted.
     * @throws ApiException            Unexpected API response.
     * @throws RateLimitException     HTTP 429 - you are over the rate limit; back off (getRetryAfterSeconds()).
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
     * creates a second payment; read the generated one back with getLastIdempotencyKey()).
     *
     * Retrying a key the gateway already saw does NOT return the original session: it
     * answers HTTP 200 with success=false and a replay code, which arrives here as a
     * CheckoutRefusedException. The first session's cashierKey/cashierToken are not in
     * that response - reconcile via the refusal's transaction id and getStatus().
     *
     * @param array<string,mixed> $params
     * @return array{transactionId:string,orderId:string,cashierKey:string,cashierToken:string,amount:int,currency:string,expiresAt:string}
     *
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws CheckoutRefusedException The gateway refused the session (inspect getErrorCode()).
     * @throws ApiException            Unexpected API response.
     * @throws RateLimitException     HTTP 429 - you are over the rate limit; back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure (retry WITH the same idempotencyKey - getLastIdempotencyKey()).
     */
    public function createCheckoutSession(array $params): array
    {
        // Cleared first, so a call that never reaches the wire cannot leave the PREVIOUS
        // order's key readable. An error handler reading the accessor after a rejected key
        // would otherwise file an earlier order's key against this one, and a later retry
        // with it collides with that earlier payment instead.
        $this->lastIdempotencyKey = null;

        foreach (['amount', 'currency', 'orderReference'] as $required) {
            if (!isset($params[$required])) {
                throw new \InvalidArgumentException("Missing required parameter: {$required}");
            }
        }
        if (!is_int($params['amount']) || $params['amount'] <= 0) {
            throw new \InvalidArgumentException('amount must be a positive integer in MINOR units (e.g. 2500 for 25.00 EUR)');
        }
        if (!is_string($params['orderReference']) || $params['orderReference'] === ''
            || self::codePoints($params['orderReference']) > 100) {
            throw new \InvalidArgumentException('orderReference must be a non-empty string of at most 100 characters');
        }

        $idempotencyKey = $params['idempotencyKey'] ?? bin2hex(random_bytes(16));
        unset($params['idempotencyKey']);
        if (!is_string($idempotencyKey) || $idempotencyKey === '' || self::codePoints($idempotencyKey) > 100) {
            throw new \InvalidArgumentException('idempotencyKey must be a non-empty string of at most 100 characters');
        }
        self::assertHeaderSafe('idempotencyKey', $idempotencyKey);

        // Recorded BEFORE the call so a caller who catches TransportException can read the
        // key the timed-out attempt used and retry with it. A generated key that only ever
        // existed inside this method would leave a retry no choice but a fresh key, and a
        // fresh key is a second real payment for the same order.
        $this->lastIdempotencyKey = $idempotencyKey;

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
     * The idempotency key the last createCheckoutSession() call sent, generated or yours.
     *
     * Read it in your catch block. On a timeout you cannot know whether the gateway
     * created the session, and retrying with a NEW key charges the order twice - retry
     * with this one, or hand it to getStatus() reconciliation later:
     *
     *   try {
     *       $session = $client->createCheckoutSession($params);
     *   } catch (TransportException $e) {
     *       $key = $client->getLastIdempotencyKey();  // store it, then retry with it
     *   }
     *
     * Null before the first createCheckoutSession() call, and null again after one that was
     * rejected locally without reaching the API - it always means "the key of the most
     * recent attempt that went out", never an older order's.
     *
     * It is a single slot on a client you can reuse, so the next createCheckoutSession()
     * overwrites it. On a long-lived worker that means reading it in the catch block and
     * storing it against your order, not going back for it later.
     *
     * ping() and getStatus() sign an empty key by design and leave this untouched.
     */
    public function getLastIdempotencyKey(): ?string
    {
        return $this->lastIdempotencyKey;
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
     * @return array{transactionId:string,orderId:string,orderReference:?string,status:string,amount:int,currency:string,refundedAmount:?int,createdAt:string,updatedAt:?string,expiresAt:?string}
     *
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            Unknown transaction id (HTTP 404) or unexpected response.
     * @throws RateLimitException     HTTP 429 - you are over the rate limit; back off (getRetryAfterSeconds()).
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
     * The header is exactly "t=1755700000,v1=<64 lowercase hex>". v1 is HMAC-SHA256 over
     * "{t}.{raw body}" keyed with the endpoint's whsec_ secret. A delivery whose
     * timestamp is more than $toleranceSeconds away from now is rejected even when the
     * MAC is valid, so a captured delivery cannot be replayed at leisure.
     *
     * The grammar is strict, matching what the platform emits: comma-separated key=value
     * elements, no whitespace anywhere, one t and one v1 (a repeat of either rejects the
     * whole header), t is ASCII digits, v1 is lowercase hex. Unknown keys are ignored so
     * a later v2 scheme can roll out without breaking v1 readers.
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
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                return false;
            }
            // Unknown keys are ignored on purpose: a future v2= scheme must not break v1
            // readers. t and v1 may each appear once - a repeat is a header we do not
            // understand, so we refuse it rather than pick a candidate out of it.
            if ($pair[0] === 't') {
                if ($timestamp !== null) {
                    return false;
                }
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                if ($received !== null) {
                    return false;
                }
                $received = $pair[1];
            }
        }

        if ($timestamp === null || $received === null) {
            return false;
        }
        // The raw digits go into the MAC input untouched: reformatting the number here
        // would let "01755700000" and "1755700000" sign the same bytes.
        if (preg_match('/^[0-9]+$/', $timestamp) !== 1 || preg_match('/^[0-9a-f]{64}$/', $received) !== 1) {
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
     * Length of a value in Unicode CODE POINTS, which is what the documented limits count.
     *
     * strlen() counts bytes, so a 100-character Cyrillic or Greek orderReference measures
     * 200 and gets rejected locally for a length the API would have accepted. Counting
     * code points makes the local check agree with the documented "<= 100 characters".
     *
     * Caveat: the server counts UTF-16 code units, so an astral character (emoji, rarer
     * CJK) is 1 here and 2 there. That only matters within a couple of characters of the
     * limit, and the server stays the final arbiter - a value this check passes can still
     * come back as a validation error. We do not model UTF-16 here to avoid a second,
     * subtly different notion of length in the SDK.
     */
    private static function codePoints(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * Values that end up in a request header must be printable ASCII.
     *
     * A CR or LF closes the header line, so anything after it becomes headers of the
     * caller's own choosing - an orderReference-derived key like "order-1\r\nX-Forwarded-For: 1.2.3.4"
     * would rewrite what the gateway sees as the client IP. Reject the value here rather
     * than letting curl serialise it.
     */
    private static function assertHeaderSafe(string $name, string $value): void
    {
        // \z, not $: $ also matches just before a trailing newline, which is exactly the
        // byte being defended against.
        if (preg_match('/^[\x20-\x7E]*\z/', $value) !== 1) {
            throw new \InvalidArgumentException(
                "{$name} must contain only printable ASCII characters (0x20-0x7E); it is sent as an HTTP header"
            );
        }
    }

    /**
     * @param array<string,mixed>|null $body Null for GET: an empty body (and empty
     *                                       idempotency key) is what gets signed.
     * @return array<string,mixed>
     *
     * Protected, not private, so the contract test can substitute canned gateway
     * responses and exercise the response handling above without a network call.
     * Not part of the public API - do not call or rely on it from integration code.
     */
    protected function request(string $method, string $path, ?array $body, string $idempotencyKey): array
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

        $raw = '';
        $oversized = false;
        $responseHeaders = [];

        $ch = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            // Some edges block requests without a real User-Agent - always send one.
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            // Buffer the body ourselves so the size cap can abort mid-transfer. Returning
            // a short count from this callback is curl's documented way to stop a read;
            // the transfer then fails with a write error, which $oversized tells apart
            // from a real network failure below.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw, &$oversized): int {
                if ($oversized || strlen($raw) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $oversized = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
            // Retry-After (429) is the only response header the SDK reads, but capturing
            // the set costs nothing and keeps the parsing in one place. Later duplicates
            // win, which is what a redirect chain's final response should give us.
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
                $pair = explode(':', $header, 2);
                if (count($pair) === 2) {
                    $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
                }
                return strlen($header);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $options);

        $completed = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($oversized) {
            throw new TransportException(
                'The API response exceeded ' . self::MAX_RESPONSE_BYTES . ' bytes and was not read; '
                . 'retry with the same idempotency key.'
            );
        }
        if ($completed === false) {
            throw new TransportException("Could not reach the Dominaite API: {$error}");
        }

        return $this->handleResponse($status, $raw, $responseHeaders);
    }

    /**
     * Turns one HTTP response into a payload or the right exception.
     *
     * Split out of request() so the status handling can be exercised on its own, and so
     * the ORDER below is visible: the status decides first, the body is parsed second.
     * A 502/503/504 from a load balancer or a captive portal is HTML or empty, and
     * parsing first would classify the infrastructure being down as "the API sent
     * something we could not read" - an ApiException nobody retries - instead of the
     * retryable TransportException it is. Same for a 429 served by an edge.
     *
     * @param array<string,string> $responseHeaders Lowercased header names to values.
     * @return array<string,mixed>
     */
    protected function handleResponse(int $status, string $raw, array $responseHeaders = []): array
    {
        if ($status >= 500) {
            throw new TransportException("The Dominaite API is unavailable (HTTP {$status}); retry with the same idempotency key.");
        }
        if ($status === 429) {
            throw new RateLimitException(
                'Rate limit exceeded (HTTP 429); back off and retry with the same idempotency key.',
                self::parseRetryAfter($responseHeaders['retry-after'] ?? null)
            );
        }

        $decoded = json_decode($raw, true);
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
        if ($status >= 400) {
            throw new ApiException($status, (string) ($payload['errorMessage'] ?? $envelopeError['message'] ?? 'Request rejected'));
        }

        return $payload;
    }

    /**
     * Reads Retry-After as whole seconds.
     *
     * RFC 9110 allows either a delay in seconds or an HTTP-date. Only the seconds form
     * is modelled: converting a date needs the server's clock to agree with ours, and a
     * wrong number here is worse than no number, because a caller would sleep on it.
     * The date form (and an absent or unparseable header) comes back as null - "the
     * server did not tell us", which the caller answers with its own backoff.
     */
    private static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || preg_match('/^[0-9]+$/', trim($value)) !== 1) {
            return null;
        }

        return (int) trim($value);
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
