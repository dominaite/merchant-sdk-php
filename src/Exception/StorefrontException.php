<?php

declare(strict_types=1);

namespace Dominaite\Exception;

/**
 * The gateway refused the session because of the storefront (online location) it would
 * be attributed to. Nothing was created. Branch on getErrorCode():
 * - STOREFRONT_NOT_WHITELISTED (409): the storefront's domain is not yet whitelisted with
 *   the payment provider. Retrying does not help until onboarding finishes the
 *   whitelisting; contact Dominaite with the domain. Show the payer "payments are not
 *   available yet", not a generic error.
 * - STOREFRONT_INACTIVE (409): the storefront was deactivated or deleted.
 * - STOREFRONT_MISMATCH (400): the API key is bound to one storefront and the request
 *   named another; use the key issued for that storefront.
 *
 * Extends ApiException, so an existing `catch (ApiException $e)` still catches it. Catch
 * StorefrontException first when you want to branch on it. The codes are also named
 * constants on DominaiteClient (STOREFRONT_NOT_WHITELISTED and friends).
 *
 * A key first used for one storefront and replayed for another answers STOREFRONT_MISMATCH
 * as an HTTP 200 refusal instead, which arrives as CheckoutRefusedException.
 */
class StorefrontException extends ApiException
{
}
