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
 *
 * getResult() is the whole envelope the API sent, for fields not modelled here, such as
 * error.params (WEBHOOK_ALREADY_REGISTERED names the existing endpoint there) and
 * validationErrors. It is [] when the response was not the API's JSON.
 */
class ApiException extends \RuntimeException
{
    private int $httpStatus;
    private ?string $errorCode;

    /** @var array<string,mixed> */
    private array $result;

    private ?string $requestId;

    /** @param array<string,mixed> $result The full envelope, when the API sent one. */
    public function __construct(int $httpStatus, string $message, ?string $errorCode = null, array $result = [], ?string $requestId = null)
    {
        parent::__construct($message);
        $this->requestId = $requestId;
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->result = $result;
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

    /**
     * The full envelope the API sent, or [] when there was none.
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
