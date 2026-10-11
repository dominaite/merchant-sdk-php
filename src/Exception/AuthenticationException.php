<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The API refused the request before the route ran. Not retryable as it is.
 * Machine-readable code via getErrorCode(), HTTP status via getHttpStatus().
 *
 * HTTP 401: the API rejected your credentials or signature. Fix the key id, secret, or
 * server clock. Codes: INVALID_API_KEY, INVALID_SIGNATURE, TIMESTAMP_OUT_OF_RANGE,
 * IP_NOT_ALLOWED.
 *
 * HTTP 403: the signature was valid, but the call is not allowed. Codes:
 * INSUFFICIENT_SCOPE (the key does not hold the scope the route needs; use a key issued
 * with that scope) and VERIFY_ONLY_NOT_ENABLED (verify-only sessions are not switched on
 * for your account).
 */
class AuthenticationException extends \RuntimeException
{
    private string $errorCode;
    private int $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 401)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** 401 for the key, signature or clock; 403 for a valid call that is not allowed. */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
