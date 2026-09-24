# Changelog

Versions follow semver. The package installs from git tags, so a version exists once its
`v` tag does.

## 1.0.0 (unreleased)

Breaking:

- `idempotencyKey` is required on `createCheckoutSession()` and `chargePaymentMethod()`. A
  missing key throws `InvalidArgumentException` before anything is sent; the random fallback
  is gone.

  Migration: pass `'idempotencyKey' => DominaiteClient::orderIdempotencyKey('checkout',
  $orderId, $amountMinor, $currency)`. The same order at the same amount now replays the
  open session instead of opening a second payment; a changed amount gets a new key.

Added:

- `DominaiteClient::orderIdempotencyKey()` builds `{scope}-{orderId}-{amountMinor}-{CURRENCY}`.
- Named error-code constants: `ALREADY_PROCESSED`, `PRIOR_ATTEMPT_FAILED`,
  `DUPLICATE_REQUEST`, `PAYMENT_PROCESSING_UNAVAILABLE`, `IDEMPOTENCY_KEY_REUSED`,
  `STOREFRONT_NOT_WHITELISTED`, `STOREFRONT_INACTIVE`, `STOREFRONT_MISMATCH`, plus
  `STOREFRONT_ERROR_CODES`.
- `StorefrontException` (extends `ApiException`) for the 409/400 storefront refusals.
- `toMinorUnits()` and `minorUnitExponent()`: decimal string to integer minor units by
  ISO 4217 exponent, no float math.
- `isPaid()`, `isTerminal()` and `TERMINAL_STATUSES`.
- Stored payment methods (`saveCard`, `chargePaymentMethod()`, `revokePaymentMethod()`),
  which landed after 0.2.0 and were never tagged.

## 0.2.0

Provider-neutral checkout path, webhook verification helpers, client hardening.
