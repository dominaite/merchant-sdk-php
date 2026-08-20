<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The gateway understood the request but refused to open a checkout session.
 * Branch on getErrorCode():
 * - PAYMENT_PROCESSING_UNAVAILABLE: card payments are off right now; retry later.
 * - DUPLICATE_REQUEST: a session for this idempotency key is already open.
 * - ALREADY_PROCESSED: this idempotency key's payment already completed.
 * - IDEMPOTENCY_KEY_REUSED: same key sent with a DIFFERENT body; use a fresh key.
 */
class CheckoutRefusedException extends \RuntimeException
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
