<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * Network-level failure or a 5xx - the request may or may not have reached the API.
 * Safe to retry WITH THE SAME idempotency key; a retried key never creates a second payment.
 */
class TransportException extends \RuntimeException
{
}
