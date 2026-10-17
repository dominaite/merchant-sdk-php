# Changelog

Versions follow semver. The package installs from git tags, so a version exists once its
`v` tag does.

## 0.7.0 (unreleased)

Breaking:

- The client now retries a failed request on its own, up to `maxRetries` times (default 2).
  It retries only a GET or a POST with an idempotency key, and never
  `createWebhookEndpoint()`. It retries after no answer, a 5xx, or a
  `PAYMENT_PROCESSING_UNAVAILABLE` refusal. It never retries a 429, a 4xx, an oversized
  answer, PATCH or DELETE. Every attempt sends the same key, path and body. Pass
  `'maxRetries' => 0` to keep the old one-attempt behavior. If your code has its own retry
  loop, it now runs on top of these retries.
- The timeout is per attempt. With retries, one call can take up to
  `(maxRetries + 1) x timeout`, plus the waits between attempts. With the defaults that is
  up to about 137 seconds instead of 15.
- The default timeout is 45 seconds per attempt, up from 15, the same as the other Dominaite
  SDKs. A charge can take longer than 15 seconds at the card provider, and a first attempt
  cut off early makes the API refuse its same-key retry until that attempt finishes. Pass
  `'timeout' => 15` to keep the old limit.
- A 404 on `chargePaymentMethod()` (`PAYMENT_METHOD_NOT_FOUND`, `AGREEMENT_NOT_FOUND`) is now a
  `ChargeException`, not an `ApiException`. `ChargeException` does not extend
  `ApiException`, so a `catch (ApiException $e)` no longer sees it. Check the order of your
  `catch` blocks and your `instanceof` checks.
- A 5xx with a code the route does not own is a `TransportException` on every route, also
  on `chargePaymentMethod()` and `revokePaymentMethod()`. The exception keeps the code:
  `getHttpStatus()`, `getErrorCode()` and `getResult()` are new on `TransportException`.
- A `STOREFRONT_MISMATCH` refusal on a checkout replay is a `StorefrontException` with
  `getHttpStatus()` 200, not a `CheckoutRefusedException`.
- `getStatus()` returns `null` for every nullable field the API leaves out, also
  `orderReference`, `pspReference`, `refundedAmount`, `paymentMethod`, `walletType`,
  `declineClass`, `updatedAt` and `expiresAt`. `verifyOnly` reads `false` when absent. Code
  that tested `isset()` or `array_key_exists()` on these keys now sees them set.
- `REFUSAL_ERROR_CODES` gains the subscription session refusals, and `CHARGE_ERROR_CODES`
  lists every code the charge route answers. Code that relies on the old lists being short
  sees more codes.

Added:

- The request id. `getLastRequestId()` on the client, and `getRequestId()` on every SDK
  exception. The SDK reads the `Dominaite-Request-Id` header, then `metadata.requestId` in
  the body. The debug line prints that id, and `attempt=N` on a retry.
- The `maxRetries` option (int, 0 or more, default 2) and the `timeout` option (seconds per
  attempt, default 45).
- Error code lists: `AUTHENTICATION_ERROR_CODES`, `TRANSIENT_ERROR_CODES`,
  `FIELD_VALIDATION_CODES`, `RECURRING_ERROR_CODES`, `WEBHOOK_ERROR_CODES` and
  `MERCHANT_API_UNAVAILABLE`. Webhook event lists: `PAYMENT_WEBHOOK_EVENTS`,
  `RECURRING_WEBHOOK_EVENTS` and `VERIFICATION_WEBHOOK_EVENTS`. A test pins them to the wire
  contract fixture.

Fixed:

- The README said nothing was charged on a 409 `DUPLICATE_REQUEST` from
  `chargePaymentMethod()`. It means an earlier request with this key is still running, or
  it finished and its answer can no longer be replayed, so a charge may exist. Retry with
  the same key; if the code comes back, read the transaction. Never retry under a new key.
- `unserialize()` reset `maxRetries` and `timeout` to the defaults. A restored client now
  keeps the values you passed to the constructor.
- A `pm_` id with uppercase hex is lowercased before signing. The API signs a charge on the
  lowercase id, so an uppercase id failed with `INVALID_SIGNATURE`.
- The README and the `createWebhookEndpoint()` docblock no longer say a same-key retry always
  answers a new secret. After a timeout, retry with the same key. If that answer has no
  secret, delete that endpoint and create it again with a new key.

## 0.6.0

Breaking:

- The base URL is required. The client no longer falls back to production when you leave it
  out, so an unset environment variable cannot send sandbox credentials to production.
  Construction fails with `InvalidArgumentException` when the base URL is missing, empty or
  whitespace. Pass `DominaiteClient::SANDBOX_BASE_URL`
  (`https://api.staging.dominaite.com/payments`) or `DominaiteClient::PRODUCTION_BASE_URL`
  (`https://api.dominaite.com/payments`), or your own value.
- The private `DEFAULT_BASE_URL` is removed. Use `DominaiteClient::PRODUCTION_BASE_URL`.

Added:

- The User-Agent is now `dominaite-php/<version> (php <version>)`, then your suffix. The new
  fourth constructor argument takes options; `userAgent` sets the suffix. A control
  character in it, or an unknown option key, throws `InvalidArgumentException`.
- `DominaiteClient::VERSION`, the SDK version the User-Agent carries.
- The `logger` option: any object with `warning()`, for example a PSR-3 `LoggerInterface`
  (`psr/log` is suggested, not required). Without one, warnings go to
  `trigger_error(E_USER_DEPRECATED)`. A logger that throws never fails a request.
- The SDK reads the `Dominaite-Sdk-Notice` response header on every response and warns once
  per distinct value per client when this version is deprecated. It also warns when `ping()`
  finds a clock skew above 60 seconds.
- The `debug` option, off by default: one line per request with the method, the path, the
  status, the time, the `Dominaite-Request-Id` header and the idempotency key. No line holds
  the secret, the signature, the `cashierToken` or a body.

## 0.5.2

Added:

- Billing address on the payer: the `customer` keys `address`, `city`, `postalCode` and `state`,
  all optional. Address is one line, at most 200 characters; city at most 100; postal code at
  most 20. State is the state or province code, at most 50 characters, and only used for the
  United States, Canada and Australia. The widget does not ask the payer for a field that you
  send. A value that is too long fails with 400 `VALIDATION_ERROR`, code `MAX_LENGTH`.
  The SDK already sent `customer` as given; the docblock and the README now document the keys.

## 0.5.1

Added:

- `listTransactions()` items carry `fee`, `netAmount`, `settledAt` and `payoutId`. They are
  `null` until the acquirer settles the payment. `fee` and `netAmount` are in minor units.
  `netAmount` is `amount` minus the fee of this payment only.
- `listTransactions()` documents `settledFrom` and `settledTo`, a settlement-date window in
  dates only (`2026-10-13`). Send both or neither. `from` and `to` are still required.

## 0.5.0

Added:

- Verify-only sessions: `'verifyOnly' => true` on `createCheckoutSession()` saves the payer's
  card with no payment. Send `'amount' => 0` or leave the amount out; any other amount throws
  `InvalidArgumentException` before anything is sent. The API refuses the session with 403
  `VERIFY_ONLY_NOT_ENABLED` until we switch it on for your account. The session sends
  `verification.succeeded` or `verification.failed`, never a `payment.*` event.
- Subscription sessions: `createCheckoutSession()` documents `agreementId`, `consent` and
  `locationId`.
- Subscriptions: `createPlan()`, `listPlans()`, `getPlan()`, `updatePlan()` (PATCH) and
  `deletePlan()`, and `createAgreement()`, `getAgreement()` and `cancelAgreement()`.
  `createPlan()` and `createAgreement()` need an idempotency key. `updatePlan()` sends one only
  when you give one, because the API does not need it to update a plan.
- `listRefunds($transactionId)`: the refunds of one payment that you created through the API,
  and the `refundedAmount` and `refundableAmount` totals.
- Webhook endpoints from your code, with a `webhooks:manage` key: `createWebhookEndpoint()`,
  `listWebhookEndpoints()` and `deleteWebhookEndpoint()`. The create answers the `whsec_` secret
  one time only; a repeat with the same key answers `'secret' => null`. The SDK does not check
  event types.
- `listTransactions($params)` (`from`, `to`, `status`, `limit`, `cursor`), with a
  `transactions:read` key: one page of your payments, oldest first, and the `nextCursor` of the
  next page. The SDK signs the path without the query.
- `getIntegrationContract()`: the contract that the API publishes about itself, as sent.
- Response fields: `verifyOnly`, `declineClass`, `isRecurring`, `agreement` and `sequence` in
  the `getStatus()` shape, which reads an omitted `agreement.nextChargeAt` as null. `verifyOnly`
  and `agreementId` on the checkout session. `agreementId` and `sequence` on the charge, which
  reads an omitted `agreementId` as null. Every status, class and reason stays a plain string,
  so a value the SDK does not know never throws.
- `agreementId` on `chargePaymentMethod()`.
- Errors: `ApiException::getResult()` on every API error, the whole JSON answer, for example
  `error.params.webhookId` on 409 `WEBHOOK_ALREADY_REGISTERED`. `RateLimitException` carries the
  error code, so `getErrorCode()` names the limit you hit.
- Constants: `INSUFFICIENT_SCOPE`, `VERIFY_ONLY_NOT_ENABLED`, `RATE_LIMIT_EXCEEDED`,
  `CHARGE_VELOCITY_EXCEEDED` and `CHARGE_DECLINE_RATE_EXCEEDED`. `AUTHORIZATION_ERROR_CODES`
  lists the 403 authorization group, pinned against the wire contract. `PLANS_PATH`,
  `AGREEMENTS_PATH`, `WEBHOOKS_PATH`, `TRANSACTIONS_PATH` and `INTEGRATION_CONTRACT_PATH` name the
  new routes.
- Contract fixture: the new status read, checkout session and charge fields, with examples.
  Wire contract fixture: refreshed from the API, with the authorization error group.
- Wallet reporting: `paymentMethod` and `walletType` on `getStatus()`, both optional and
  nullable strings. `paymentMethod` is `card`, `wallet`, `bank_transfer` or `sepa`;
  `walletType` names the wallet when `paymentMethod` is `wallet`, and an unknown value is a
  valid wallet, not an error. Reporting data only: a wallet payment refunds, captures and
  disputes like a card. `PAYMENT_METHOD_CATEGORIES` and `WALLET_TYPES` list the known values.
- Contract fixtures: the two wallet fields in the status read fields and examples, and the
  gateway's `wallets` section in the wire contract.

Changed:

- A 403 no longer tells you to check your key id, secret and server clock. That hint is for 401
  only. 403 `INSUFFICIENT_SCOPE` says that the key does not hold the scope and repeats the API
  message, and 403 `VERIFY_ONLY_NOT_ENABLED` says that verify-only is not on for your account.
- `chargePaymentMethod()` and `createRefund()` send every field you give, not only the fields
  that the SDK models, so a new API field needs no SDK release. The idempotency key stays a
  header, and the amount and reason checks stay.
- `declineClass` can be null, for example when nobody declined the payment. The docs no longer
  promise a class on every decline.
- The README documents key scopes, verify-only sessions, subscriptions, the refund list, webhook
  endpoints, transactions and the integration contract.

Fixed:

- `getStatus()` reads an omitted `orderReference`, `refundedAmount`, `updatedAt` or `expiresAt`
  as null. The API leaves a null field out, so a paid session with no refund had no
  `refundedAmount` key. Reading it raised a PHP notice or warning, which some frameworks turn
  into an exception. The other reads already gave null for an omitted key that their shape
  always has, for example `amount` on a pending full refund.

## 0.4.0

Added:

- Card fields: `integration` on `createCheckoutSession()` (`INTEGRATION_WIDGET` or
  `INTEGRATION_FIELDS`, left out of the body when null or not set), and `integration` and
  `clientSecret` on the returned session. `clientSecret` is set only for fields sessions.
  `INTEGRATION_VOCABULARY` lists both values. Card fields are enabled per merchant on request;
  see the README.
- Processor reference: `pspReference` on `getStatus()` and on `payment.*` webhook `data`. The
  payment processor's reference for the transaction, null until known and on a sale settled by
  reconciliation without a processor webhook. Refund and cancel events carry the original sale's
  reference. Not on `charge.*` events.
- Contract fixture: `pspReference` in the status read fields and examples.
- Contract fixture: `integrationVocabulary`, the two new checkout fields and a fields session
  example.

## 0.3.1

Added:

- Webhook docs for the `2026-09-25` envelope: `apiVersion` on every event, the
  `agreement.*` and `charge.*` events, and ordering them by `data.sequence` per object.
  Additive on the wire: `verifyWebhook()` is unchanged and the canonical signature vector
  still verifies. Events recorded before these fields existed arrive without them.
- Refunds: `createRefund()` (POST, 202, required signed `idempotencyKey`, optional `amount`
  in minor units and `reason`; no amount refunds everything still refundable) and
  `getRefund()`. Both return `{refundId, transactionId, status, amount, currency,
  failureCode, failureMessage, completedAt}` with omitted nulls read as null.
  `REFUND_STATUS_VOCABULARY`, `REFUND_ERROR_CODES`, `REFUND_FAILURE_CODES` and named
  constants for the new codes, pinned against the contract fixture. The refund error codes
  raise `RefundException` (extends `ApiException`), whose `isRetryable()` is true for
  `REFUND_NOT_FOUND` and `DUPLICATE_REQUEST`. `REFUND_FAILED` is a `failureCode`, never an
  HTTP error. A failed refund fires no webhook: poll `getRefund()`.
- `data.storedPaymentMethod` on `payment.succeeded` and `payment.requires_capture`
  webhooks, documented: the same object as `storedPaymentMethod` on `getStatus()`, null on
  every other `payment.*` event. It can be null even when a card was saved; the
  status read is the source of truth.

## 0.3.0

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
  `STOREFRONT_ERROR_CODES` in the contract's order (`STOREFRONT_MISMATCH` 400,
  `STOREFRONT_INACTIVE` 409, `STOREFRONT_NOT_WHITELISTED` 409), pinned against its
  `storefrontErrorCodes`. None is retryable and none is a session refusal.
- `StorefrontException` (extends `ApiException`) for the 409/400 storefront refusals.
- `toMinorUnits()` and `minorUnitExponent()`: decimal string to integer minor units by the
  gateway's exponent (HUF is 0, not ISO's 2), no float math. ISK, KRW, OMR, JOD and TND
  throw as not supported.
- `isPaid()`, `isTerminal()` and `TERMINAL_STATUSES`.
- Stored payment methods (`saveCard`, `chargePaymentMethod()`, `revokePaymentMethod()`),
  which landed after 0.2.0 and were never tagged.
- Contract refresh (gateway contract 2026-09-16): a stored payment method can be `retired`, and
  carries `retiredReason` (`hard_decline`, `chargeback` or `source_sale_reversed`, null on every
  other card), listed in `STORED_PAYMENT_METHOD_RETIRED_REASON_VOCABULARY`. A retired card is
  not chargeable and never becomes active again.

## 0.2.0

Provider-neutral checkout path, webhook verification helpers, client hardening.
