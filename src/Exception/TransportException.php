<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * Network-level failure or a 5xx - the request may or may not have reached the API.
 * Safe to retry WITH THE SAME idempotency key; a retried key never creates a second payment.
 * Read the key off the client with getLastIdempotencyKey().
 *
 * If the first attempt did reach the gateway, the retry comes back as a
 * CheckoutRefusedException carrying a replay code, not as the original session - the
 * cashier fields are not replayed. Reconcile with getStatus() from there.
 */
class TransportException extends \RuntimeException
{
}
