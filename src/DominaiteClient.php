<?php

declare(strict_types=1);

namespace Dominaite;

use Dominaite\Exception\ApiException;
use Dominaite\Exception\AuthenticationException;
use Dominaite\Exception\ChargeException;
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\RateLimitException;
use Dominaite\Exception\RefundException;
use Dominaite\Exception\RevokeException;
use Dominaite\Exception\StorefrontException;
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
 *       'idempotencyKey' => DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 2500, 'EUR'),
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
    /**
     * Canonical path of stored payment methods. POST {id}/charges charges one, DELETE
     * {id} revokes it - both signed on this path with the id verbatim.
     */
    public const PAYMENT_METHODS_PATH = '/merchant-api/payment-methods';
    /**
     * Canonical path of payments. POST {transactionId}/refunds refunds one, GET
     * {transactionId}/refunds/{refundId} reads a refund back - both signed on this path
     * with the lowercase transaction id.
     */
    public const PAYMENTS_PATH = '/merchant-api/payments';
    public const PING_PATH = '/merchant-api/ping';
    private const USER_AGENT = 'dominaite-php/0.3.0 (php ' . PHP_VERSION . ')';
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
     * A payment method id is opaque (pm_...), so this only pins what keeps it a single
     * path segment: no slash, no query, no whitespace, nothing that needs
     * percent-encoding. The id goes into the signed canonical path verbatim, so anything
     * else would sign one path and request another.
     */
    private const PAYMENT_METHOD_ID_PATTERN = '/^[A-Za-z0-9_-]{1,100}$/';

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
     * The statuses after which a payment does not move on its own: stop polling. Only
     * succeeded means money in hand (isPaid()). disputed is left out on purpose: a dispute
     * resolves one way or the other. Unknown values are not terminal, see getStatus().
     */
    public const TERMINAL_STATUSES = [
        'succeeded',
        'failed',
        'cancelled',
        'abandoned',
        'refunded',
        'partially_refunded',
    ];

    /** Card payments are off right now; retry later with the same key. HTTP 200 refusal on a session, 503 on a charge. */
    public const PAYMENT_PROCESSING_UNAVAILABLE = 'PAYMENT_PROCESSING_UNAVAILABLE';

    /** A request with this idempotency key is still in flight or its session is open; retry the SAME key shortly. */
    public const DUPLICATE_REQUEST = 'DUPLICATE_REQUEST';

    /** The payment for this idempotency key has already been taken; reconcile, do not charge again. */
    public const ALREADY_PROCESSED = 'ALREADY_PROCESSED';

    /** This idempotency key was used with a different amount, currency or card-saving choice; a bug on your side. */
    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    /** The attempt under this idempotency key ended without payment; the key is spent, reconcile and use a new one. */
    public const PRIOR_ATTEMPT_FAILED = 'PRIOR_ATTEMPT_FAILED';

    /**
     * HTTP 409: the storefront's domain is not yet whitelisted with the payment provider,
     * and this environment requires it. Not retryable until onboarding finishes the
     * whitelisting; contact Dominaite with the storefront's domain.
     */
    public const STOREFRONT_NOT_WHITELISTED = 'STOREFRONT_NOT_WHITELISTED';

    /** HTTP 409: the storefront (online location) was deactivated or deleted. Not retryable. */
    public const STOREFRONT_INACTIVE = 'STOREFRONT_INACTIVE';

    /**
     * HTTP 400: the API key is bound to one storefront and the request named another. A
     * configuration bug: use the key issued for that storefront. On a replay of a key
     * first used for a different storefront it arrives as an HTTP 200 refusal instead.
     */
    public const STOREFRONT_MISMATCH = 'STOREFRONT_MISMATCH';

    /**
     * Every errorCode a refused createCheckoutSession() can carry, as pinned by the
     * canonical contract fixture. These arrive as HTTP 200 with success=false and reach
     * the caller as a CheckoutRefusedException - branch on getErrorCode().
     */
    public const REFUSAL_ERROR_CODES = [
        self::PAYMENT_PROCESSING_UNAVAILABLE,
        self::DUPLICATE_REQUEST,
        self::ALREADY_PROCESSED,
        self::IDEMPOTENCY_KEY_REUSED,
        self::PRIOR_ATTEMPT_FAILED,
    ];

    /**
     * The storefront refusals at session mint, in the contract's order: 400
     * STOREFRONT_MISMATCH, 409 STOREFRONT_INACTIVE, 409 STOREFRONT_NOT_WHITELISTED. A 4xx
     * carrying one of these raises StorefrontException (an ApiException), so
     * getErrorCode() and getHttpStatus() are there to branch on. None of them is fixed by
     * retrying the same request, and none is in REFUSAL_ERROR_CODES.
     */
    public const STOREFRONT_ERROR_CODES = [
        self::STOREFRONT_MISMATCH,
        self::STOREFRONT_INACTIVE,
        self::STOREFRONT_NOT_WHITELISTED,
    ];

    /**
     * Input-validation codes on the create endpoint. Unlike the refusals above these are
     * HTTP 400, not the success=false shape, and surface as ApiException. This SDK
     * requires and length-checks the idempotency key before sending, so a correct
     * integration never sees IDEMPOTENCY_KEY_REQUIRED.
     */
    public const VALIDATION_ERROR_CODES = [
        'IDEMPOTENCY_KEY_REQUIRED',
    ];

    /**
     * Every value a stored payment method's `status` can carry, in the API's own order.
     * Only active methods can be charged; revoked is what revokePaymentMethod() leaves
     * behind, expired means the card's expiry date has passed, retired means the
     * platform stopped the card on its own (retiredReason says why) and it never becomes
     * active again, so ask the customer to save a card again. Treat an unknown value as
     * not chargeable.
     */
    public const STORED_PAYMENT_METHOD_STATUS_VOCABULARY = [
        'active',
        'revoked',
        'expired',
        'retired',
    ];

    /**
     * Every value a stored payment method's `retiredReason` can carry, in the API's own
     * order. hard_decline: a charge on it was declined as final. chargeback: a charge on
     * it was disputed. source_sale_reversed: the payment that saved it was fully refunded
     * or disputed. retiredReason is null on a card the platform has not retired, and kept
     * if you revoke a retired card. Treat an unknown value as retired for an unknown
     * reason.
     */
    public const STORED_PAYMENT_METHOD_RETIRED_REASON_VOCABULARY = [
        'hard_decline',
        'chargeback',
        'source_sale_reversed',
    ];

    /**
     * Every value chargePaymentMethod() can return in `status`, in the API's own order.
     * succeeded: the money moved. failed: it did not, and on a 402 declineClass says
     * why. pending is not terminal: poll getStatus() with the charge's transactionId.
     * cancelled: an authorization voided before capture, no money moved. Treat an
     * unknown value as still open.
     */
    public const CHARGE_STATUS_VOCABULARY = [
        'succeeded',
        'failed',
        'pending',
        'cancelled',
    ];

    /**
     * Why a charge failed, coarse enough to act on without reading the issuer's code:
     * hard = do not retry this card; soft_funds = insufficient funds, retry later;
     * soft_sca_required = the issuer wants the customer present, send them through a
     * hosted session with saveCard; soft_other = transient, one retry later is
     * reasonable.
     */
    public const DECLINE_CLASS_VOCABULARY = [
        'hard',
        'soft_funds',
        'soft_sca_required',
        'soft_other',
    ];

    /**
     * Every errorCode chargePaymentMethod() raises as a ChargeException, in the API's
     * own order: 409 (PAYMENT_METHOD_NOT_ACTIVE, DUPLICATE_REQUEST), 422
     * (IDEMPOTENCY_KEY_REUSED), 502 (CHARGE_OUTCOME_UNKNOWN, CHARGE_FAILED) and 503
     * (PAYMENT_METHOD_CHARGES_DISABLED, PAYMENT_PROCESSING_UNAVAILABLE). CHARGE_DECLINED
     * (402) is deliberately not one of them: a decline is a charge with status failed.
     */
    public const CHARGE_ERROR_CODES = [
        'PAYMENT_METHOD_NOT_ACTIVE',
        'DUPLICATE_REQUEST',
        'IDEMPOTENCY_KEY_REUSED',
        'CHARGE_OUTCOME_UNKNOWN',
        'CHARGE_FAILED',
        'PAYMENT_METHOD_CHARGES_DISABLED',
        'PAYMENT_PROCESSING_UNAVAILABLE',
    ];

    /**
     * Every errorCode revokePaymentMethod() raises as a RevokeException, in the API's
     * own order: 502 UPSTREAM_CONTRACT_ERROR (not retryable) and 503
     * MERCHANT_API_UNAVAILABLE (retry later). Nothing changed under either.
     */
    public const REVOKE_ERROR_CODES = [
        'UPSTREAM_CONTRACT_ERROR',
        'MERCHANT_API_UNAVAILABLE',
    ];

    /** HTTP 404 on a refund route: no card-not-present payment with this id under your account. */
    public const PAYMENT_NOT_FOUND = 'PAYMENT_NOT_FOUND';

    /**
     * HTTP 404 on getRefund() only: no refund with this id on this payment. Right after the
     * 202 the refund may not be picked up yet, so poll again for up to 60 seconds.
     */
    public const REFUND_NOT_FOUND = 'REFUND_NOT_FOUND';

    /**
     * The payment cannot be refunded: not paid, already fully refunded, or everything left
     * is already being refunded. HTTP 422 on createRefund() (nothing queued, the key is not
     * burnt), or the failureCode of a failed refund.
     */
    public const PAYMENT_NOT_REFUNDABLE = 'PAYMENT_NOT_REFUNDABLE';

    /**
     * The amount is more than what is left to refund, counting refunds in progress. HTTP
     * 422 on createRefund() (nothing queued, the key is not burnt), or the failureCode of a
     * failed refund.
     */
    public const REFUND_AMOUNT_EXCEEDED = 'REFUND_AMOUNT_EXCEEDED';

    /** failureCode of a refund the provider did not complete. Never an HTTP error. */
    public const REFUND_FAILED = 'REFUND_FAILED';

    /**
     * Every value a refund's `status` can carry, in the API's own order. pending: queued.
     * processing: with the payment provider. succeeded and failed are final; failed is
     * final for its idempotency key, so a new attempt needs a new key.
     */
    public const REFUND_STATUS_VOCABULARY = [
        'pending',
        'processing',
        'succeeded',
        'failed',
    ];

    /**
     * Every errorCode the refund routes raise as a RefundException, in the API's own order:
     * 404 PAYMENT_NOT_FOUND, 404 REFUND_NOT_FOUND (getRefund() only, retryable), 422
     * PAYMENT_NOT_REFUNDABLE and REFUND_AMOUNT_EXCEEDED (nothing queued, key not burnt), 422
     * IDEMPOTENCY_KEY_REUSED, 409 DUPLICATE_REQUEST (retryable with the SAME key) and 400
     * IDEMPOTENCY_KEY_REQUIRED. REFUND_FAILED is deliberately not one of them: it is the
     * failureCode of a failed refund.
     */
    public const REFUND_ERROR_CODES = [
        self::PAYMENT_NOT_FOUND,
        self::REFUND_NOT_FOUND,
        self::PAYMENT_NOT_REFUNDABLE,
        self::REFUND_AMOUNT_EXCEEDED,
        self::IDEMPOTENCY_KEY_REUSED,
        self::DUPLICATE_REQUEST,
        'IDEMPOTENCY_KEY_REQUIRED',
    ];

    /**
     * Every value a failed refund's `failureCode` can carry, in the API's own order. Treat
     * an unknown value as REFUND_FAILED.
     */
    public const REFUND_FAILURE_CODES = [
        self::REFUND_AMOUNT_EXCEEDED,
        self::PAYMENT_NOT_REFUNDABLE,
        self::REFUND_FAILED,
    ];

    /**
     * Minor-unit exponents for toMinorUnits(), as the GATEWAY counts them, which is what
     * the amount you send is read against. That is ISO 4217 except HUF: the gateway
     * treats forints as whole units (0 decimals, the filler was withdrawn in 1999), so
     * "2 for HUF" would be a 100x overcharge. An unlisted currency throws instead of
     * guessing 2, because a wrong guess is a 10x or 100x charge.
     */
    private const MINOR_UNIT_EXPONENTS = [
        'EUR' => 2, 'USD' => 2, 'GBP' => 2, 'CAD' => 2, 'AUD' => 2, 'CHF' => 2, 'BGN' => 2,
        'RON' => 2, 'PLN' => 2, 'CZK' => 2, 'SEK' => 2, 'DKK' => 2, 'NOK' => 2,
        'JPY' => 0, 'HUF' => 0,
        'BHD' => 3, 'KWD' => 3,
    ];

    /**
     * Currencies where ISO 4217 and the gateway disagree on the exponent (the gateway has
     * no entry and falls back to 2). Converting them either way risks a silent 10x or 100x
     * error, so toMinorUnits() refuses them outright.
     */
    private const UNSUPPORTED_MINOR_UNIT_CURRENCIES = ['ISK', 'KRW', 'OMR', 'JOD', 'TND'];

    /**
     * The statuses that keep their generic exception on every route: validation (400),
     * authentication (401, 403), an id that is not yours (404) and rate limiting (429).
     * Any other failure that carries an error code on a payment-method route is that
     * route's typed exception; without a code a 5xx stays the retryable transport error.
     */
    private const GENERIC_FAILURE_STATUSES = [400, 401, 403, 404, 429];

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
     * orderReference (your order id, <= 100 chars), idempotencyKey (1-100 visible
     * ASCII chars; build it with orderIdempotencyKey() so the same order at the same
     * amount always sends the same key - retrying with it never creates a second payment).
     * A missing key throws InvalidArgumentException before anything is sent.
     * Optional: customer{firstName,lastName,email,phone}, country (ISO 3166-1 alpha-2),
     * language (ISO 639-1), theme ('light'|'dark'|'bright'), description,
     * saveCard (bool - keep the card on file once this payment is approved, so you can
     * charge it again with chargePaymentMethod(); the stored method shows up as
     * storedPaymentMethod on getStatus(), and the card details never reach you).
     *
     * Re-sending a key the gateway already saw, with the same amount and currency, returns
     * the ORIGINAL session while it is still open and unexpired: same transactionId, same
     * cashierKey/cashierToken. That is what makes a reload or a retried timeout safe. Any
     * other replay answers HTTP 200 with success=false and a replay code, which arrives
     * here as a CheckoutRefusedException naming the transaction to reconcile with
     * getStatus().
     *
     * @param array<string,mixed> $params
     * @return array{transactionId:string,orderId:string,cashierKey:string,cashierToken:string,amount:int,currency:string,expiresAt:string}
     *
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws CheckoutRefusedException The gateway refused the session (inspect getErrorCode()).
     * @throws StorefrontException     The storefront cannot take payments (STOREFRONT_ERROR_CODES, HTTP 409 or 400).
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

        self::validateMoneyParams($params);
        if (array_key_exists('saveCard', $params) && !is_bool($params['saveCard'])) {
            throw new \InvalidArgumentException('saveCard must be a bool');
        }

        $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'] ?? null);
        unset($params['idempotencyKey']);

        // Recorded BEFORE the call so a caller who catches TransportException can read the
        // key the timed-out attempt used and retry with it; a fresh key is a second real
        // payment for the same order.
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
     * The idempotency key the last createCheckoutSession(), chargePaymentMethod() or
     * createRefund() call sent. Always the one you passed: the SDK no longer generates keys.
     *
     * Handy in a generic catch block that did not keep the params around. On a timeout
     * you cannot know whether the gateway created the session, and retrying with a NEW key
     * charges the order twice - retry with this one:
     *
     *   try {
     *       $session = $client->createCheckoutSession($params);
     *   } catch (TransportException $e) {
     *       $key = $client->getLastIdempotencyKey();  // store it, then retry with it
     *   }
     *
     * Null before the first call that carries a key, and null again after one that was
     * rejected locally without reaching the API - it always means "the key of the most
     * recent attempt that went out", never an older order's.
     *
     * It is a single slot on a client you can reuse, so the next createCheckoutSession(),
     * chargePaymentMethod() or createRefund() overwrites it. On a long-lived worker that means reading it
     * in the catch block and storing it against your order, not going back for it later.
     *
     * ping(), getStatus(), getRefund() and revokePaymentMethod() sign an empty key by design
     * and leave this untouched.
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
     * storedPaymentMethod is the card kept on file by a session created with saveCard:
     * {id, brand, last4, expiryMonth, expiryYear, status, retiredReason}, present once the
     * payment is approved (and it stays after a revoke, with status 'revoked'); absent or
     * null until then, for sessions without saveCard, and for declined or abandoned ones.
     * brand, last4 and the expiry are null when the provider did not report them.
     * retiredReason is null unless the platform retired the card (status 'retired'). It never
     * carries the card number or the provider token. Store storedPaymentMethod['id']
     * against your customer - it is what chargePaymentMethod() and
     * revokePaymentMethod() take. It is not the paymentMethod field, which is the
     * gateway's string category of how the payer paid ('card', 'wallet', ...) and
     * passes through untouched.
     *
     * payment.succeeded and payment.requires_capture webhooks carry the same object as
     * data.storedPaymentMethod, null when no card was saved (and on every other payment.*
     * event); an event recorded before the field existed does not carry it at all. It can also be null when a card WAS saved: on a
     * server-to-server sale approved synchronously, on a sale whose outcome the platform
     * confirmed later, and whenever the card was stored after the approval was announced.
     * This status read is the source of truth: on a saveCard session whose webhook has no
     * card, read it here.
     *
     * @param string $transactionId The transactionId returned by createCheckoutSession().
     * @return array{transactionId:string,orderId:string,orderReference:?string,status:string,amount:int,currency:string,refundedAmount:?int,createdAt:string,updatedAt:?string,expiresAt:?string,storedPaymentMethod?:?array{id:string,brand:?string,last4:?string,expiryMonth:?int,expiryYear:?int,status:string,retiredReason:?string}}
     *
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            Unknown transaction id (HTTP 404) or unexpected response.
     * @throws RateLimitException     HTTP 429 - you are over the rate limit; back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure (safe to retry).
     */
    public function getStatus(string $transactionId): array
    {
        $normalized = self::normalizeTransactionId($transactionId);

        $status = $this->request('GET', self::SESSIONS_PATH . '/' . $normalized, null, '');

        // Passed through as sent, except the card on file: the gateway omits its null
        // fields on the wire, and the caller gets one shape for it, not two. When the
        // gateway sent no storedPaymentMethod at all there is no key here either.
        if (is_array($status['storedPaymentMethod'] ?? null)) {
            $status['storedPaymentMethod'] = self::storedPaymentMethod($status['storedPaymentMethod']);
        }

        return $status;
    }

    /**
     * True only for 'succeeded', the one status that means the payment is complete.
     * requires_capture (funds held), processing and anything unknown are not paid.
     *
     *   if (DominaiteClient::isPaid($status['status'])) { fulfil($order); }
     */
    public static function isPaid(string $status): bool
    {
        return $status === 'succeeded';
    }

    /**
     * True when the status will not change on its own, so polling can stop: succeeded,
     * failed, cancelled, abandoned, refunded, partially_refunded (TERMINAL_STATUSES).
     * pending, processing, requires_capture, disputed and any value this SDK does not
     * know are not terminal: keep polling rather than close an order that is still live.
     */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Charges a card kept on file, off-session: no widget, no payer present.
     *
     * $paymentMethodId is storedPaymentMethod['id'] from getStatus() of a session you
     * created with saveCard. The charge is signed like a session and carries an
     * Idempotency-Key, so retrying after a timeout WITH THE SAME KEY never charges the
     * card twice: the gateway replays its first answer, HTTP status included. Derive the
     * key from what you are billing (orderIdempotencyKey('renewal', $periodId, ...)), never
     * a random value per attempt.
     *
     * Required params: amount (int, MINOR units), currency (ISO 4217), orderReference
     * (<= 100 chars), idempotencyKey (a missing key throws InvalidArgumentException before
     * anything is sent). Optional: description.
     *
     * The HTTP status is the contract on this route. 201 (200 on a replay) returns the
     * charge, status 'succeeded', 'pending' or 'cancelled'. 402 returns the charge too:
     * a decline is not an exception, the charge has status 'failed' plus a declineClass
     * telling you whether to give up on the card (hard), wait (soft_funds, soft_other)
     * or bring the customer back for a hosted session (soft_sca_required). 'pending' is
     * not terminal - poll getStatus() with the charge's transactionId. declineClass and
     * declineCode are null unless the charge was declined; the gateway omits them on
     * the wire and the SDK reads absent as null.
     *
     * @param array<string,mixed> $params
     * @return array{chargeId:string,status:string,declineClass:?string,declineCode:?string,transactionId:string}
     *
     * @throws ChargeException         409, 422, 502 or 503 with a code (CHARGE_ERROR_CODES): branch on
     *                                 getErrorCode(). CHARGE_OUTCOME_UNKNOWN carries the charge row
     *                                 (getCharge(), getTransactionId()): poll getStatus() with it, never
     *                                 retry under a new key.
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            An id that is not yours (HTTP 404, getErrorCode() PAYMENT_METHOD_NOT_FOUND),
     *                                 validation (HTTP 400) or an unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()), retry with the same key.
     * @throws TransportException      Network-level failure or a 5xx without a code (retry WITH the same
     *                                 idempotencyKey - getLastIdempotencyKey()).
     */
    public function chargePaymentMethod(string $paymentMethodId, array $params): array
    {
        $this->lastIdempotencyKey = null;

        $id = self::normalizePaymentMethodId($paymentMethodId);
        self::validateMoneyParams($params);
        if (array_key_exists('description', $params) && !is_string($params['description'])) {
            throw new \InvalidArgumentException('description must be a string');
        }
        $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'] ?? null);

        // Built field by field, not passed through: the body is what gets signed, and the
        // contract for this route is exactly these fields in this order.
        $body = [
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'orderReference' => $params['orderReference'],
        ];
        if (array_key_exists('description', $params)) {
            $body['description'] = $params['description'];
        }

        $this->lastIdempotencyKey = $idempotencyKey;

        $reply = $this->send('POST', self::PAYMENT_METHODS_PATH . '/' . $id . '/charges', $body, $idempotencyKey);

        $data = $reply['envelope']['data'] ?? null;
        $charge = is_array($data) && is_string($data['chargeId'] ?? null) ? self::charge($data) : null;
        $errorCode = $reply['error']['code'] ?? null;
        $errorCode = is_string($errorCode) && $errorCode !== '' ? $errorCode : null;

        // 201 (200 on a durable replay): the charge was placed, whatever its status. 402:
        // the provider declined; the envelope says success=false but the charge is right
        // there, status failed with its decline class, so it is a result, not an exception.
        if ($charge !== null && (($reply['envelope']['success'] ?? null) === true || $reply['status'] === 402)) {
            return $charge;
        }

        if ($errorCode !== null && $reply['status'] >= 400 && !in_array($reply['status'], self::GENERIC_FAILURE_STATUSES, true)) {
            throw new ChargeException(
                $reply['status'],
                $errorCode,
                (string) ($reply['error']['message'] ?? 'The charge was refused.'),
                $charge,
                $reply['envelope']
            );
        }
        if ($reply['status'] >= 400) {
            throw self::rejection($reply);
        }
        throw new ApiException($reply['status'], 'The API answered the charge without a charge body', 'UNEXPECTED_RESPONSE');
    }

    /**
     * Revokes a card kept on file. The saved credential is deleted at the payment
     * provider and the method's status becomes 'revoked'; a later chargePaymentMethod()
     * on it is refused with PAYMENT_METHOD_NOT_ACTIVE. Returns nothing on success
     * (HTTP 204), and again for an already revoked method, so retrying a timed-out
     * revoke is safe. Not a payment operation: no idempotency key is signed.
     *
     * @throws RevokeException         The gateway refused and nothing changed: MERCHANT_API_UNAVAILABLE
     *                                 (503, retry later) or UPSTREAM_CONTRACT_ERROR (502, the provider
     *                                 refused for good - contact support with the id).
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            An id that is not yours (HTTP 404) or unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx without a code (safe to retry).
     */
    public function revokePaymentMethod(string $paymentMethodId): void
    {
        $id = self::normalizePaymentMethodId($paymentMethodId);

        // DELETE signs an EMPTY idempotency key and an EMPTY body, like GET, and sends
        // no Idempotency-Key header.
        $reply = $this->send('DELETE', self::PAYMENT_METHODS_PATH . '/' . $id, null, '');
        if ($reply['status'] < 400) {
            return;
        }

        $errorCode = $reply['error']['code'] ?? null;
        if (is_string($errorCode) && $errorCode !== '' && !in_array($reply['status'], self::GENERIC_FAILURE_STATUSES, true)) {
            throw new RevokeException(
                $reply['status'],
                $errorCode,
                (string) ($reply['error']['message'] ?? 'The revoke was refused.'),
                $reply['envelope']
            );
        }
        throw self::rejection($reply);
    }

    /**
     * Refunds a payment, all of it or part of it. The refund runs asynchronously: HTTP 202
     * means queued, not done.
     *
     * $transactionId is the id createCheckoutSession() or chargePaymentMethod() returned.
     * Required param: idempotencyKey. Derive it from YOUR refund (a return or credit-note
     * id), never a random value per attempt: the same key always answers the same refundId
     * and never refunds twice, and a replay answers 202 with the refund as it stands now.
     * A missing key throws InvalidArgumentException before anything is sent. Optional:
     * amount (int, MINOR units of the payment's currency, see toMinorUnits()); omit it (or
     * pass null) to refund everything still refundable, and no amount key is sent. reason
     * (string, at most 500 characters, stored with the refund).
     *
     * Returns the refund: {refundId, transactionId, status, amount, currency, failureCode,
     * failureMessage, completedAt}, the same shape getRefund() reads. status is pending or
     * processing here, as a rule. Read the outcome with getRefund(), or wait for the
     * payment.refunded webhook, which fires when the money has moved. A failed refund
     * sends no webhook: poll getRefund() if you need to know about failures.
     *
     * @param array<string,mixed> $params
     * @return array{refundId:string,transactionId:string,status:string,amount:?int,currency:string,failureCode:?string,failureMessage:?string,completedAt:?string}
     *
     * @throws RefundException         A code in REFUND_ERROR_CODES: branch on getErrorCode(). isRetryable()
     *                                 is true for DUPLICATE_REQUEST (retry the SAME key for up to 120s).
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            Validation (HTTP 400) or an unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()), retry with the same key.
     * @throws TransportException      Network-level failure or a 5xx: nothing was queued on a 500, retry
     *                                 WITH the same idempotencyKey (getLastIdempotencyKey()).
     */
    public function createRefund(string $transactionId, array $params): array
    {
        $this->lastIdempotencyKey = null;

        $id = self::normalizeTransactionId($transactionId);
        if (isset($params['amount']) && (!is_int($params['amount']) || $params['amount'] <= 0)) {
            throw new \InvalidArgumentException('amount must be a positive integer in MINOR units, or omitted to refund everything still refundable');
        }
        if (isset($params['reason']) && !is_string($params['reason'])) {
            throw new \InvalidArgumentException('reason must be a string');
        }
        $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'] ?? null);

        // Built field by field: the body is what gets signed. A full refund sends no
        // amount key at all, which is what the gateway reads as "everything left".
        $body = [];
        if (isset($params['amount'])) {
            $body['amount'] = $params['amount'];
        }
        if (isset($params['reason'])) {
            $body['reason'] = $params['reason'];
        }

        $this->lastIdempotencyKey = $idempotencyKey;

        return self::refundReply(
            $this->send('POST', self::PAYMENTS_PATH . '/' . $id . '/refunds', $body, $idempotencyKey)
        );
    }

    /**
     * Reads one refund back: {refundId, transactionId, status, amount, currency,
     * failureCode, failureMessage, completedAt}.
     *
     * status is one of REFUND_STATUS_VOCABULARY; succeeded and failed are final. amount is
     * the amount requested before success (null for a full refund), the amount actually
     * refunded on succeeded, and always null on failed. failureCode and failureMessage are
     * set on failed only; failureCode is one of REFUND_FAILURE_CODES, and an unknown value
     * means REFUND_FAILED. completedAt is set once the refund is final. The gateway omits
     * null fields on the wire; they read as null here.
     *
     * Right after createRefund() the refund may not be picked up yet: REFUND_NOT_FOUND
     * then comes back as a RefundException with isRetryable() true. Poll again for up to
     * 60 seconds; after that the id is unknown.
     *
     * @param string $transactionId The payment the refund belongs to.
     * @param string $refundId      The refundId createRefund() returned.
     * @return array{refundId:string,transactionId:string,status:string,amount:?int,currency:string,failureCode:?string,failureMessage:?string,completedAt:?string}
     *
     * @throws RefundException         PAYMENT_NOT_FOUND or REFUND_NOT_FOUND (HTTP 404): branch on getErrorCode().
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            Unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function getRefund(string $transactionId, string $refundId): array
    {
        $id = self::normalizeTransactionId($transactionId);
        $refund = self::normalizeRefundId($refundId);

        // GET signs an EMPTY idempotency key and an EMPTY body, like getStatus().
        return self::refundReply(
            $this->send('GET', self::PAYMENTS_PATH . '/' . $id . '/refunds/' . $refund, null, '')
        );
    }

    /**
     * The reading shared by both refund routes: the refund on a 2xx, a RefundException for
     * a code in REFUND_ERROR_CODES, the generic exceptions for everything else.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     * @return array<string,mixed>
     */
    private static function refundReply(array $reply): array
    {
        $data = $reply['envelope']['data'] ?? null;
        if ($reply['status'] < 400 && is_array($data) && is_string($data['refundId'] ?? null)) {
            return self::refund($data);
        }

        $errorCode = $reply['error']['code'] ?? null;
        if ($reply['status'] >= 400 && $reply['status'] < 500
            && is_string($errorCode) && in_array($errorCode, self::REFUND_ERROR_CODES, true)) {
            throw new RefundException(
                $reply['status'],
                (string) ($reply['error']['message'] ?? 'The refund was refused.'),
                $errorCode,
                $reply['envelope']
            );
        }
        if ($reply['status'] >= 400) {
            throw self::rejection($reply);
        }
        throw new ApiException($reply['status'], 'The API answered without a refund body', 'UNEXPECTED_RESPONSE');
    }

    /**
     * The checks shared by every request that moves money: amount, currency, orderReference.
     *
     * @param array<string,mixed> $params
     */
    private static function validateMoneyParams(array $params): void
    {
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
    }

    /**
     * The idempotency key for one order at one price: "{scope}-{orderId}-{amountMinor}-{CURRENCY}".
     *
     * Pass the result as idempotencyKey to createCheckoutSession() (or chargePaymentMethod()).
     * Deriving the key from the order instead of minting a random one per request is what
     * makes a reload, a back button or a retried request safe: the same order at the same
     * amount always sends the same key, so the gateway replays the session it already
     * opened instead of opening a second payment. A changed amount or currency is a
     * different payment and gets a different key, because the gateway refuses a known key
     * with a different body (IDEMPOTENCY_KEY_REUSED).
     *
     *   $key = DominaiteClient::orderIdempotencyKey('shop', 'order-1042', 2500, 'eur');
     *   // "shop-order-1042-2500-EUR"
     *
     * $scope names the flow the key belongs to ('checkout', 'renewal', ...) so two flows
     * for the same order never share a key. Keep it a fixed string per flow.
     *
     * @param string $scope       A fixed label for the flow, e.g. 'checkout'.
     * @param string $orderId     Your order id.
     * @param int    $amountMinor The amount you send, in MINOR units.
     * @param string $currency    ISO 4217 code; uppercased here.
     *
     * @throws \InvalidArgumentException An empty part, a non-positive amount, a currency that is not
     *                                   three letters, or a result the key rules refuse (over 100
     *                                   characters, or anything but visible ASCII 0x21-0x7E).
     */
    public static function orderIdempotencyKey(string $scope, string $orderId, int $amountMinor, string $currency): string
    {
        if ($scope === '' || $orderId === '') {
            throw new \InvalidArgumentException('scope and orderId must not be empty');
        }
        if ($amountMinor <= 0) {
            throw new \InvalidArgumentException('amountMinor must be a positive integer in MINOR units');
        }
        $currency = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}\z/', $currency) !== 1) {
            throw new \InvalidArgumentException('currency must be a three-letter ISO 4217 code');
        }

        return self::normalizeIdempotencyKey($scope . '-' . $orderId . '-' . $amountMinor . '-' . $currency);
    }

    /**
     * Converts a decimal amount string to integer MINOR units, by the currency's exponent
     * as the gateway counts it: toMinorUnits('0.30', 'EUR') is 30, toMinorUnits('1000',
     * 'JPY') is 1000, toMinorUnits('5000', 'HUF') is 5000, toMinorUnits('1.250', 'KWD')
     * is 1250.
     *
     * Takes a string on purpose. A float cannot hold 0.30 exactly, and (int) (0.3 * 100)
     * is 29, so the parsing here is plain digit handling with no float or bcmath step.
     * Accepted: digits, optionally a dot and at most as many fractional digits as the
     * currency has ("25", "25.5", "25.50" for EUR). Refused with InvalidArgumentException:
     * more fractional digits than the currency allows, even zeros ("25.000" EUR, "100.0"
     * JPY), a sign, whitespace, thousands separators, a comma as the decimal mark,
     * exponents, an amount past PHP_INT_MAX, and a currency not in the list below. Zero
     * converts to 0; the API itself still requires a positive amount.
     *
     * Exponent 2: EUR USD GBP CAD AUD CHF BGN RON PLN CZK SEK DKK NOK. Exponent 0: JPY and
     * HUF (whole forints, unlike ISO 4217). Exponent 3: BHD KWD. ISK, KRW, OMR, JOD and TND
     * throw as not supported: ISO and the gateway disagree on them.
     *
     * @param string $amount   Decimal amount in MAJOR units, e.g. "25.00".
     * @param string $currency ISO 4217 code, any case.
     *
     * @throws \InvalidArgumentException Malformed amount, too many decimals, or an unknown currency.
     */
    public static function toMinorUnits(string $amount, string $currency): int
    {
        $exponent = self::minorUnitExponent($currency);
        if (preg_match('/^([0-9]+)(?:\.([0-9]+))?\z/', $amount, $parts) !== 1) {
            throw new \InvalidArgumentException('amount must be a plain decimal string like "25.00" (digits and at most one dot)');
        }
        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > $exponent) {
            throw new \InvalidArgumentException(
                strtoupper($currency) . " has {$exponent} decimal place(s); \"{$amount}\" has " . strlen($fraction)
            );
        }

        $digits = ltrim($parts[1] . str_pad($fraction, $exponent, '0'), '0');
        if ($digits === '') {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new \InvalidArgumentException('amount is too large to represent in minor units');
        }

        return (int) $digits;
    }

    /**
     * The minor-unit exponent toMinorUnits() uses: 2 for EUR, 0 for JPY and HUF, 3 for KWD.
     *
     * @throws \InvalidArgumentException A currency this SDK does not list, or one of the
     *                                   unsupported ISK, KRW, OMR, JOD, TND.
     */
    public static function minorUnitExponent(string $currency): int
    {
        $code = strtoupper($currency);
        if (in_array($code, self::UNSUPPORTED_MINOR_UNIT_CURRENCIES, true)) {
            throw new \InvalidArgumentException(
                "{$code} is not supported: ISO 4217 and the gateway disagree on its decimals, convert it yourself"
            );
        }
        if (!isset(self::MINOR_UNIT_EXPONENTS[$code])) {
            throw new \InvalidArgumentException(
                "Unknown currency \"{$currency}\": convert to minor units yourself, or ask for it to be added"
            );
        }

        return self::MINOR_UNIT_EXPONENTS[$code];
    }

    /**
     * Requires a key and bounds it. There is no generated fallback: a random key per call
     * turns every reload or retry that forgot to reuse it into a second payment.
     *
     * @param mixed $idempotencyKey
     */
    private static function normalizeIdempotencyKey($idempotencyKey): string
    {
        if ($idempotencyKey === null) {
            throw new \InvalidArgumentException(
                'idempotencyKey is required; derive it from your order with DominaiteClient::orderIdempotencyKey()'
            );
        }
        if (!is_string($idempotencyKey) || $idempotencyKey === '' || self::codePoints($idempotencyKey) > 100) {
            throw new \InvalidArgumentException('idempotencyKey must be a non-empty string of at most 100 characters');
        }
        // Visible ASCII only (0x21-0x7E), stricter than the header rule: a space is legal in
        // a header, but proxies and servers trim leading and trailing ones, and the key is
        // inside the signature, so a trimmed key fails as a bad signature nobody can explain.
        // One rule in every Dominaite SDK, so a key built in one language works in all.
        if (preg_match('/^[\x21-\x7E]+\z/', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException(
                'idempotencyKey must contain only visible ASCII characters (0x21-0x7E): no spaces, control characters or non-ASCII'
            );
        }

        return $idempotencyKey;
    }

    /** Refuses anything that would not survive as one path segment of the signed canonical path. */
    private static function normalizePaymentMethodId(string $paymentMethodId): string
    {
        $normalized = trim($paymentMethodId);
        if (preg_match(self::PAYMENT_METHOD_ID_PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException('paymentMethodId must be the storedPaymentMethod id from getStatus()');
        }

        return $normalized;
    }

    /** A transaction id goes into the signed path in lowercase hyphenated form. */
    private static function normalizeTransactionId(string $transactionId): string
    {
        $normalized = strtolower(trim($transactionId));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $normalized) !== 1) {
            throw new \InvalidArgumentException('transactionId must be the UUID returned by createCheckoutSession()');
        }

        return $normalized;
    }

    /** Same guard as a payment method id: the refund id is one segment of the signed path. */
    private static function normalizeRefundId(string $refundId): string
    {
        $normalized = trim($refundId);
        if (preg_match(self::PAYMENT_METHOD_ID_PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException('refundId must be the refundId createRefund() returned');
        }

        return $normalized;
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
     * Sends and applies the generic failure rules: 5xx is transport, 4xx is ApiException.
     *
     * @param array<string,mixed>|null $body Null for GET and DELETE: an empty body (and
     *                                       empty idempotency key) is what gets signed.
     * @return array<string,mixed> The unwrapped payload.
     *
     * Protected, not private, so a test can substitute canned gateway responses and
     * exercise the routes above without a network call. Not part of the public API -
     * do not call or rely on it from integration code.
     */
    protected function request(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        return self::settle($this->send($method, $path, $body, $idempotencyKey));
    }

    /**
     * Signs, sends and parses one request. Transport failures, non-JSON bodies, 401/403
     * and 429 are thrown here; every other status comes back as a reply for the route
     * to read, because the payment-method routes answer 402, 409, 422, 502 and 503
     * with a body that the caller needs.
     *
     * @param array<string,mixed>|null $body Null for GET and DELETE.
     * @return array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>}
     *
     * Protected for the same reason as request(); not part of the public API.
     */
    protected function send(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        if ($body === null) {
            $json = '';
        } elseif ($body === []) {
            // A full refund with no reason: json_encode() would send an empty PHP array
            // as the JSON list [], and the route reads a JSON object.
            $json = '{}';
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

        return $this->readResponse($status, $raw, $responseHeaders);
    }

    /**
     * Turns one HTTP response into a payload or the right exception: readResponse()
     * followed by the generic failure rules, which is what every route except the
     * payment-method ones needs.
     *
     * @param array<string,string> $responseHeaders Lowercased header names to values.
     * @return array<string,mixed>
     */
    protected function handleResponse(int $status, string $raw, array $responseHeaders = []): array
    {
        return self::settle($this->readResponse($status, $raw, $responseHeaders));
    }

    /**
     * Turns one HTTP response into a reply for the route to read, or the exception no
     * route could read past.
     *
     * The ORDER below is deliberate. A 429 is classified on its status alone, because an
     * edge serves it as HTML. A 502/503/504 from a load balancer or a captive portal is
     * HTML or empty too, and it must stay the retryable TransportException it is, not
     * become "the API sent something we could not read" - an ApiException nobody
     * retries; only a 5xx that parses as the gateway's envelope comes back as a reply,
     * because the charge and revoke routes read its error code.
     *
     * @param array<string,string> $responseHeaders Lowercased header names to values.
     * @return array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>}
     */
    protected function readResponse(int $status, string $raw, array $responseHeaders = []): array
    {
        if ($status === 429) {
            throw new RateLimitException(
                'Rate limit exceeded (HTTP 429); back off and retry with the same idempotency key.',
                self::parseRetryAfter($responseHeaders['retry-after'] ?? null)
            );
        }

        // 204 carries nothing to parse; the status is the whole answer.
        if ($status === 204) {
            return ['status' => 204, 'envelope' => [], 'payload' => [], 'error' => []];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if ($status >= 500) {
                throw self::unavailable($status);
            }
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

        return ['status' => $status, 'envelope' => $decoded, 'payload' => $payload, 'error' => $envelopeError];
    }

    /**
     * The generic reading of a reply: the payload on success, the generic exception on
     * any failure.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     * @return array<string,mixed>
     */
    private static function settle(array $reply): array
    {
        if ($reply['status'] >= 400) {
            throw self::rejection($reply);
        }

        return $reply['payload'];
    }

    /**
     * The generic reading of a failed reply: 5xx is the API being unavailable, 4xx a
     * rejection. The machine-readable code rides along when the API sent one: a
     * validation rejection like IDEMPOTENCY_KEY_REQUIRED is only actionable if the
     * caller can branch on it. A storefront code gets its own ApiException subclass,
     * because it is an onboarding state to act on, not a bad request to debug.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     */
    private static function rejection(array $reply): \RuntimeException
    {
        if ($reply['status'] >= 500) {
            return self::unavailable($reply['status']);
        }
        $errorCode = $reply['payload']['errorCode'] ?? $reply['error']['code'] ?? null;
        $errorCode = is_string($errorCode) && $errorCode !== '' ? $errorCode : null;
        $message = (string) ($reply['payload']['errorMessage'] ?? $reply['error']['message'] ?? 'Request rejected');

        if ($errorCode !== null && in_array($errorCode, self::STOREFRONT_ERROR_CODES, true)) {
            return new StorefrontException($reply['status'], $message, $errorCode);
        }

        return new ApiException($reply['status'], $message, $errorCode);
    }

    private static function unavailable(int $status): TransportException
    {
        return new TransportException("The Dominaite API is unavailable (HTTP {$status}); retry with the same idempotency key.");
    }

    /**
     * The charge body as one shape: the gateway omits declineClass and declineCode when
     * they are null (every 201, and a 502 CHARGE_FAILED row), so absent reads as null.
     * Anything else the gateway sends is carried through.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function charge(array $data): array
    {
        $data['chargeId'] = (string) $data['chargeId'];
        $data['status'] = (string) ($data['status'] ?? '');
        $data['declineClass'] = is_string($data['declineClass'] ?? null) ? $data['declineClass'] : null;
        $data['declineCode'] = is_string($data['declineCode'] ?? null) ? $data['declineCode'] : null;
        $data['transactionId'] = (string) ($data['transactionId'] ?? '');

        return $data;
    }

    /**
     * The refund as one shape: the gateway omits amount, failureCode, failureMessage and
     * completedAt when they are null, so absent reads as null.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function refund(array $data): array
    {
        $data['refundId'] = (string) $data['refundId'];
        $data['transactionId'] = (string) ($data['transactionId'] ?? '');
        $data['status'] = (string) ($data['status'] ?? '');
        $data['amount'] = is_int($data['amount'] ?? null) ? $data['amount'] : null;
        $data['currency'] = (string) ($data['currency'] ?? '');
        $data['failureCode'] = is_string($data['failureCode'] ?? null) ? $data['failureCode'] : null;
        $data['failureMessage'] = is_string($data['failureMessage'] ?? null) ? $data['failureMessage'] : null;
        $data['completedAt'] = is_string($data['completedAt'] ?? null) ? $data['completedAt'] : null;

        return $data;
    }

    /**
     * Same rule for the card on file: brand, last4 and the expiry are absent when the
     * provider did not report them, and retiredReason is absent unless the platform
     * retired the card.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function storedPaymentMethod(array $data): array
    {
        $data['id'] = (string) ($data['id'] ?? '');
        $data['brand'] = is_string($data['brand'] ?? null) ? $data['brand'] : null;
        $data['last4'] = is_string($data['last4'] ?? null) ? $data['last4'] : null;
        $data['expiryMonth'] = is_int($data['expiryMonth'] ?? null) ? $data['expiryMonth'] : null;
        $data['expiryYear'] = is_int($data['expiryYear'] ?? null) ? $data['expiryYear'] : null;
        $data['status'] = (string) ($data['status'] ?? '');
        $data['retiredReason'] = is_string($data['retiredReason'] ?? null) ? $data['retiredReason'] : null;

        return $data;
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
