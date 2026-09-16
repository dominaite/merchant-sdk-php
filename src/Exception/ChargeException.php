<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The gateway answered a charge with an error code instead of a charge result. The
 * HTTP status is on getHttpStatus(), the machine-readable code on getErrorCode(), and
 * the charge row the gateway attached (when it did) on getCharge(). Branch on the code:
 * - CHARGE_OUTCOME_UNKNOWN (502): the provider gave no verdict and the charge MAY have
 *   happened. getCharge() is set: poll getStatus(getTransactionId()) or wait for the
 *   webhook. Never retry under a new key.
 * - CHARGE_FAILED (502): nothing was charged. getCharge() is set when a row exists (its
 *   declineClass and declineCode are null), null when the provider refused before one.
 * - PAYMENT_METHOD_NOT_ACTIVE (409): the method is revoked or expired; ask the customer
 *   for another card via a hosted session with saveCard.
 * - DUPLICATE_REQUEST (409): a request with this key is still in flight; retry with the
 *   SAME key in a moment.
 * - IDEMPOTENCY_KEY_REUSED (422): same key, different body or method; a bug on your side.
 * - PAYMENT_METHOD_CHARGES_DISABLED, PAYMENT_PROCESSING_UNAVAILABLE (503): nothing was
 *   charged; retry later with the SAME key.
 *
 * A decline (HTTP 402) is deliberately NOT this exception: chargePaymentMethod() returns
 * it as a charge with status 'failed' and a declineClass.
 *
 * getResult() is the whole envelope the gateway sent, for fields not modelled above.
 */
class ChargeException extends \RuntimeException
{
    private int $httpStatus;
    private string $errorCode;

    /** @var array<string,mixed>|null */
    private ?array $charge;

    /** @var array<string,mixed> */
    private array $result;

    /**
     * @param array<string,mixed>|null $charge The charge row the gateway attached, when it did.
     * @param array<string,mixed>      $result The full envelope.
     */
    public function __construct(int $httpStatus, string $errorCode, string $message, ?array $charge = null, array $result = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->charge = $charge;
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
     * The charge row the gateway attached to its answer: {chargeId, status, declineClass,
     * declineCode, transactionId}. Null when it attached none.
     *
     * @return array<string,mixed>|null
     */
    public function getCharge(): ?array
    {
        return $this->charge;
    }

    /** Shortcut for getCharge()['transactionId'], for polling getStatus(). Null without a charge. */
    public function getTransactionId(): ?string
    {
        $transactionId = $this->charge['transactionId'] ?? null;

        return is_string($transactionId) && $transactionId !== '' ? $transactionId : null;
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
