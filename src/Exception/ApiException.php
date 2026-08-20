<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/** The API answered, but with an unexpected or rejecting response. */
class ApiException extends \RuntimeException
{
    private int $httpStatus;

    public function __construct(int $httpStatus, string $message)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
