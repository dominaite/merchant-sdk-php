<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * Network-level failure or a 5xx - the request may or may not have reached the API.
 * Safe to retry WITH THE SAME idempotency key; a retried key never creates a second payment.
 * Read the key off the client with getLastIdempotencyKey().
 *
 * If the first attempt did reach the gateway and its session is still open, the retry
 * returns that same session. Otherwise it comes back as a CheckoutRefusedException
 * carrying a replay code; reconcile with getStatus() from there.
 */
class TransportException extends \RuntimeException
{
}
