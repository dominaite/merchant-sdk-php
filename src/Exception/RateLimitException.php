<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The API answered HTTP 429: you are sending faster than your key is allowed to.
 *
 * Platform limits at the time of writing: 60 requests per minute per API key, and
 * 120 requests per minute per source IP. The per-IP bucket is the one that bites when
 * several keys share one egress address, so a key well under 60/min can still land here.
 *
 * The SDK does NOT retry this for you. A blind retry loop against a rate limiter just
 * spends the next window too, and on createCheckoutSession() an automatic retry is a
 * decision about a payment that belongs to your code, not to the transport. Back off,
 * then retry WITH THE SAME idempotency key (getLastIdempotencyKey()) so a request that
 * did get through cannot turn into a second payment.
 *
 * getRetryAfterSeconds() is the server's own answer to "how long", in whole seconds,
 * taken from the Retry-After header. It is null when the header was absent or sent in
 * the HTTP-date form, which this SDK does not convert - fall back to your own backoff
 * (a few seconds, growing) when it is null rather than treating null as "retry now".
 *
 * getErrorCode() says which limit you hit, when the API sent a code: RATE_LIMIT_EXCEEDED
 * for the request limits, and on chargePaymentMethod() also CHARGE_VELOCITY_EXCEEDED
 * (too many charges on the card, or too much money in the currency, in the window) or
 * CHARGE_DECLINE_RATE_EXCEEDED (the key collected too many declines; its charge route is
 * locked for a while). Nothing was charged under any of them. It is null when the 429
 * came from an edge in front of the API, as HTML. Treat an unknown code as a rate limit.
 *
 * Extends ApiException, so existing `catch (ApiException $e)` blocks keep catching a
 * 429 as they did before. Catch RateLimitException FIRST when you want to branch on it.
 */
class RateLimitException extends ApiException
{
    private ?int $retryAfterSeconds;

    public function __construct(string $message, ?int $retryAfterSeconds = null, ?string $errorCode = null)
    {
        parent::__construct(429, $message, $errorCode);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    /**
     * Whole seconds to wait, per the Retry-After header, or null when the server did
     * not give a usable number. Never negative.
     */
    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
