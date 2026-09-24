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
- An idempotency key must be 1 to 100 visible ASCII characters (0x21-0x7E). Spaces were
  accepted before and are now refused locally.

Added:

- `DominaiteClient::orderIdempotencyKey()` builds `{scope}-{orderId}-{amountMinor}-{CURRENCY}`.
- Named error-code constants: `ALREADY_PROCESSED`, `PRIOR_ATTEMPT_FAILED`,
  `DUPLICATE_REQUEST`, `PAYMENT_PROCESSING_UNAVAILABLE`, `IDEMPOTENCY_KEY_REUSED`,
  `STOREFRONT_NOT_WHITELISTED`, `STOREFRONT_INACTIVE`, `STOREFRONT_MISMATCH`, plus
  `STOREFRONT_ERROR_CODES`.
- `StorefrontException` (extends `ApiException`) for the 409/400 storefront refusals.
- `toMinorUnits()` and `minorUnitExponent()`: decimal string to integer minor units by the
  gateway's exponent (HUF is 0, not ISO's 2), no float math. ISK, KRW, OMR, JOD and TND
  throw as not supported.
- `isPaid()`, `isTerminal()` and `TERMINAL_STATUSES`.
- Stored payment methods (`saveCard`, `chargePaymentMethod()`, `revokePaymentMethod()`),
  which landed after 0.2.0 and were never tagged.

## 0.2.0

Provider-neutral checkout path, webhook verification helpers, client hardening.
