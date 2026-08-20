<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The API rejected your credentials or signature. Not retryable - fix the key id,
 * secret, or server clock. Machine-readable code via getErrorCode():
 * INVALID_API_KEY, INVALID_SIGNATURE, TIMESTAMP_OUT_OF_RANGE.
 */
class AuthenticationException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
