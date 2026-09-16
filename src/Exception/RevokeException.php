<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The gateway refused to revoke a stored payment method. Nothing changed either way;
 * branch on getErrorCode():
 * - MERCHANT_API_UNAVAILABLE (503): the provider is unavailable or throttling; retry later.
 * - UPSTREAM_CONTRACT_ERROR (502): the provider refused the deletion for a reason a retry
 *   will not fix; contact support with the payment method id.
 *
 * An id that is not yours is still the generic ApiException with getHttpStatus() 404.
 */
class RevokeException extends \RuntimeException
{
    private int $httpStatus;
    private string $errorCode;

    /** @var array<string,mixed> */
    private array $result;

    /** @param array<string,mixed> $result The full envelope. */
    public function __construct(int $httpStatus, string $errorCode, string $message, array $result = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->result = $result;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The full envelope, for fields not modelled above.
     *
     * @return array<string,mixed>
     */
    public function getResult(): array
    {
        return $this->result;
    }
}
