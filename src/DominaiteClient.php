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
 *   $client = new DominaiteClient('dmk_...', 'dms_...', DominaiteClient::SANDBOX_BASE_URL);
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
    /** The sandbox base URL. Pass it as the third constructor argument. */
    public const SANDBOX_BASE_URL = 'https://api.staging.dominaite.com/payments';
    /** The production base URL. Pass it as the third constructor argument. */
    public const PRODUCTION_BASE_URL = 'https://api.dominaite.com/payments';
    public const SESSIONS_PATH = '/merchant-api/checkout/sessions';
    /**
     * Canonical path of stored payment methods. POST {id}/charges charges one, DELETE
     * {id} revokes it - both signed on this path with the id in lowercase.
     */
    public const PAYMENT_METHODS_PATH = '/merchant-api/payment-methods';
    /**
     * Canonical path of payments. POST {transactionId}/refunds refunds one, GET
     * {transactionId}/refunds lists its refunds, GET {transactionId}/refunds/{refundId}
     * reads a refund back - all signed on this path with the lowercase transaction id.
     */
    public const PAYMENTS_PATH = '/merchant-api/payments';
    public const PING_PATH = '/merchant-api/ping';
    /**
     * Canonical path of subscription plans. POST creates one, GET lists them; GET, PATCH
     * and DELETE {id} read, change and archive one - all signed with the plan_ id verbatim.
     */
    public const PLANS_PATH = '/merchant-api/plans';
    /**
     * Canonical path of subscription agreements. POST creates one; GET and DELETE {id}
     * read and cancel one - signed with the agr_ id verbatim.
     */
    public const AGREEMENTS_PATH = '/merchant-api/agreements';
    /**
     * Canonical path of webhook endpoints. POST registers one, GET lists them, DELETE {id}
     * removes one - signed with the lowercase hyphenated id. Needs the webhooks:manage scope.
     */
    public const WEBHOOKS_PATH = '/merchant-api/webhooks';
    /**
     * Canonical path of the transaction list. GET only. The query string is sent in the URL
     * and is never part of the signature. Needs the transactions:read scope.
     */
    public const TRANSACTIONS_PATH = '/merchant-api/transactions';
    /** The public integration contract. It needs no key; the SDK signs it like ping. */
    public const INTEGRATION_CONTRACT_PATH = '/merchant-api/integration/contract';
    /** This SDK's version. The User-Agent carries it. */
    public const VERSION = '0.6.0';
    private const TIMEOUT_SECONDS = 45;
    private const DEFAULT_MAX_RETRIES = 2;
    private const RETRY_BASE_MS = 500;
    private const RETRY_CAP_MS = 8000;
    /** The keys the constructor's $options accepts. */
    private const OPTION_KEYS = ['userAgent', 'logger', 'debug', 'maxRetries', 'timeout'];
    /** Distinct Dominaite-Sdk-Notice values one client remembers; later new ones are dropped. */
    private const MAX_NOTICES = 32;
    /** The API rejects requests from 300 seconds of skew; the SDK warns well before that. */
    private const CLOCK_SKEW_WARNING_SECONDS = 60;

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
     * percent-encoding. Anything else would sign one path and request another. The plan_,
     * agr_ and re_ ids share the pattern and go into the signed path verbatim.
     */
    private const PAYMENT_METHOD_ID_PATTERN = '/^[A-Za-z0-9_-]{1,100}$/';

    /** What a missing key on a create route that is not a payment should be derived from. */
    private const RECORD_KEY_HINT = 'derive it from your own record of what you create (for example its id in your database), '
        . 'so a retry sends the same key';

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

    /**
     * Every payment method category getStatus() reports in `paymentMethod`, in the gateway's
     * own order. Reporting data, not a money-flow switch: a wallet payment refunds, captures
     * and disputes exactly like a plain card payment.
     */
    public const PAYMENT_METHOD_CATEGORIES = [
        'card',
        'wallet',
        'bank_transfer',
        'sepa',
    ];

    /**
     * The wallets the gateway names in `walletType` today, pinned against the gateway
     * contract (tests/merchant-api-wire-contract.json). The field can carry a lower-cased
     * identifier not in this list yet: treat an unknown value as a valid wallet, not an
     * error, and never branch payment handling on it.
     */
    public const WALLET_TYPES = [
        'apple_pay',
        'google_pay',
        'samsung_pay',
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
     * first used for a different storefront the API answers HTTP 200 instead; both arrive
     * as a StorefrontException, the replay with getHttpStatus() 200.
     */
    public const STOREFRONT_MISMATCH = 'STOREFRONT_MISMATCH';

    /**
     * Every errorCode a refused createCheckoutSession() can carry as a CheckoutRefusedException.
     * These arrive as HTTP 200 with success=false - branch on getErrorCode(). The first five
     * are the replay and availability refusals of every session. The rest refuse a
     * subscription session (one with agreementId): the agreement is unknown, not pending,
     * paid already or has a session open; the amount or the consent does not fit it; or
     * subscriptions are not switched on for your account. STOREFRONT_MISMATCH can arrive as
     * an HTTP 200 refusal too, but as a StorefrontException, so it is not in this list.
     */
    public const REFUSAL_ERROR_CODES = [
        self::PAYMENT_PROCESSING_UNAVAILABLE,
        self::DUPLICATE_REQUEST,
        self::ALREADY_PROCESSED,
        self::IDEMPOTENCY_KEY_REUSED,
        self::PRIOR_ATTEMPT_FAILED,
        'AGREEMENT_NOT_FOUND',
        'AGREEMENT_NOT_PENDING',
        'AGREEMENT_AMOUNT_MISMATCH',
        'AGREEMENT_CONSENT_REQUIRED',
        'AGREEMENT_SESSION_OPEN',
        'AGREEMENT_ALREADY_PAID',
        'AGREEMENT_VARIABLE_AMOUNT_UNSUPPORTED',
        'SUBSCRIPTIONS_NOT_ENABLED',
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
     * HTTP 403: the API key does not hold the scope this route needs. A key holds either
     * payments, or one or both of transactions:read and webhooks:manage, never both kinds.
     * Not retryable: call the route with a key issued with that scope.
     */
    public const INSUFFICIENT_SCOPE = 'INSUFFICIENT_SCOPE';

    /**
     * HTTP 403 on createCheckoutSession() with verifyOnly: verify-only sessions are not
     * switched on for your account. We switch it on for your account. Nothing was
     * created. Arrives as an AuthenticationException.
     */
    public const VERIFY_ONLY_NOT_ENABLED = 'VERIFY_ONLY_NOT_ENABLED';

    /** HTTP 429: a request limit. Arrives as a RateLimitException; read Retry-After. */
    public const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';

    /**
     * HTTP 429 on chargePaymentMethod(): the card has the most charges allowed in 24
     * hours, or the charge would pass your hourly ceiling in its currency. Nothing was
     * charged. Arrives as a RateLimitException; retry with the same key after Retry-After.
     */
    public const CHARGE_VELOCITY_EXCEEDED = 'CHARGE_VELOCITY_EXCEEDED';

    /**
     * HTTP 429 on chargePaymentMethod(): the key collected too many declined charges in a
     * short window, so its charge route is locked. Nothing was charged. Arrives as a
     * RateLimitException; retry after Retry-After.
     */
    public const CHARGE_DECLINE_RATE_EXCEEDED = 'CHARGE_DECLINE_RATE_EXCEEDED';

    /**
     * The authorization codes the gateway publishes, in the contract's order. Each is an
     * HTTP 403 and arrives as an AuthenticationException.
     */
    public const AUTHORIZATION_ERROR_CODES = [
        self::INSUFFICIENT_SCOPE,
    ];

    /**
     * The authentication codes the gateway publishes, in the contract's order. Each is an
     * HTTP 401 and arrives as an AuthenticationException: the key id, the signature, the
     * clock or the source IP. Not retryable: fix the configuration.
     */
    public const AUTHENTICATION_ERROR_CODES = [
        'INVALID_API_KEY',
        'INVALID_SIGNATURE',
        'TIMESTAMP_OUT_OF_RANGE',
        'IP_NOT_ALLOWED',
    ];

    /** HTTP 503: the API cannot serve the request right now. Nothing changed; retry later. */
    public const MERCHANT_API_UNAVAILABLE = 'MERCHANT_API_UNAVAILABLE';

    /**
     * The transient codes the gateway publishes, in the contract's order: retry later with
     * the SAME idempotency key. MERCHANT_API_UNAVAILABLE is an HTTP 503 (a TransportException
     * that keeps the code, a RevokeException on revokePaymentMethod()).
     * PAYMENT_PROCESSING_UNAVAILABLE is an HTTP 200 refusal on a session and a 503
     * ChargeException on a charge.
     */
    public const TRANSIENT_ERROR_CODES = [
        self::MERCHANT_API_UNAVAILABLE,
        self::PAYMENT_PROCESSING_UNAVAILABLE,
    ];

    /**
     * The codes a 400 VALIDATION_ERROR names per field, in the contract's order, at
     * getResult()['error']['validationErrors'][n]['code']. They are never getErrorCode().
     */
    public const FIELD_VALIDATION_CODES = [
        'REQUIRED',
        'MIN_VALUE',
        'MAX_LENGTH',
        'INVALID_FORMAT',
        'INVALID_SELECTION',
        'DATE_IN_FUTURE',
        'INVALID_EMAIL',
        'INVALID_CURRENCY',
    ];

    /** integration value for the hosted cashier widget, the default when you send none. */
    public const INTEGRATION_WIDGET = 'widget';

    /**
     * integration value for card fields rendered in your own page by the checkout.js
     * drop-in. Enabled per merchant on request.
     */
    public const INTEGRATION_FIELDS = 'fields';

    /**
     * Every value createCheckoutSession() accepts in `integration`, in the API's own order.
     * The session answers with the one in effect in checkout.integration.
     */
    public const INTEGRATION_VOCABULARY = [
        self::INTEGRATION_WIDGET,
        self::INTEGRATION_FIELDS,
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
     * succeeded: the money moved. failed: it did not, and declineClass says why when it
     * is set. pending is not terminal: poll getStatus() with the charge's transactionId.
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
     * reasonable. A declined charge can have no class yet (null): read getStatus() a
     * moment later. A value not in this list is a new class, not an error.
     */
    public const DECLINE_CLASS_VOCABULARY = [
        'hard',
        'soft_funds',
        'soft_sca_required',
        'soft_other',
    ];

    /**
     * Every errorCode chargePaymentMethod() raises as a ChargeException, by HTTP status:
     * 404 (the payment method or the agreement is not yours), 409 (the method is not
     * active, or a charge with this key is in flight), 422 (the key was used for another
     * body, or the currency or the amount does not fit the card, the agreement or the
     * per-charge cap, or the agreement cannot be charged now), 502 (CHARGE_OUTCOME_UNKNOWN,
     * CHARGE_FAILED) and 503 (PAYMENT_METHOD_CHARGES_DISABLED, PAYMENT_PROCESSING_UNAVAILABLE).
     * Nothing was charged under any of them except, maybe, CHARGE_OUTCOME_UNKNOWN and
     * DUPLICATE_REQUEST. DUPLICATE_REQUEST means an earlier request with this key is still
     * running, or it finished and its answer can no longer be replayed: in both cases a
     * charge may exist. Retry with the SAME key, or read the transaction; never retry under
     * a new key.
     * CHARGE_DECLINED (402) is deliberately not one of them: a decline is a charge with
     * status failed. The 429 codes are RateLimitExceptions (CHARGE_VELOCITY_EXCEEDED,
     * CHARGE_DECLINE_RATE_EXCEEDED).
     */
    public const CHARGE_ERROR_CODES = [
        'PAYMENT_METHOD_NOT_FOUND',
        'AGREEMENT_NOT_FOUND',
        'PAYMENT_METHOD_NOT_ACTIVE',
        'CHARGE_IN_PROGRESS',
        'DUPLICATE_REQUEST',
        'IDEMPOTENCY_KEY_REUSED',
        'CHARGE_CURRENCY_MISMATCH',
        'AGREEMENT_METHOD_MISMATCH',
        'AGREEMENT_CONSENT_MISSING',
        'AGREEMENT_CURRENCY_MISMATCH',
        'AGREEMENT_AMOUNT_MISMATCH',
        'CHARGE_AMOUNT_ABOVE_AGREEMENT',
        'AGREEMENT_PERIOD_ALREADY_CHARGED',
        'AGREEMENT_ENDED',
        'AGREEMENT_NOT_STARTED',
        'CHARGE_AMOUNT_ABOVE_CAP',
        'CHARGE_OUTCOME_UNKNOWN',
        'CHARGE_FAILED',
        'PAYMENT_METHOD_CHARGES_DISABLED',
        'PAYMENT_PROCESSING_UNAVAILABLE',
    ];

    /**
     * Every errorCode the plan and agreement routes answer, by HTTP status: 404
     * (PLAN_NOT_FOUND, AGREEMENT_NOT_FOUND, PAYMENT_METHOD_NOT_FOUND), 409 (PLAN_NOT_ACTIVE,
     * AGREEMENT_ENDED, PAYMENT_METHOD_NOT_ACTIVE) and 422 (the card's currency, a variable
     * amount below the plan's, missing consent, or IDEMPOTENCY_KEY_REUSED). Each arrives as
     * an ApiException; branch on getErrorCode(). Nothing was created or changed.
     */
    public const RECURRING_ERROR_CODES = [
        'PLAN_NOT_FOUND',
        'PLAN_NOT_ACTIVE',
        'AGREEMENT_NOT_FOUND',
        'AGREEMENT_ENDED',
        'PAYMENT_METHOD_NOT_FOUND',
        'PAYMENT_METHOD_NOT_ACTIVE',
        'PAYMENT_METHOD_CURRENCY_MISMATCH',
        'PAYMENT_METHOD_CURRENCY_UNKNOWN',
        'MAX_AMOUNT_BELOW_PLAN_AMOUNT',
        'AGREEMENT_CONSENT_REQUIRED',
        'IDEMPOTENCY_KEY_REUSED',
    ];

    /**
     * Every errorCode the webhook endpoint routes answer: 404 WEBHOOK_NOT_FOUND, 409
     * WEBHOOK_LIMIT_REACHED and WEBHOOK_ALREADY_REGISTERED, 400 WEBHOOK_URL_REJECTED, 422
     * IDEMPOTENCY_KEY_REUSED and 409 DUPLICATE_REQUEST (retry the SAME key after a second).
     * Each arrives as an ApiException; branch on getErrorCode().
     */
    public const WEBHOOK_ERROR_CODES = [
        'WEBHOOK_NOT_FOUND',
        'WEBHOOK_LIMIT_REACHED',
        'WEBHOOK_ALREADY_REGISTERED',
        'WEBHOOK_URL_REJECTED',
        'IDEMPOTENCY_KEY_REUSED',
        'DUPLICATE_REQUEST',
    ];

    /**
     * The payment events a webhook endpoint can receive, in the contract's order. Their
     * data is the payment: transactionId, status, previousStatus, amount, currency,
     * paymentMethod, walletType, storedPaymentMethod, verifyOnly, declineClass and the rest
     * of the fields in the README. Every field is in the payload, null when it has no value.
     */
    public const PAYMENT_WEBHOOK_EVENTS = [
        'payment.succeeded',
        'payment.failed',
        'payment.requires_capture',
        'payment.cancelled',
        'payment.abandoned',
        'payment.refunded',
        'payment.disputed',
    ];

    /**
     * The subscription events, in the contract's order. agreement.* data is the agreement
     * (id, planId, status, previousStatus, nextChargeAt, sequence, ...); charge.* data is
     * one scheduled charge (chargeId, transactionId, agreementId, outcome, paymentMethod
     * {brand, last4}, declineClass, nextAttemptAt, sequence, ...). Order both by
     * data.sequence. Every field is in the payload, null when it has no value.
     */
    public const RECURRING_WEBHOOK_EVENTS = [
        'agreement.activated',
        'agreement.past_due',
        'agreement.cancelled',
        'charge.succeeded',
        'charge.failed',
        'charge.retrying',
    ];

    /**
     * The verify-only events: a session created with verifyOnly saved the card or did not.
     * Their data has the payment events' shape, with verifyOnly true.
     */
    public const VERIFICATION_WEBHOOK_EVENTS = [
        'verification.succeeded',
        'verification.failed',
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

    /**
     * The same for the charge route, without 404: a coded 404 there (PAYMENT_METHOD_NOT_FOUND,
     * AGREEMENT_NOT_FOUND) is a ChargeException like the other charge refusals.
     */
    private const CHARGE_GENERIC_FAILURE_STATUSES = [400, 401, 403, 429];

    /**
     * The 5xx codes the automatic retry leaves to the caller. Each one is an answer about
     * this request, not an outage: the provider failed or broke the contract, the charge
     * outcome needs a getStatus() check, or charges are off for the payment method.
     */
    private const NOT_RETRIED_5XX_CODES = [
        'CHARGE_OUTCOME_UNKNOWN',
        'CHARGE_FAILED',
        'PAYMENT_METHOD_CHARGES_DISABLED',
        'UPSTREAM_CONTRACT_ERROR',
    ];

    private string $keyId;
    private string $secret;
    private string $baseUrl;
    private ?string $lastIdempotencyKey = null;
    private ?string $lastRequestId = null;
    private string $userAgent;
    /** @var object|null Anything with warning(string $message, array $context = []); PSR-3 fits. */
    private ?object $logger = null;
    private bool $debug = false;
    private int $maxRetries = self::DEFAULT_MAX_RETRIES;
    private int $timeoutMs = self::TIMEOUT_SECONDS * 1000;
    /** @var array<string,true> The Dominaite-Sdk-Notice values this client already warned about. */
    private array $seenNotices = [];

    /**
     * @param string $keyId   Your API key id (dmk_...), from the Dominaite dashboard/operator.
     * @param string $secret  Your API secret (dms_...). Server-side only.
     * @param ?string $baseUrl Required. self::SANDBOX_BASE_URL or self::PRODUCTION_BASE_URL.
     *                         There is no default: null, empty or whitespace throws, so an
     *                         unset environment variable cannot reach production. Must be
     *                         https://, except on localhost / 127.0.0.1 / ::1 for local
     *                         development.
     * @param array<string,mixed> $options Optional:
     *                         userAgent (string) - your own identifier, appended to the SDK's
     *                         User-Agent after one space. It helps support find your calls.
     *                         logger (object) - receives the SDK's warnings through
     *                         warning(string $message, array $context = []). A PSR-3
     *                         LoggerInterface fits; psr/log is not required. Without one,
     *                         warnings go to trigger_error(E_USER_DEPRECATED).
     *                         debug (bool) - one line per request (method, path, status,
     *                         time, request id, idempotency key) to the logger's debug(),
     *                         or to error_log() without one. Off by default.
     *                         maxRetries (int, 0 or more) - how many times a failed attempt is
     *                         sent again, with the same idempotency key. Default 2; 0 sends
     *                         each request once. See send() for what is retried.
     *                         timeout (int|float seconds, more than 0) - the limit for ONE
     *                         attempt. Default 45. With retries, one call can take up to
     *                         (maxRetries + 1) x timeout, plus the waits between attempts.
     *                         An unknown key throws InvalidArgumentException.
     */
    public function __construct(string $keyId, string $secret, ?string $baseUrl = null, array $options = [])
    {
        if (strpos($keyId, 'dmk_') !== 0) {
            throw new \InvalidArgumentException('keyId must start with dmk_');
        }
        if (strpos($secret, 'dms_') !== 0) {
            throw new \InvalidArgumentException('secret must start with dms_');
        }
        self::assertHeaderSafe('keyId', $keyId);
        $baseUrl = self::requireBaseUrl($baseUrl);
        $unknown = array_diff(array_keys($options), self::OPTION_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Unknown option ' . implode(', ', $unknown) . '; the options are ' . implode(', ', self::OPTION_KEYS)
            );
        }
        $this->keyId = $keyId;
        $this->secret = $secret;
        $this->baseUrl = $baseUrl;
        $this->userAgent = self::userAgent($options['userAgent'] ?? null);

        $logger = $options['logger'] ?? null;
        if ($logger !== null && (!is_object($logger) || !method_exists($logger, 'warning'))) {
            throw new \InvalidArgumentException('logger must be an object with a warning() method, for example a PSR-3 LoggerInterface');
        }
        $this->logger = $logger;
        $debug = $options['debug'] ?? false;
        if (!is_bool($debug)) {
            throw new \InvalidArgumentException('debug must be a bool');
        }
        $this->debug = $debug;

        $maxRetries = $options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES;
        if (!is_int($maxRetries) || $maxRetries < 0) {
            throw new \InvalidArgumentException('maxRetries must be an int of 0 or more');
        }
        $this->maxRetries = $maxRetries;
        $timeout = $options['timeout'] ?? self::TIMEOUT_SECONDS;
        if ((!is_int($timeout) && !is_float($timeout)) || !($timeout > 0) || is_infinite((float) $timeout)) {
            throw new \InvalidArgumentException('timeout must be a number of seconds, more than 0');
        }
        $this->timeoutMs = max(1, (int) round($timeout * 1000));
    }

    /**
     * Hands one warning to the logger, or to trigger_error() without one.
     *
     * E_USER_DEPRECATED rather than E_USER_WARNING: frameworks turn warnings into exceptions,
     * and a notice about this SDK must never fail a payment call. For the same reason
     * anything the logger or an error handler throws is dropped here.
     */
    private function warn(string $message): void
    {
        try {
            if ($this->logger !== null) {
                $this->logger->warning($message);
            } else {
                trigger_error($message, E_USER_DEPRECATED);
            }
        } catch (\Throwable $ignored) {
            // A log sink must not break the request it reports on.
        }
    }

    /** One debug line, only when the debug option is on. Never a body, a secret or a signature. */
    private function debugLine(string $line): void
    {
        if (!$this->debug) {
            return;
        }
        try {
            if ($this->logger !== null && method_exists($this->logger, 'debug')) {
                $this->logger->debug($line);
            } else {
                error_log($line);
            }
        } catch (\Throwable $ignored) {
            // A log sink must not break the request it reports on.
        }
    }

    /** A header value cut to $max characters, without control characters. */
    private static function headerText(string $value, int $max): string
    {
        $value = mb_substr($value, 0, $max, 'UTF-8');

        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }

    /**
     * Warns once per distinct Dominaite-Sdk-Notice value. The API sends it when this SDK
     * version is deprecated; a missing or empty header is no notice.
     */
    private function readNotice(?string $header): void
    {
        if ($header === null) {
            return;
        }
        $value = trim(self::headerText($header, 512));
        if ($value === '' || isset($this->seenNotices[$value]) || count($this->seenNotices) >= self::MAX_NOTICES) {
            return;
        }
        $this->seenNotices[$value] = true;
        $this->warn(self::noticeMessage($value));
    }

    /** deprecated; current="0.6.0"; sunset="2027-01-31"; info="https://..." as a sentence. */
    private static function noticeMessage(string $value): string
    {
        $parts = explode(';', $value);
        if (strtolower(trim($parts[0])) !== 'deprecated') {
            return 'dominaite-php ' . self::VERSION . ': ' . $value;
        }
        $params = [];
        foreach (array_slice($parts, 1) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $param = trim($pair[1]);
            if (strlen($param) >= 2 && $param[0] === '"' && substr($param, -1) === '"') {
                $param = substr($param, 1, -1);
            }
            $params[strtolower(trim($pair[0]))] = $param;
        }
        $message = 'dominaite-php ' . self::VERSION . ' is deprecated';
        if (($params['current'] ?? '') !== '') {
            $message .= '; upgrade to ' . $params['current'];
        }
        if (($params['sunset'] ?? '') !== '') {
            $message .= ' (sunset ' . $params['sunset'] . ')';
        }
        $message .= '.';
        if (($params['info'] ?? '') !== '') {
            $message .= ' ' . $params['info'];
        }

        return $message;
    }

    /**
     * dominaite-php/<version> (php <version>), then the caller's suffix after one space.
     * No operating system: the runtime version is all support needs.
     *
     * @param mixed $suffix
     */
    private static function userAgent($suffix): string
    {
        $userAgent = 'dominaite-php/' . self::VERSION . ' (php ' . PHP_VERSION . ')';
        if ($suffix === null) {
            return $userAgent;
        }
        if (!is_string($suffix)) {
            throw new \InvalidArgumentException('userAgent must be a string');
        }
        // A CR or LF would end the header line and start a header of the caller's choosing.
        if (preg_match('/[\x00-\x1F\x7F]/', $suffix) === 1) {
            throw new \InvalidArgumentException('userAgent must not contain control characters; it is sent as an HTTP header');
        }
        $suffix = trim($suffix);

        return $suffix === '' ? $userAgent : $userAgent . ' ' . $suffix;
    }

    /**
     * Returns the base URL without surrounding whitespace or trailing slashes, or throws
     * when there is none.
     *
     * There is deliberately no default. A default of production turns an unset
     * environment variable into sandbox credentials sent to production, which fail
     * authentication and can get the caller's IP blocked.
     */
    private static function requireBaseUrl(?string $baseUrl): string
    {
        $trimmed = rtrim(trim((string) $baseUrl), '/');
        if ($trimmed === '') {
            throw new \InvalidArgumentException(
                'baseUrl is required. Use ' . self::SANDBOX_BASE_URL . ' for the sandbox or '
                . self::PRODUCTION_BASE_URL . ' for production.'
            );
        }
        self::assertTransportIsEncrypted($trimmed);

        return $trimmed;
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
            'userAgent' => $this->userAgent,
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
        $this->baseUrl = self::requireBaseUrl(isset($data['baseUrl']) ? (string) $data['baseUrl'] : null);
        $this->lastIdempotencyKey = isset($data['lastIdempotencyKey']) ? (string) $data['lastIdempotencyKey'] : null;
        $this->userAgent = self::userAgent(null);
        $this->logger = null;
        $this->debug = false;
        $this->maxRetries = self::DEFAULT_MAX_RETRIES;
        $this->timeoutMs = self::TIMEOUT_SECONDS * 1000;
        $this->seenNotices = [];
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
        $ping = $this->request('GET', self::PING_PATH, null, '');

        $skew = (int) ($ping['clockSkewSeconds'] ?? 0);
        if (abs($skew) > self::CLOCK_SKEW_WARNING_SECONDS) {
            $this->warn("dominaite-php: clock skew is {$skew} s; requests fail at 300 s. Sync this server's clock.");
        }

        return $ping;
    }

    /**
     * Reads the public integration contract: the SDKs, the signing recipes, the request
     * fields, the statuses, the error codes, the scopes and the webhook event catalog.
     *
     * The API does not check a key on this route, so any key can read it, whatever its
     * scopes. The SDK signs it like ping() all the same. The SDK itself never reads the
     * contract at runtime: it is data for your tools, your tests and your docs.
     *
     * @return array<string,mixed> The contract as the API sent it.
     *
     * @throws ApiException       Unexpected API response.
     * @throws RateLimitException HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException Network-level failure or a 5xx (safe to retry).
     */
    public function getIntegrationContract(): array
    {
        return $this->request('GET', self::INTEGRATION_CONTRACT_PATH, null, '');
    }

    /**
     * Creates a hosted checkout session for one payment.
     *
     * Required params: amount (int, MINOR units - cents), currency (ISO 4217),
     * orderReference (your order id, <= 100 chars), idempotencyKey (1-100 visible
     * ASCII chars; build it with orderIdempotencyKey() so the same order at the same
     * amount always sends the same key - retrying with it never creates a second payment).
     * A missing key throws InvalidArgumentException before anything is sent.
     * Optional: customer{firstName,lastName,email,phone,address,city,postalCode,state}
     * (see below for the billing address), country (ISO 3166-1 alpha-2),
     * language (ISO 639-1), theme ('light'|'dark'|'bright'), description,
     * saveCard (bool - keep the card on file once this payment is approved, so you can
     * charge it again with chargePaymentMethod(); the stored method shows up as
     * storedPaymentMethod on getStatus(), and the card details never reach you),
     * integration (INTEGRATION_WIDGET, the default, or INTEGRATION_FIELDS for card fields
     * in your own page; card fields are enabled per merchant on request and 'fields' on an
     * account without them is a 400; part of the idempotency identity, so a replay with a
     * different integration is refused with IDEMPOTENCY_KEY_REUSED; omitted when null),
     * locationId (the id of the storefront the payment belongs to),
     * agreementId (the agr_ handle of a pending agreement from createAgreement(): the
     * session takes the agreement's first payment, saves the card and activates the
     * agreement; amount and currency must be the agreement's own, and on a plan with a
     * trial only the currency is held), consent{textVersion,acceptedAt,ip} (only with
     * agreementId: the platform consent text version the customer accepted, when and
     * from which IP; required unless the agreement already carries it), verifyOnly (bool,
     * see below). Any other key is sent as it is, so a field the API adds later needs no
     * SDK update.
     *
     * The billing address on customer is optional: address (street, one line, <= 200
     * chars), city (<= 100), postalCode (<= 20) and state (<= 50, an ISO 3166-2
     * subdivision code such as 'CA' or 'NSW', only used for the United States, Canada and
     * Australia). The checkout does not ask the payer for a field the session carries. A
     * value over its limit is a 400 ApiException: getErrorCode() is VALIDATION_ERROR and
     * getResult()['error']['validationErrors'] names the field with code MAX_LENGTH.
     *
     * verifyOnly true saves the card without a payment. Leave amount out or send 0: the
     * API places its own small verification hold and releases it once the card is saved.
     * saveCard is implied, integration must be the widget, and agreementId is refused.
     * Verify-only sessions are off until we switch them on for your account; before that
     * the API answers 403 VERIFY_ONLY_NOT_ENABLED (an AuthenticationException).
     * orderIdempotencyKey() needs a positive amount, so build this key yourself, for
     * example 'verify-' . $customerId. The session comes back with verifyOnly true and
     * amount set to the hold.
     *
     * The session always carries integration. clientSecret is set only for a fields
     * session (absent for a widget session): an opaque string of at most 128 characters,
     * the same on every replay, that the payer's page hands to checkout.js. Keep it out of
     * your logs.
     *
     * Re-sending a key the gateway already saw, with the same amount and currency, returns
     * the ORIGINAL session while it is still open and unexpired: same transactionId, same
     * cashierKey/cashierToken. That is what makes a reload or a retried timeout safe. Any
     * other replay answers HTTP 200 with success=false and a replay code, which arrives
     * here as a CheckoutRefusedException naming the transaction to reconcile with
     * getStatus().
     *
     * @param array<string,mixed> $params
     * @return array{transactionId:string,orderId:string,cashierKey:string,cashierToken:string,amount:int,currency:string,expiresAt:string,agreementId?:?string,integration:string,clientSecret?:?string,verifyOnly?:bool}
     *
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry),
     *                                 or HTTP 403 VERIFY_ONLY_NOT_ENABLED.
     * @throws CheckoutRefusedException The gateway refused the session (inspect getErrorCode()).
     * @throws StorefrontException     The storefront cannot take payments (STOREFRONT_ERROR_CODES, HTTP 409 or 400,
     *                                 or 200 for a replayed key first used for another storefront).
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

        if (array_key_exists('verifyOnly', $params)) {
            if ($params['verifyOnly'] === null) {
                unset($params['verifyOnly']);
            } elseif (!is_bool($params['verifyOnly'])) {
                throw new \InvalidArgumentException('verifyOnly must be a bool');
            }
        }
        if (($params['verifyOnly'] ?? false) === true) {
            self::validateVerifyOnlyParams($params);
        } else {
            self::validateMoneyParams($params);
        }
        if (array_key_exists('saveCard', $params) && !is_bool($params['saveCard'])) {
            throw new \InvalidArgumentException('saveCard must be a bool');
        }
        if (array_key_exists('integration', $params)) {
            if ($params['integration'] === null) {
                // Not set means the widget: leave the key out so the signed body of an
                // existing integration does not change.
                unset($params['integration']);
            } elseif (!is_string($params['integration'])) {
                throw new \InvalidArgumentException('integration must be a string');
            }
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
            $errorCode = (string) ($response['errorCode'] ?? 'UNKNOWN');
            $errorMessage = (string) ($response['errorMessage'] ?? 'The checkout session was refused.');

            // A storefront problem is one class whatever the status: a replay for another
            // storefront answers 200, a fresh request 400.
            if (in_array($errorCode, self::STOREFRONT_ERROR_CODES, true)) {
                throw new StorefrontException(200, $errorMessage, $errorCode, $response, $this->lastRequestId);
            }

            throw new CheckoutRefusedException(
                $errorCode,
                $errorMessage,
                is_string($transactionId) && $transactionId !== '' ? $transactionId : null,
                $response,
                $this->lastRequestId
            );
        }

        return $response['checkout'];
    }

    /**
     * The idempotency key the last call that carries one sent: createCheckoutSession(),
     * chargePaymentMethod(), createRefund(), createPlan(), createAgreement(),
     * createWebhookEndpoint(), or updatePlan() with a key.
     * Always the one you passed: the SDK no longer generates keys.
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
     * chargePaymentMethod(), createRefund(), createPlan(), createAgreement(),
     * createWebhookEndpoint(), or updatePlan() with a key overwrites it. On a long-lived
     * worker that means reading it in the catch block and storing it against your order,
     * not going back for it later.
     *
     * The routes that read or delete (ping(), getIntegrationContract(), getStatus(),
     * getRefund(), listRefunds(), listTransactions(), revokePaymentMethod(), the plan,
     * agreement and webhook endpoint reads, deletePlan(), cancelAgreement() and
     * deleteWebhookEndpoint()) sign an empty key by design and leave this untouched.
     */
    public function getLastIdempotencyKey(): ?string
    {
        return $this->lastIdempotencyKey;
    }

    /**
     * The request id of the last answer this client received: the Dominaite-Request-Id
     * response header, or metadata.requestId in the body when the header is absent. Give
     * it to support with the time of the call.
     *
     * Every result reads it here, including the lists and the calls that return nothing.
     * Every exception carries its own copy on getRequestId(). Null before the first answer,
     * after a request that got no answer, and when the answer carried no id. A single slot,
     * like getLastIdempotencyKey(): the next request overwrites it.
     */
    public function getLastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    /**
     * Reads the payment status of one of your checkout sessions.
     *
     * Status values: pending, processing, succeeded, failed, refunded, partially_refunded,
     * cancelled, disputed, requires_capture, abandoned. While a session is still payable,
     * expiresAt says until when; amounts are integers in MINOR units.
     *
     * orderReference, pspReference, refundedAmount, paymentMethod, walletType, declineClass,
     * updatedAt and expiresAt are always in the array, and null when they have no value;
     * verifyOnly is always there too, false unless the session was verify-only. The API
     * leaves a null field out; the SDK reads it back as null (false for verifyOnly).
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
     * pspReference is the payment processor's reference for the transaction, null until it
     * is known. A sale settled by reconciliation without a processor webhook can have it
     * null for good; refunds and cancellations carry the original sale's reference. Older
     * gateways do not send the key at all, so read it as $status['pspReference'] ?? null.
     *
     * paymentMethod says how the payer paid ('card', 'wallet', 'bank_transfer' or 'sepa',
     * see PAYMENT_METHOD_CATEGORIES); it is null while the payment is still open and on
     * older transactions. When it is 'wallet', walletType names the wallet ('apple_pay',
     * 'google_pay', 'samsung_pay', or a lower-cased identifier not in WALLET_TYPES yet:
     * treat an unknown value as a valid wallet, not an error); it is null for non-wallet
     * payments. Both are reporting data, never a money-flow switch: a wallet payment
     * refunds, captures and disputes exactly like a card payment. Older gateways do not
     * send the keys, so read them with ?? null.
     *
     * The API also sends these fields. It leaves some of them out when they have no value,
     * so read each one with ?? as shown:
     * - declineClass ($status['declineClass'] ?? null): why a failed payment was declined,
     *   a value of DECLINE_CLASS_VOCABULARY. Null on every status other than failed.
     *   declineClass can be null on a failed payment too, for example when nobody declined
     *   the payment. A value not in the list is a new class, not an error.
     * - isRecurring ($status['isRecurring'] ?? false): true for a merchant-initiated charge
     *   on a stored card (a recurring charge), false for a checkout session.
     * - verifyOnly ($status['verifyOnly'] ?? false): true for a session created with
     *   verifyOnly. Its status stays the raw status: requires_capture, then cancelled once
     *   the hold is released. A non-null storedPaymentMethod is what says the card is
     *   verified.
     * - agreement ($status['agreement'] ?? null): {id, status, nextChargeAt} of the
     *   subscription agreement this payment is for; null on a one-off payment. After a
     *   subscription session it says whether the subscription started: status turns active
     *   when the payment is approved, and stays pending after a decline or an abandoned
     *   session. nextChargeAt reads as null when the API leaves it out.
     * - sequence ($status['sequence'] ?? 0): data.sequence of the latest charge.* event about
     *   this payment, to order this read against those events. 0 on a checkout payment and
     *   before the first charge.* event.
     *
     * @param string $transactionId The transactionId returned by createCheckoutSession().
     * @return array{transactionId:string,orderId:string,orderReference:?string,pspReference?:?string,status:string,amount:int,currency:string,refundedAmount:?int,paymentMethod?:?string,walletType?:?string,declineClass?:?string,isRecurring?:bool,verifyOnly?:bool,createdAt:string,updatedAt:?string,expiresAt:?string,storedPaymentMethod?:?array{id:string,brand:?string,last4:?string,expiryMonth:?int,expiryYear:?int,status:string,retiredReason:?string},agreement?:?array{id:string,status:string,nextChargeAt:?string},sequence?:int}
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

        // Passed through as sent, except where the gateway omits a null field on the wire.
        // Every nullable key reads as null when absent: no order reference recorded, no
        // processor reference yet, nothing refunded, still open (no payment method), not a
        // wallet, not declined, never updated, or the payer's part is over.
        foreach (['orderReference', 'pspReference', 'refundedAmount', 'paymentMethod', 'walletType', 'declineClass',
            'updatedAt', 'expiresAt'] as $nullable) {
            $status[$nullable] = $status[$nullable] ?? null;
        }
        $status['verifyOnly'] = $status['verifyOnly'] ?? false;
        // The card on file and the agreement get one shape each, not two. When the gateway
        // sent no storedPaymentMethod or agreement at all there is no key here either.
        if (is_array($status['storedPaymentMethod'] ?? null)) {
            $status['storedPaymentMethod'] = self::storedPaymentMethod($status['storedPaymentMethod']);
        }
        if (is_array($status['agreement'] ?? null)) {
            $status['agreement']['nextChargeAt'] = $status['agreement']['nextChargeAt'] ?? null;
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
     * anything is sent). Optional: description, agreementId (the agr_ handle of the
     * agreement the charge is made under: it must be yours, linked to this payment method
     * and carry consent, and the amount must fit the agreement). Any other key is sent as
     * it is, after these.
     *
     * The HTTP status is the contract on this route. 201 (200 on a replay) returns the
     * charge, status 'succeeded', 'pending' or 'cancelled'. 402 returns the charge too:
     * a decline is not an exception, the charge has status 'failed'. declineClass tells
     * you whether to give up on the card (hard), wait (soft_funds, soft_other) or bring
     * the customer back for a hosted session (soft_sca_required). declineClass can be
     * null, for example when nobody declined the payment; handle a null class on a 402
     * like soft_other. 'pending' is not terminal - poll getStatus() with the charge's
     * transactionId. declineClass and declineCode are always null unless the charge was
     * declined; the gateway omits null fields on the wire and the SDK reads absent as
     * null.
     *
     * agreementId is the agr_ handle the charge was made under, and null when the request
     * named none. sequence is the charge's outcome counter as of this answer: data.sequence
     * of its charge.succeeded or charge.failed event, 0 while pending. A replay answers the
     * first response as it was, so for the current state read getStatus(). Older gateways
     * do not send sequence, so read it with ?? 0.
     *
     * @param array<string,mixed> $params
     * @return array{chargeId:string,status:string,declineClass:?string,declineCode:?string,transactionId:string,agreementId:?string,sequence?:int}
     *
     * @throws ChargeException         404, 409 or 422 with a code, or 502 or 503 with a code in
     *                                 CHARGE_ERROR_CODES: branch on getErrorCode(). An id that is not
     *                                 yours is 404 PAYMENT_METHOD_NOT_FOUND. CHARGE_OUTCOME_UNKNOWN
     *                                 carries the charge row (getCharge(), getTransactionId()): poll
     *                                 getStatus() with it, never retry under a new key.
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            Validation (HTTP 400) or an unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()), retry with the same key.
     * @throws TransportException      Network-level failure, or a 5xx with no code or a code not in
     *                                 CHARGE_ERROR_CODES (getErrorCode() keeps it). Retry WITH the same
     *                                 idempotencyKey - getLastIdempotencyKey().
     */
    public function chargePaymentMethod(string $paymentMethodId, array $params): array
    {
        $this->lastIdempotencyKey = null;

        $id = self::normalizePaymentMethodId($paymentMethodId);
        self::validateMoneyParams($params);
        foreach (['description', 'agreementId'] as $optional) {
            if (array_key_exists($optional, $params) && !is_string($params[$optional])) {
                throw new \InvalidArgumentException("{$optional} must be a string");
            }
        }
        $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'] ?? null);
        unset($params['idempotencyKey']);

        // The contract's fields first, in the contract's order, then any other key as the
        // caller sent it, so a field the API adds later needs no SDK update. The body is
        // what gets signed, so this code fixes the order of the known fields.
        $body = [
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'orderReference' => $params['orderReference'],
        ];
        foreach (['description', 'agreementId'] as $optional) {
            if (array_key_exists($optional, $params)) {
                $body[$optional] = $params[$optional];
            }
        }
        $body += $params;

        $this->lastIdempotencyKey = $idempotencyKey;

        $reply = $this->send('POST', self::PAYMENT_METHODS_PATH . '/' . $id . '/charges', $body, $idempotencyKey);

        $data = $reply['envelope']['data'] ?? null;
        $charge = is_array($data) && is_string($data['chargeId'] ?? null) ? self::charge($data) : null;
        $errorCode = $reply['error']['code'] ?? null;
        $errorCode = is_string($errorCode) && $errorCode !== '' ? $errorCode : null;

        // 201 (200 on a durable replay): the charge was placed, whatever its status. 402:
        // the provider declined; the envelope says success=false but the charge is right
        // there, status failed, so it is a result, not an exception.
        if ($charge !== null && (($reply['envelope']['success'] ?? null) === true || $reply['status'] === 402)) {
            return $charge;
        }

        // A 5xx is the charge's own outcome only for the codes the route owns. Any other
        // coded 5xx (the platform's INTERNAL_ERROR, say) stays the transport error it is.
        if ($errorCode !== null && $reply['status'] >= 400
            && !in_array($reply['status'], self::CHARGE_GENERIC_FAILURE_STATUSES, true)
            && ($reply['status'] < 500 || in_array($errorCode, self::CHARGE_ERROR_CODES, true))) {
            throw new ChargeException(
                $reply['status'],
                $errorCode,
                (string) ($reply['error']['message'] ?? 'The charge was refused.'),
                $charge,
                $reply['envelope'],
                $reply['requestId'] ?? null
            );
        }
        if ($reply['status'] >= 400) {
            throw self::rejection($reply);
        }
        throw self::unexpected($reply, 'The API answered the charge without a charge body');
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
     * @throws TransportException      Network-level failure, or a 5xx with no code or another code
     *                                 (getErrorCode() keeps it). Safe to retry.
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
        if (is_string($errorCode) && $errorCode !== '' && !in_array($reply['status'], self::GENERIC_FAILURE_STATUSES, true)
            && ($reply['status'] < 500 || in_array($errorCode, self::REVOKE_ERROR_CODES, true))) {
            throw new RevokeException(
                $reply['status'],
                $errorCode,
                (string) ($reply['error']['message'] ?? 'The revoke was refused.'),
                $reply['envelope'],
                $reply['requestId'] ?? null
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
     * (string, at most 500 characters, stored with the refund). Any other key is sent as
     * it is, after these.
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

        // The known fields first, in the contract's order, then any other key as the caller
        // sent it, so a field the API adds later needs no SDK update. A full refund sends
        // no amount key at all, which is what the gateway reads as "everything left".
        $body = [];
        if (isset($params['amount'])) {
            $body['amount'] = $params['amount'];
        }
        if (isset($params['reason'])) {
            $body['reason'] = $params['reason'];
        }
        unset($params['amount'], $params['reason'], $params['idempotencyKey']);
        $body += $params;

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
     * the amount requested on pending (null for a full refund), the amount being refunded on
     * processing (null until a full refund has been sized), the amount actually refunded on
     * succeeded, and always null on failed. failureCode and failureMessage are
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
     * Lists the refunds on one payment: {transactionId, currency, refundedAmount,
     * refundableAmount, refunds}. Every amount is in minor units of currency.
     *
     * refunds holds the refunds created through this API, each in getRefund()'s shape, and
     * is empty when there are none. The order is pending first, then processing, then
     * succeeded and failed by completedAt, newest first within each group. A refund made
     * in the dashboard or at the provider is not in the list, but refundedAmount counts it.
     * refundableAmount is what a new refund could take right now: what is not refunded yet,
     * minus refunds still in progress. It is 0 when the payment cannot be refunded.
     *
     * A refund shows up here at the same moment getRefund() stops answering
     * REFUND_NOT_FOUND.
     *
     * @param string $transactionId The id createCheckoutSession() or chargePaymentMethod() returned.
     * @return array{transactionId:string,currency:string,refundedAmount:int,refundableAmount:int,refunds:list<array{refundId:string,transactionId:string,status:string,amount:?int,currency:string,failureCode:?string,failureMessage:?string,completedAt:?string}>}
     *
     * @throws RefundException         PAYMENT_NOT_FOUND (HTTP 404): branch on getErrorCode().
     * @throws AuthenticationException Wrong/revoked credentials or bad signature (fix config; do not retry).
     * @throws ApiException            Unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry). A 503 means the
     *                                 refunds changed while the API read them: retry after a second.
     */
    public function listRefunds(string $transactionId): array
    {
        $id = self::normalizeTransactionId($transactionId);

        // GET signs an EMPTY idempotency key and an EMPTY body, like getRefund().
        $reply = $this->send('GET', self::PAYMENTS_PATH . '/' . $id . '/refunds', null, '');

        $data = $reply['envelope']['data'] ?? null;
        if ($reply['status'] < 400 && is_array($data) && is_array($data['refunds'] ?? null)) {
            $data['transactionId'] = (string) ($data['transactionId'] ?? '');
            $data['currency'] = (string) ($data['currency'] ?? '');
            $data['refundedAmount'] = (int) ($data['refundedAmount'] ?? 0);
            $data['refundableAmount'] = (int) ($data['refundableAmount'] ?? 0);
            $data['refunds'] = array_map(static function (array $refund): array {
                return self::refund($refund);
            }, array_values($data['refunds']));

            return $data;
        }

        throw self::refundRejection($reply, 'The API answered without a refund list');
    }

    /**
     * The reading shared by both single-refund routes: the refund on a 2xx, the refund
     * failure otherwise.
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

        throw self::refundRejection($reply, 'The API answered without a refund body');
    }

    /**
     * A refund route's failure: a RefundException for a code in REFUND_ERROR_CODES, the
     * generic exceptions for everything else, and $unexpected for a 2xx without the body.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     */
    private static function refundRejection(array $reply, string $unexpected): \RuntimeException
    {
        $errorCode = $reply['error']['code'] ?? null;
        if ($reply['status'] >= 400 && $reply['status'] < 500
            && is_string($errorCode) && in_array($errorCode, self::REFUND_ERROR_CODES, true)) {
            return new RefundException(
                $reply['status'],
                (string) ($reply['error']['message'] ?? 'The refund was refused.'),
                $errorCode,
                $reply['envelope'],
                $reply['requestId'] ?? null
            );
        }
        if ($reply['status'] >= 400) {
            return self::rejection($reply);
        }

        return self::unexpected($reply, $unexpected);
    }

    /**
     * Creates a subscription plan: the price and the billing interval that agreements are
     * made from.
     *
     * Required params: name (1-100 characters), amount (int, MINOR units; with scheduled
     * charges on, the platform charges it once per period), currency (ISO 4217),
     * intervalUnit (day, week, month or year) and idempotencyKey. Optional: intervalCount
     * (units per period, default 1; the period is at most one year), periodCount
     * (agreements complete after this many periods; leave it out for an agreement that
     * runs until cancelled), trialDays (0-365) and cancelUrl (your https page where
     * the customer cancels; the platform prints it on every receipt it emails). Any other
     * key is sent as it is, so a field the API adds later needs no SDK update. The API
     * checks the values; the SDK does not.
     *
     * amount, currency and the interval never change. For a new price, create a new plan
     * and archive the old one with deletePlan().
     *
     * The same idempotencyKey with the same body answers the first plan again, so a create
     * whose answer you lost is safe to repeat. The same key with a different body is an
     * ApiException with IDEMPOTENCY_KEY_REUSED. Keys are not case-sensitive on the API.
     *
     * @param array<string,mixed> $params
     * @return array{id:string,name:string,amount:int,currency:string,intervalUnit:string,intervalCount:int,periodCount:?int,trialDays:int,status:string,cancelUrl:?string,createdAt:string,archivedAt:?string}
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            Validation (HTTP 400) or IDEMPOTENCY_KEY_REUSED (HTTP 422): branch on getErrorCode().
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()), retry with the same key.
     * @throws TransportException      Network-level failure or a 5xx: retry WITH the same idempotencyKey.
     */
    public function createPlan(array $params): array
    {
        $this->lastIdempotencyKey = null;
        $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'] ?? null, self::RECORD_KEY_HINT);
        unset($params['idempotencyKey']);
        $this->lastIdempotencyKey = $idempotencyKey;

        return self::plan(self::objectReply($this->send('POST', self::PLANS_PATH, $params, $idempotencyKey), 'plan'));
    }

    /**
     * Lists your plans: at most 200, active first, newest first within a status. There
     * are no pages on this route; read one plan with getPlan() when you have more.
     *
     * @return list<array{id:string,name:string,amount:int,currency:string,intervalUnit:string,intervalCount:int,periodCount:?int,trialDays:int,status:string,cancelUrl:?string,createdAt:string,archivedAt:?string}>
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            Unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function listPlans(): array
    {
        return array_map(static function (array $plan): array {
            return self::plan($plan);
        }, self::listReply($this->send('GET', self::PLANS_PATH, null, ''), 'plan'));
    }

    /**
     * Reads one plan. status is active or archived; only an active plan takes new
     * agreements.
     *
     * @param string $planId The plan_ id createPlan() returned, exactly as returned: it is case-sensitive.
     * @return array{id:string,name:string,amount:int,currency:string,intervalUnit:string,intervalCount:int,periodCount:?int,trialDays:int,status:string,cancelUrl:?string,createdAt:string,archivedAt:?string}
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            PLAN_NOT_FOUND (HTTP 404), or an unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function getPlan(string $planId): array
    {
        $id = self::normalizeHandle($planId, 'planId', 'createPlan()');

        return self::plan(self::objectReply($this->send('GET', self::PLANS_PATH . '/' . $id, null, ''), 'plan'));
    }

    /**
     * Changes a plan's name, trialDays or cancelUrl. Send at least one of them. A field you
     * leave out stays as it is; an empty cancelUrl removes it. A trialDays change reaches
     * only agreements created afterwards. Any other key is sent as it is.
     *
     * The API does not require an idempotency key here: the same change sent twice gives
     * the same plan. An idempotencyKey you pass anyway is signed and sent.
     *
     * @param string              $planId The plan_ id createPlan() returned.
     * @param array<string,mixed> $params
     * @return array{id:string,name:string,amount:int,currency:string,intervalUnit:string,intervalCount:int,periodCount:?int,trialDays:int,status:string,cancelUrl:?string,createdAt:string,archivedAt:?string}
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            Validation (HTTP 400) or PLAN_NOT_FOUND (HTTP 404): branch on getErrorCode().
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function updatePlan(string $planId, array $params): array
    {
        $idempotencyKey = '';
        if (array_key_exists('idempotencyKey', $params)) {
            $this->lastIdempotencyKey = null;
            $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'], self::RECORD_KEY_HINT);
            unset($params['idempotencyKey']);
        }
        $id = self::normalizeHandle($planId, 'planId', 'createPlan()');
        if ($idempotencyKey !== '') {
            $this->lastIdempotencyKey = $idempotencyKey;
        }

        return self::plan(self::objectReply($this->send('PATCH', self::PLANS_PATH . '/' . $id, $params, $idempotencyKey), 'plan'));
    }

    /**
     * Archives a plan: it takes no new agreements, and the agreements made from it go on
     * as they are. A plan that is archived already answers the same, so a retry is safe.
     *
     * @param string $planId The plan_ id createPlan() returned.
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            PLAN_NOT_FOUND (HTTP 404), or a malformed id (HTTP 400).
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function deletePlan(string $planId): void
    {
        $id = self::normalizeHandle($planId, 'planId', 'createPlan()');

        // DELETE signs an EMPTY idempotency key and an EMPTY body, like GET.
        self::emptyReply($this->send('DELETE', self::PLANS_PATH . '/' . $id, null, ''));
    }

    /**
     * Creates a subscription agreement: one customer on one plan. The agreement starts
     * pending and nothing is charged. The customer activates it in a subscription session:
     * createCheckoutSession() with its agreementId.
     *
     * Required params: planId (the plan_ id of an active plan), customerReference (your id
     * for the customer, 1-100 characters) and idempotencyKey. Optional:
     * storedPaymentMethodId (a pm_ card this customer saved in the plan's currency, to bill
     * instead of saving a new card in the session), consent{textVersion, acceptedAt, ip}
     * (all three together; required with storedPaymentMethodId or maxAmountPerPeriod) and
     * maxAmountPerPeriod (int, MINOR units, for a variable-amount subscription; at least
     * the plan's amount). Any other key is sent as it is. The API checks the values; the
     * SDK does not.
     *
     * The same idempotencyKey with the same body answers the first agreement again; the
     * same key with a different body is IDEMPOTENCY_KEY_REUSED.
     *
     * A refusal is an ApiException; branch on getErrorCode(). 404: PLAN_NOT_FOUND,
     * PAYMENT_METHOD_NOT_FOUND. 409: PLAN_NOT_ACTIVE, PAYMENT_METHOD_NOT_ACTIVE. 422:
     * PAYMENT_METHOD_CURRENCY_MISMATCH, MAX_AMOUNT_BELOW_PLAN_AMOUNT,
     * AGREEMENT_CONSENT_REQUIRED, PAYMENT_METHOD_CURRENCY_UNKNOWN, IDEMPOTENCY_KEY_REUSED.
     * Nothing was created, and the same request gets the same answer. getResult() has the
     * envelope; validationErrors[0].params carries the amounts and currencies in question.
     *
     * @param array<string,mixed> $params
     * @return array{id:string,planId:string,customerReference:string,storedPaymentMethodId:?string,status:string,amount:int,maxAmountPerPeriod:?int,currency:string,intervalUnit:string,intervalCount:int,periodCount:?int,trialDays:int,nextChargeAt:?string,pastDueReason:?string,pastDueAt:?string,consent:?array{textVersion:string,acceptedAt:?string,ip:string,receivedAt:?string,receivedFromIp:?string},version:int,sequence:int,createdAt:string,activatedAt:?string,cancelledAt:?string}
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            A refusal above, or validation (HTTP 400): branch on getErrorCode().
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()), retry with the same key.
     * @throws TransportException      Network-level failure or a 5xx: retry WITH the same idempotencyKey.
     */
    public function createAgreement(array $params): array
    {
        $this->lastIdempotencyKey = null;
        $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'] ?? null, self::RECORD_KEY_HINT);
        unset($params['idempotencyKey']);
        $this->lastIdempotencyKey = $idempotencyKey;

        return self::agreement(
            self::objectReply($this->send('POST', self::AGREEMENTS_PATH, $params, $idempotencyKey), 'agreement')
        );
    }

    /**
     * Reads one agreement.
     *
     * status is pending, active, past_due, cancelled, completed or failed. A value not in
     * this list is a new status, not an error. pastDueReason says why an agreement is
     * past_due: card_expired or card_revoked (a new card fixes it), hard_decline,
     * dunning_exhausted, sca_required or unprocessable. It is null in every other status.
     * nextChargeAt is null while pending and once the agreement has ended. sequence counts
     * the agreement's changes: compare it with data.sequence on the agreement.* webhooks.
     * amount, currency and the interval are copied from the plan when the agreement is
     * created; version goes up when the price changes for a future period.
     *
     * @param string $agreementId The agr_ id createAgreement() returned, exactly as returned: it is case-sensitive.
     * @return array{id:string,planId:string,customerReference:string,storedPaymentMethodId:?string,status:string,amount:int,maxAmountPerPeriod:?int,currency:string,intervalUnit:string,intervalCount:int,periodCount:?int,trialDays:int,nextChargeAt:?string,pastDueReason:?string,pastDueAt:?string,consent:?array{textVersion:string,acceptedAt:?string,ip:string,receivedAt:?string,receivedFromIp:?string},version:int,sequence:int,createdAt:string,activatedAt:?string,cancelledAt:?string}
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            AGREEMENT_NOT_FOUND (HTTP 404), or an unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function getAgreement(string $agreementId): array
    {
        $id = self::normalizeHandle($agreementId, 'agreementId', 'createAgreement()');

        return self::agreement(
            self::objectReply($this->send('GET', self::AGREEMENTS_PATH . '/' . $id, null, ''), 'agreement')
        );
    }

    /**
     * Cancels an agreement: no further charge is scheduled. A charge already placed is not
     * reversed here. Allowed from pending, active and past_due. Cancelling a cancelled
     * agreement answers the same, so a retry is safe.
     *
     * @param string $agreementId The agr_ id createAgreement() returned.
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without the payments scope (403).
     * @throws ApiException            AGREEMENT_NOT_FOUND (HTTP 404) or AGREEMENT_ENDED (HTTP 409: it completed
     *                                 or failed, so there is nothing left to cancel).
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function cancelAgreement(string $agreementId): void
    {
        $id = self::normalizeHandle($agreementId, 'agreementId', 'createAgreement()');

        // DELETE signs an EMPTY idempotency key and an EMPTY body, like GET.
        self::emptyReply($this->send('DELETE', self::AGREEMENTS_PATH . '/' . $id, null, ''));
    }

    /**
     * The agreement as one shape: the API omits a null field, so absent reads as null, in
     * consent too. Everything the API sends is carried through as sent.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function agreement(array $data): array
    {
        $nullable = ['storedPaymentMethodId', 'maxAmountPerPeriod', 'periodCount', 'nextChargeAt', 'pastDueReason',
            'pastDueAt', 'consent', 'activatedAt', 'cancelledAt'];
        foreach ($nullable as $field) {
            $data[$field] = $data[$field] ?? null;
        }
        if (is_array($data['consent'])) {
            foreach (['acceptedAt', 'receivedAt', 'receivedFromIp'] as $field) {
                $data['consent'][$field] = $data['consent'][$field] ?? null;
            }
        }

        return $data;
    }

    /**
     * Registers a webhook endpoint: the URL that receives the events you name.
     *
     * Needs a key with the webhooks:manage scope. A key with the payments scope never holds
     * it, so use a separate reporting key. Any other key gets an AuthenticationException
     * (403 INSUFFICIENT_SCOPE).
     *
     * Required params: url (https on a public host, at most 500 characters), events (the
     * event types to deliver, 1-50 of them) and idempotencyKey. Any other key is sent as it
     * is. The SDK sends events as given and never checks them: the API refuses a type it
     * does not know (VALIDATION_ERROR), and a type the API adds later needs no SDK update.
     *
     * secret (whsec_...) is in the FIRST 201 only. Store it at once: a replay of the same
     * key, the list and every other answer leave it out, and it reads as null here. Verify
     * deliveries with verifyWebhook() and that secret. Lost it? Delete the endpoint and
     * register it again.
     *
     * A create with no answer (a timeout or a 503) may still have registered the endpoint.
     * The client never retries this call on its own, whatever maxRetries says. Retry it
     * yourself with the SAME key. If that answer has no secret (null), delete that endpoint
     * and create it again with a new key. A retry with a new key while the first endpoint
     * exists registers a second one.
     *
     * A refusal is an ApiException; branch on getErrorCode(). WEBHOOK_URL_REJECTED (400:
     * not https, does not resolve, or a private host), WEBHOOK_LIMIT_REACHED (409: you have
     * the most active endpoints allowed; delete one first), WEBHOOK_ALREADY_REGISTERED (409:
     * this key registered an endpoint more than a day ago that still exists;
     * getResult()['error']['params']['webhookId'] is its id; use a fresh key for a new
     * endpoint), DUPLICATE_REQUEST (409: retry with the SAME key after a second) and
     * IDEMPOTENCY_KEY_REUSED (422: this key was first used for another url or events).
     *
     * @param array<string,mixed> $params
     * @return array{id:string,url:string,events:list<string>,status:string,createdAt:string,secret:?string}
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without webhooks:manage (403).
     * @throws ApiException            A refusal above, or validation (HTTP 400): branch on getErrorCode().
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()), retry with the same key.
     * @throws TransportException      Network-level failure or a 5xx: retry WITH the same idempotencyKey after
     *                                 about five seconds, for up to about five minutes.
     */
    public function createWebhookEndpoint(array $params): array
    {
        $this->lastIdempotencyKey = null;
        $idempotencyKey = self::normalizeIdempotencyKey($params['idempotencyKey'] ?? null, self::RECORD_KEY_HINT);
        unset($params['idempotencyKey']);
        $this->lastIdempotencyKey = $idempotencyKey;

        $endpoint = self::objectReply($this->send('POST', self::WEBHOOKS_PATH, $params, $idempotencyKey), 'webhook endpoint');
        $endpoint['secret'] = $endpoint['secret'] ?? null;

        return $endpoint;
    }

    /**
     * Lists your webhook endpoints, newest first: every active one and your newest 1000
     * inactive ones. There are no pages, and never a secret. status is active or inactive
     * (paused in the dashboard or after repeated failed deliveries); a value not in this
     * list is a new status, not an error. Needs the webhooks:manage scope.
     *
     * @return list<array{id:string,url:string,events:list<string>,status:string,createdAt:string}>
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without webhooks:manage (403).
     * @throws ApiException            Unexpected response.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function listWebhookEndpoints(): array
    {
        return self::listReply($this->send('GET', self::WEBHOOKS_PATH, null, ''), 'webhook endpoint');
    }

    /**
     * Removes a webhook endpoint. Deliveries to it stop. Needs the webhooks:manage scope.
     *
     * An id that is unknown, of another account or deleted already is an ApiException with
     * WEBHOOK_NOT_FOUND (404). A retry after a lost 204 therefore answers 404: treat that
     * as done.
     *
     * @param string $webhookId The id createWebhookEndpoint() or listWebhookEndpoints() returned (a UUID).
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without webhooks:manage (403).
     * @throws ApiException            WEBHOOK_NOT_FOUND (HTTP 404).
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function deleteWebhookEndpoint(string $webhookId): void
    {
        $id = self::normalizeUuid($webhookId, 'webhookId must be the UUID createWebhookEndpoint() returned');

        // DELETE signs an EMPTY idempotency key and an EMPTY body, like GET.
        self::emptyReply($this->send('DELETE', self::WEBHOOKS_PATH . '/' . $id, null, ''));
    }

    /**
     * Lists your card-not-present payments in a time window, one page at a time:
     * {items, nextCursor}.
     *
     * Needs a key with the transactions:read scope. A key with the payments scope never
     * holds it, so use a separate reporting key. Any other key gets an
     * AuthenticationException (403 INSUFFICIENT_SCOPE).
     *
     * Required params: from (inclusive) and to (exclusive), ISO 8601 UTC, at most 31 days
     * apart. Use the Z form, for example gmdate('Y-m-d\TH:i:s\Z', $unixTime). The window is
     * on createdAt. Optional: status (only payments now in this status, a value of
     * STATUS_VOCABULARY), limit (1-100, default 50) and cursor (the nextCursor of the
     * previous page, unchanged). Optional settledFrom (inclusive) and settledTo (exclusive)
     * filter on the settlement date: dates only (2026-10-13), sent together, at most 31 days
     * apart. from and to still apply on createdAt. A null value is left out. Any other key is sent as it is.
     * Every value must be a string or an int: the SDK sends them in the query string, which
     * is never part of the signature. The API checks the values; the SDK does not.
     *
     * Rows come oldest first. To read the whole window, send nextCursor back as cursor with
     * the same from, to, settledFrom, settledTo and status until nextCursor is null. Paging never repeats or skips
     * a row. A refund is not a row of its own: it shows on its payment as refundedAmount
     * and status. type is payment, charge or verification (a verifyOnly session saved a
     * card; leave it out of revenue). A type or status not in these lists is a new value,
     * not an error. The API omits orderReference, completedAt, storedPaymentMethodId and
     * refundedAmount when it has no value; they read as null here.
     *
     * fee, netAmount (amount minus fee, in minor units), settledAt (the settlement date at
     * midnight UTC) and payoutId read as null until the acquirer settles the payment.
     * payoutId stays null until the money is paid out. fee and netAmount can change or go
     * back to null when the settlement is matched again.
     *
     * @param array<string,string|int|null> $params
     * @return array{items:list<array{transactionId:string,orderReference:?string,type:string,status:string,amount:int,currency:string,createdAt:string,completedAt:?string,storedPaymentMethodId:?string,refundedAmount:?int,fee:?int,netAmount:?int,settledAt:?string,payoutId:?string}>,nextCursor:?string}
     *
     * @throws AuthenticationException Bad credentials or signature (401), or a key without transactions:read (403).
     * @throws ApiException            Validation (HTTP 400): a missing or malformed from, to, settledFrom, settledTo, status, limit or cursor.
     * @throws RateLimitException     HTTP 429 - back off (getRetryAfterSeconds()).
     * @throws TransportException      Network-level failure or a 5xx (safe to retry).
     */
    public function listTransactions(array $params): array
    {
        foreach ($params as $name => $value) {
            if ($value === null) {
                unset($params[$name]);
            } elseif (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException("{$name} must be a string or an int: it is sent in the query string");
            }
        }
        // RFC 3986 encoding: a + in a time offset goes out as %2B, never as a space.
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        // GET signs an EMPTY idempotency key and an EMPTY body; send() leaves the query out.
        $reply = $this->send('GET', self::TRANSACTIONS_PATH . ($query === '' ? '' : '?' . $query), null, '');
        $page = self::settle($reply);
        if (!is_array($page['items'] ?? null)) {
            throw self::unexpected($reply, 'The API answered without a transaction page');
        }
        $page['items'] = array_map(static function (array $item): array {
            foreach (['orderReference', 'completedAt', 'storedPaymentMethodId', 'refundedAmount', 'fee', 'netAmount',
                'settledAt', 'payoutId'] as $nullable) {
                $item[$nullable] = $item[$nullable] ?? null;
            }

            return $item;
        }, array_values($page['items']));
        $page['nextCursor'] = $page['nextCursor'] ?? null;

        return $page;
    }

    /**
     * The plan as one shape: the API omits periodCount, cancelUrl and archivedAt when they
     * are null, so absent reads as null. Everything the API sends is carried through as sent.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function plan(array $data): array
    {
        foreach (['periodCount', 'cancelUrl', 'archivedAt'] as $nullable) {
            $data[$nullable] = $data[$nullable] ?? null;
        }

        return $data;
    }

    /**
     * A reply that must carry one object with an id: the object on a 2xx, the generic
     * failure otherwise.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     * @return array<string,mixed>
     */
    private static function objectReply(array $reply, string $what): array
    {
        $data = self::settle($reply);
        if (!is_string($data['id'] ?? null)) {
            throw self::unexpected($reply, "The API answered without the {$what}");
        }

        return $data;
    }

    /**
     * A reply that must carry a JSON array of objects: the array on a 2xx, the generic
     * failure otherwise.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     * @return list<array<string,mixed>>
     */
    private static function listReply(array $reply, string $what): array
    {
        self::settle($reply);
        $data = $reply['envelope']['data'] ?? null;
        if (!is_array($data) || $data !== array_values($data) || count(array_filter($data, 'is_array')) !== count($data)) {
            throw self::unexpected($reply, "The API answered without the {$what} list");
        }

        return $data;
    }

    /**
     * A reply that carries no body on success (204): nothing, or the generic failure.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     */
    private static function emptyReply(array $reply): void
    {
        if ($reply['status'] >= 400) {
            throw self::rejection($reply);
        }
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
     * The checks for a verify-only session. Currency and orderReference are required as for
     * a payment. The amount is 0 or left out, because the API sets the verification hold.
     *
     * @param array<string,mixed> $params
     */
    private static function validateVerifyOnlyParams(array $params): void
    {
        foreach (['currency', 'orderReference'] as $required) {
            if (!isset($params[$required])) {
                throw new \InvalidArgumentException("Missing required parameter: {$required}");
            }
        }
        if (array_key_exists('amount', $params) && $params['amount'] !== 0) {
            throw new \InvalidArgumentException('amount must be 0 or left out with verifyOnly: the API sets the verification hold');
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
    private static function normalizeIdempotencyKey(
        $idempotencyKey,
        string $derive = 'derive it from your order with DominaiteClient::orderIdempotencyKey()'
    ): string {
        if ($idempotencyKey === null) {
            throw new \InvalidArgumentException('idempotencyKey is required; ' . $derive);
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

    /**
     * Refuses anything that would not survive as one path segment of the signed canonical
     * path, and lowercases the rest. The API signs a charge on the lowercase pm_ id
     * whatever case the request used, so an uppercase id would fail the signature.
     */
    private static function normalizePaymentMethodId(string $paymentMethodId): string
    {
        $normalized = strtolower(trim($paymentMethodId));
        if (preg_match(self::PAYMENT_METHOD_ID_PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException('paymentMethodId must be the storedPaymentMethod id from getStatus()');
        }

        return $normalized;
    }

    /** A transaction id goes into the signed path in lowercase hyphenated form. */
    private static function normalizeTransactionId(string $transactionId): string
    {
        return self::normalizeUuid($transactionId, 'transactionId must be the UUID returned by createCheckoutSession()');
    }

    /**
     * A UUID goes into the signed path in lowercase hyphenated form: the API signs the id
     * it parsed, written that way, whatever case the request used.
     */
    private static function normalizeUuid(string $uuid, string $message): string
    {
        $normalized = strtolower(trim($uuid));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $normalized) !== 1) {
            throw new \InvalidArgumentException($message);
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
     * Same guard as a payment method id, for the plan_ and agr_ ids: one segment of the
     * signed path, sent verbatim, because the API reads them case-sensitively.
     */
    private static function normalizeHandle(string $handle, string $name, string $source): string
    {
        $normalized = trim($handle);
        if (preg_match(self::PAYMENT_METHOD_ID_PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException("{$name} must be the id {$source} returned");
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
     * A failed attempt is sent again, up to the maxRetries option (default 2), when the
     * API can replay it: a GET, or a POST with an idempotency key. The body, the path and
     * the idempotency key are the same on every attempt; only the timestamp, and so the
     * signature, is new. An attempt is retried after:
     *  - no answer at all (connect error, reset, timeout);
     *  - a 5xx, except a code in NOT_RETRIED_5XX_CODES;
     *  - a PAYMENT_PROCESSING_UNAVAILABLE refusal, whatever the status.
     * Never retried: a 429 (back off as Retry-After says), a 4xx, an answer over the size
     * cap, PATCH and DELETE, and webhook endpoint create, whose answer carries the signing
     * secret once. Before retry n (from 0) the client waits a random time from 0 to
     * min(8 s, 500 ms x 2^n). The last attempt's answer or exception is what the route sees.
     *
     * @param array<string,mixed>|null $body Null for GET and DELETE.
     * @return array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>,requestId:?string}
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

        // The signature covers the route's own path, never a query string: the API signs
        // its canonical path, not the URL it received. Only listTransactions() sends one.
        $queryAt = strpos($path, '?');
        $signedPath = $queryAt === false ? $path : substr($path, 0, $queryAt);

        $replayable = $method === 'GET'
            || ($method === 'POST' && $idempotencyKey !== '' && $signedPath !== self::WEBHOOKS_PATH);
        $retries = $replayable ? $this->maxRetries : 0;

        for ($attempt = 1; ; $attempt++) {
            $canRetry = $attempt <= $retries;
            $retry = false;

            $timestamp = (string) time();
            $headers = [
                'Content-Type: application/json',
                'X-Api-Key-Id: ' . $this->keyId,
                'X-Timestamp: ' . $timestamp,
                'X-Signature: ' . $this->sign($timestamp, $method, $signedPath, $idempotencyKey, $json),
            ];
            if ($idempotencyKey !== '') {
                $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
            }

            $started = hrtime(true);
            $answer = $this->exchange($method, $this->baseUrl . $path, $headers, $body === null ? null : $json);
            $this->lastRequestId = null;
            try {
                if ($answer['oversized']) {
                    throw new TransportException(
                        'The API response exceeded ' . self::MAX_RESPONSE_BYTES . ' bytes and was not read; '
                        . 'retry with the same idempotency key.'
                    );
                }
                if ($answer['error'] !== null) {
                    $retry = $canRetry;
                    throw new TransportException("Could not reach the Dominaite API: {$answer['error']}");
                }
                try {
                    $reply = $this->readResponse($answer['status'], $answer['body'], $answer['headers']);
                } catch (TransportException $unreadable) {
                    // Only a 5xx without a JSON body lands here: an edge's error page.
                    $retry = $canRetry;
                    throw $unreadable;
                }
                $retry = $canRetry && self::retriesReply($reply);
                if (!$retry) {
                    return $reply;
                }
            } catch (TransportException $failure) {
                if (!$retry) {
                    throw $failure;
                }
            } finally {
                // After readResponse(), so the line carries the request id from the header or
                // the body, and is written for an answer that throws too.
                if ($this->debug) {
                    $line = 'dominaite-php: ' . $method . ' ' . $signedPath . ' '
                        . ($answer['oversized'] || $answer['error'] !== null ? 'error' : (string) $answer['status']) . ' '
                        . intdiv(hrtime(true) - $started, 1000000) . 'ms';
                    if ($this->lastRequestId !== null) {
                        $line .= ' request_id=' . $this->lastRequestId;
                    }
                    if ($idempotencyKey !== '') {
                        $line .= ' idempotency_key=' . $idempotencyKey;
                    }
                    if ($attempt > 1) {
                        $line .= ' attempt=' . $attempt;
                    }
                    $this->debugLine($line);
                }
            }

            $this->sleepMs($this->randomMs(min(self::RETRY_CAP_MS, self::RETRY_BASE_MS * 2 ** ($attempt - 1))));
        }
    }

    /**
     * Whether a reply that readResponse() returned is worth another attempt.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>} $reply
     */
    private static function retriesReply(array $reply): bool
    {
        $code = $reply['error']['code'] ?? $reply['payload']['errorCode'] ?? null;
        if ($code === self::PAYMENT_PROCESSING_UNAVAILABLE) {
            return true;
        }

        return $reply['status'] >= 500 && !in_array($code, self::NOT_RETRIED_5XX_CODES, true);
    }

    /**
     * Sends one attempt over curl and hands back what came back, without reading it.
     *
     * @param list<string> $headers
     * @param ?string      $payload The JSON body, or null to send none.
     * @return array{status:int,body:string,headers:array<string,string>,error:?string,oversized:bool}
     *         error is curl's message when no answer came back, else null. headers has
     *         lowercased names.
     *
     * Protected so tests can stand in for the network; not part of the public API.
     */
    protected function exchange(string $method, string $url, array $headers, ?string $payload): array
    {
        $raw = '';
        $oversized = false;
        $responseHeaders = [];

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            // Some edges block requests without a real User-Agent - always send one.
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headers,
            // Buffer the body ourselves so the size cap can abort mid-transfer. Returning
            // a short count from this callback is curl's documented way to stop a read;
            // the transfer then fails with a write error, which $oversized tells apart
            // from a real network failure.
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw, &$oversized): int {
                if ($oversized || strlen($raw) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $oversized = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            },
            // The SDK reads Retry-After (429), Dominaite-Sdk-Notice and Dominaite-Request-Id.
            // Capturing the set costs nothing and keeps the parsing in one place. Later
            // duplicates win, which is what a redirect chain's final response should give us.
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders): int {
                $pair = explode(':', $header, 2);
                if (count($pair) === 2) {
                    $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
                }
                return strlen($header);
            },
        ];
        if ($this->timeoutMs < 1000) {
            // Below one second curl needs this, or the name lookup ignores the limit.
            $options[CURLOPT_NOSIGNAL] = true;
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $options);

        $completed = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $raw,
            'headers' => $responseHeaders,
            'error' => $completed === false && !$oversized ? $error : null,
            'oversized' => $oversized,
        ];
    }

    /**
     * Waits before a retry. Protected so tests can record the wait instead of sleeping.
     */
    protected function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * The random part of the backoff: a whole number from 0 to $max milliseconds.
     * Protected so tests can pin the draw; not part of the public API.
     */
    protected function randomMs(int $max): int
    {
        return random_int(0, $max);
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
        // First, so a notice on a 429, a 401 or an unreadable body still reaches the caller.
        $this->readNotice($responseHeaders['dominaite-sdk-notice'] ?? null);

        // 204 carries nothing to parse; the status is the whole answer, and only the header
        // can carry the request id.
        $decoded = $status === 204 ? null : json_decode($raw, true);
        $requestId = self::requestId($responseHeaders, is_array($decoded) ? $decoded : []);
        $this->lastRequestId = $requestId;

        if ($status === 429) {
            // The code is read when the body is the gateway's envelope, and skipped
            // otherwise: an edge's HTML 429 is still a rate limit.
            $limited = is_array($decoded) ? $decoded : [];
            $limitCode = is_array($limited['error'] ?? null) ? ($limited['error']['code'] ?? null) : null;
            throw new RateLimitException(
                'Rate limit exceeded (HTTP 429); back off and retry with the same idempotency key.',
                self::parseRetryAfter($responseHeaders['retry-after'] ?? null),
                is_string($limitCode) && $limitCode !== '' ? $limitCode : null,
                $limited,
                $requestId
            );
        }

        if ($status === 204) {
            return ['status' => 204, 'envelope' => [], 'payload' => [], 'error' => [], 'requestId' => $requestId];
        }

        if (!is_array($decoded)) {
            if ($status >= 500) {
                throw self::unavailable($status, null, [], $requestId);
            }
            throw new ApiException($status, 'The API returned a non-JSON response', null, [], $requestId);
        }

        // The gateway wraps responses as { success, data, ... }; unwrap when present.
        // Error responses carry the machine-readable code at error.code.
        $payload = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
        $envelopeError = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];

        if ($status === 401 || $status === 403) {
            $errorCode = (string) ($payload['errorCode'] ?? $envelopeError['code'] ?? 'UNAUTHORIZED');
            throw new AuthenticationException(
                $errorCode,
                self::refusalMessage($status, $errorCode, $envelopeError['message'] ?? null),
                $status,
                $requestId
            );
        }

        return ['status' => $status, 'envelope' => $decoded, 'payload' => $payload, 'error' => $envelopeError,
            'requestId' => $requestId];
    }

    /**
     * The request id of one answer: the Dominaite-Request-Id header when the API sent one,
     * otherwise metadata.requestId in the body. Both are the same id; production sends only
     * the body field so far.
     *
     * @param array<string,string> $responseHeaders Lowercased header names to values.
     * @param array<string,mixed>  $decoded         The decoded body, or [] when there is none.
     */
    private static function requestId(array $responseHeaders, array $decoded): ?string
    {
        $header = trim(self::headerText($responseHeaders['dominaite-request-id'] ?? '', 128));
        if ($header !== '') {
            return $header;
        }
        $body = is_array($decoded['metadata'] ?? null) ? ($decoded['metadata']['requestId'] ?? null) : null;
        if (!is_string($body)) {
            return null;
        }
        $body = trim(self::headerText($body, 128));

        return $body === '' ? null : $body;
    }

    /**
     * The message for a 401 or 403. Only a 401 is about the key id, secret or clock: a
     * 403 comes after the signature verified, so that hint would send the caller to
     * fix the wrong thing.
     *
     * @param mixed $gatewayMessage error.message from the envelope, when there is one.
     */
    private static function refusalMessage(int $status, string $errorCode, $gatewayMessage): string
    {
        if ($status === 401) {
            return 'Authentication failed - check your key id, secret, and server clock.';
        }
        $detail = is_string($gatewayMessage) && $gatewayMessage !== '' ? ' The API said: ' . $gatewayMessage : '';
        if ($errorCode === self::INSUFFICIENT_SCOPE) {
            return 'This API key does not hold the scope this route needs. The signature was valid, so the '
                . 'key id, secret and clock are fine. Use a key issued with that scope.' . $detail;
        }
        if ($errorCode === self::VERIFY_ONLY_NOT_ENABLED) {
            return 'Verify-only sessions are not switched on for this account. We switch it on for your '
                . 'account. Contact Dominaite to ask for it. Nothing was created.';
        }

        return "The API refused this call (HTTP 403, {$errorCode})." . $detail;
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
        $errorCode = $reply['payload']['errorCode'] ?? $reply['error']['code'] ?? null;
        $errorCode = is_string($errorCode) && $errorCode !== '' ? $errorCode : null;
        $requestId = $reply['requestId'] ?? null;
        if ($reply['status'] >= 500) {
            return self::unavailable($reply['status'], $errorCode, $reply['envelope'], $requestId);
        }
        $message = (string) ($reply['payload']['errorMessage'] ?? $reply['error']['message'] ?? 'Request rejected');

        if ($errorCode !== null && in_array($errorCode, self::STOREFRONT_ERROR_CODES, true)) {
            return new StorefrontException($reply['status'], $message, $errorCode, $reply['envelope'], $requestId);
        }

        return new ApiException($reply['status'], $message, $errorCode, $reply['envelope'], $requestId);
    }

    /**
     * A 2xx without the body the route promises.
     *
     * @param array{status:int,envelope:array<string,mixed>,payload:array<string,mixed>,error:array<string,mixed>,requestId?:?string} $reply
     */
    private static function unexpected(array $reply, string $message): ApiException
    {
        return new ApiException($reply['status'], $message, 'UNEXPECTED_RESPONSE', [], $reply['requestId'] ?? null);
    }

    /** @param array<string,mixed> $envelope */
    private static function unavailable(
        int $status,
        ?string $errorCode = null,
        array $envelope = [],
        ?string $requestId = null
    ): TransportException {
        return new TransportException(
            "The Dominaite API is unavailable (HTTP {$status}" . ($errorCode === null ? '' : ", {$errorCode}")
            . '); retry with the same idempotency key.',
            0,
            null,
            $status,
            $errorCode,
            $envelope,
            $requestId
        );
    }

    /**
     * The charge body as one shape: the gateway omits declineClass and declineCode when
     * they are null (every 201, and a 502 CHARGE_FAILED row), and agreementId on a charge
     * that named no agreement, so absent reads as null. Anything else the gateway sends is
     * carried through.
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
        $data['agreementId'] = is_string($data['agreementId'] ?? null) ? $data['agreementId'] : null;

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
