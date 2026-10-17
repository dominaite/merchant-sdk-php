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
 * - PAYMENT_METHOD_NOT_FOUND (404): the id is not one of your stored payment methods.
 *   Nothing was charged. Any other coded 404 on the charge route is this exception too.
 * - PAYMENT_METHOD_NOT_ACTIVE (409): the method is revoked or expired; ask the customer
 *   for another card via a hosted session with saveCard.
 * - DUPLICATE_REQUEST (409): a request with this key is still in flight; retry with the
 *   SAME key in a moment.
 * - IDEMPOTENCY_KEY_REUSED (422): same key, different body or method; a bug on your side.
 * - PAYMENT_PROCESSING_UNAVAILABLE (503): nothing was charged; retry later with the SAME
 *   key.
 * - PAYMENT_METHOD_CHARGES_DISABLED (503): nothing was charged. If we never switched
 *   stored-card charges on for your account, a retry does not help: ask us. If they are
 *   on, retry later with the SAME key.
 *
 * A decline (HTTP 402) is deliberately NOT this exception: chargePaymentMethod() returns
 * it as a charge with status 'failed'. A 5xx with a code not listed in
 * DominaiteClient::CHARGE_ERROR_CODES (for example INTERNAL_ERROR) is not this exception
 * either: it is a TransportException that keeps the code.
 *
 * Not an ApiException: a catch (ApiException $e) block does not see it.
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

    private ?string $requestId;

    /**
     * @param array<string,mixed>|null $charge The charge row the gateway attached, when it did.
     * @param array<string,mixed>      $result The full envelope.
     */
    public function __construct(int $httpStatus, string $errorCode, string $message, ?array $charge = null, array $result = [], ?string $requestId = null)
    {
        parent::__construct($message);
        $this->requestId = $requestId;
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
     * declineCode, transactionId, agreementId}, and sequence when the API sent it. Null
     * when it attached none.
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
