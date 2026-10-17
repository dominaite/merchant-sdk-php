<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * Network-level failure or a 5xx - the request may or may not have reached the API.
 * Safe to retry WITH THE SAME idempotency key; a retried key never creates a second payment.
 * Read the key off the client with getLastIdempotencyKey().
 *
 * If the first attempt did reach the gateway and its session is still open, the retry
 * returns that same session. Otherwise it comes back as a CheckoutRefusedException
 * carrying a replay code; reconcile with getStatus() from there.
 *
 * When the API answered, getHttpStatus() is its status. A 5xx in the gateway's envelope
 * keeps its code on getErrorCode() (for example MERCHANT_API_UNAVAILABLE or
 * INTERNAL_ERROR) and the envelope on getResult(). All three are null or empty when the
 * request never got an answer.
 */
class TransportException extends \RuntimeException
{
    private ?int $httpStatus;
    private ?string $errorCode;

    /** @var array<string,mixed> */
    private array $result;

    private ?string $requestId;

    /**
     * The first three parameters are \RuntimeException's own, so existing calls keep working.
     *
     * @param array<string,mixed> $result
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        array $result = [],
        ?string $requestId = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->requestId = $requestId;
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->result = $result;
    }

    /** The HTTP status the API answered with, or null when no answer arrived. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** error.code of a 5xx in the gateway's envelope, or null. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The envelope of a 5xx the gateway sent, or an empty array.
     *
     * @return array<string,mixed>
     */
    public function getResult(): array
    {
        return $this->result;
    }

    /**
     * The id the API gave this request: the Dominaite-Request-Id header, or metadata.requestId
     * in the body when the header is absent. Null when no answer carried one. Give it to
     * support with the time of the call.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
