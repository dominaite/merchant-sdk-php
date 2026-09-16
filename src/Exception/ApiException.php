<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The API answered, but with an unexpected or rejecting response.
 *
 * getErrorCode() carries the machine-readable code when the API sent one - notably the
 * validation codes on a 400 (IDEMPOTENCY_KEY_REQUIRED) and PAYMENT_METHOD_NOT_FOUND on
 * a charge against an id that is not yours. It is null when the response had no code
 * to give, so check before branching on it.
 */
class ApiException extends \RuntimeException
{
    private int $httpStatus;
    private ?string $errorCode;

    public function __construct(int $httpStatus, string $message, ?string $errorCode = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** The API's machine-readable code, when it sent one. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
