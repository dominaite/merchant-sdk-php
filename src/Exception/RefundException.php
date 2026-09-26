<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The gateway answered createRefund() or getRefund() with one of the refund error codes
 * (DominaiteClient::REFUND_ERROR_CODES) instead of a refund. Branch on getErrorCode():
 * - PAYMENT_NOT_FOUND (404): no card-not-present payment with this id under your account.
 * - REFUND_NOT_FOUND (404, getRefund() only): the refund may not be picked up yet right
 *   after the 202. Retryable: poll again for up to 60 seconds, after that the id is unknown.
 * - PAYMENT_NOT_REFUNDABLE (422): not paid, already fully refunded, or everything left is
 *   already being refunded. Nothing was queued and the key is not burnt.
 * - REFUND_AMOUNT_EXCEEDED (422): more than what is left to refund, counting refunds in
 *   progress; the message names the amount left. Nothing was queued, key not burnt.
 * - IDEMPOTENCY_KEY_REUSED (422): the key was first used for a different amount, reason
 *   or payment. Use a fresh key for a genuinely new refund.
 * - DUPLICATE_REQUEST (409): a request with this key is being processed. Retryable: retry
 *   the SAME key after a second, for up to 120 seconds.
 * - IDEMPOTENCY_KEY_REQUIRED (400): the key was missing; this SDK refuses to send without
 *   one, so a correct integration never sees it.
 *
 * A failed refund is not this exception: it is a refund with status 'failed' and a
 * failureCode (REFUND_FAILED, REFUND_AMOUNT_EXCEEDED or PAYMENT_NOT_REFUNDABLE).
 *
 * Extends ApiException, so an existing `catch (ApiException $e)` still catches it.
 */
class RefundException extends ApiException
{
    /** The codes a retry of the same request can resolve: poll or resend the SAME key. */
    private const RETRYABLE_CODES = ['REFUND_NOT_FOUND', 'DUPLICATE_REQUEST'];

    /** @var array<string,mixed> */
    private array $result;

    /** @param array<string,mixed> $result The full envelope. */
    public function __construct(int $httpStatus, string $message, string $errorCode, array $result = [])
    {
        parent::__construct($httpStatus, $message, $errorCode);
        $this->result = $result;
    }

    /**
     * True for REFUND_NOT_FOUND (poll getRefund() again, for up to 60 seconds) and
     * DUPLICATE_REQUEST (resend with the SAME key, for up to 120 seconds). False for every
     * other code: the same request will get the same answer.
     */
    public function isRetryable(): bool
    {
        return in_array($this->getErrorCode(), self::RETRYABLE_CODES, true);
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
