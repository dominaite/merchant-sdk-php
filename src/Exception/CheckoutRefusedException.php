<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The gateway understood the request but refused to open a checkout session.
 * Branch on getErrorCode():
 * - PAYMENT_PROCESSING_UNAVAILABLE: card payments are off right now; retry later.
 * - DUPLICATE_REQUEST: a session for this idempotency key is already open.
 * - ALREADY_PROCESSED: this idempotency key's payment already completed.
 * - PRIOR_ATTEMPT_FAILED: a prior attempt with this key failed terminally; use a fresh key.
 * - IDEMPOTENCY_KEY_REUSED: same key sent with a DIFFERENT body; use a fresh key.
 *
 * On a replay refusal the API also names WHICH payment your key collided with, on
 * getTransactionId(). That is the recovery path - read it back with getStatus() to
 * find out what the earlier attempt did, instead of minting a second payment for the
 * same order:
 *
 *   try {
 *       $session = $client->createCheckoutSession($params);
 *   } catch (CheckoutRefusedException $refusal) {
 *       if ($refusal->getTransactionId() !== null) {
 *           $status = $client->getStatus($refusal->getTransactionId());
 *       }
 *   }
 *
 * getTransactionId() is null when the API did not name one - notably the
 * concurrent-race DUPLICATE_REQUEST, which knows a key was taken but not yet by which
 * row. Always check before using it.
 */
class CheckoutRefusedException extends \RuntimeException
{
    private string $errorCode;
    private ?string $transactionId;

    /** @var array<string,mixed> */
    private array $result;

    /**
     * @param array<string,mixed> $result The full unwrapped refusal payload.
     */
    public function __construct(string $errorCode, string $message, ?string $transactionId = null, array $result = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->transactionId = $transactionId;
        $this->result = $result;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * The payment this idempotency key collided with, when the API named one.
     * Null otherwise - check before using it.
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /**
     * The full unwrapped refusal payload, for fields not modelled above.
     *
     * @return array<string,mixed>
     */
    public function getResult(): array
    {
        return $this->result;
    }
}
