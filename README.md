# dominaite/dominaite-php

Server-side PHP client for the Dominaite merchant API. One call from your backend opens a
hosted checkout session; a two-line script tag renders the payment widget on your page. Card
details go straight from your customer's browser into the payment widget - they never touch
your server, which keeps your PCI scope minimal (SAQ A).

Works on plain PHP 7.4+ with curl. No framework required.

The integration is three moving parts: create a session from your backend, render the widget,
and receive a webhook when the payment lands. Webhooks are how you learn the outcome; polling
is the fallback for when you have not set one up yet.

## Install

```bash
composer require dominaite/dominaite-php
```

PHP 7.4 or newer with `ext-curl`, `ext-json` and `ext-mbstring`. No other dependencies.

## Credentials

You get two values from Dominaite (shown once - store them like passwords):

- `dmk_...` - your API key id. Identifies you; not secret by itself.
- `dms_...` - your API secret. Server-side only: environment variable or config outside the
  web root. Never in a browser, never in git, never in logs.

Every request is signed with the secret (HMAC-SHA256) and timestamped. Keep your server
clock on NTP - signatures older than 5 minutes are rejected.

The third constructor argument overrides the base URL for non-production environments. It
has to be `https://`: the key id and the signature travel in headers, and a captured
signed request stays replayable for the whole 5 minute clock window. `http://` is accepted
only for `localhost`, `127.0.0.1` and `::1`, so a local mock still works. Anything else
throws `InvalidArgumentException` at construction rather than on your first live payment.

### Do not dump the client

The client holds your secret in memory. Do not `var_dump()`, `print_r()`, `var_export()`,
`(array)`-cast or `serialize()` a client as a way to inspect it, and do not let one reach a
log line or an error tracker.

`var_dump()`, `print_r()` and `serialize()` are redacted for you - the client implements
`__debugInfo()` and `__serialize()`, which Symfony VarDumper, Ignition and Whoops honour too,
so a client caught in an exception page does not print your secret. `json_encode()` returns
`{}` because the properties are private.

**`var_export()`, an `(array)` cast and Reflection are NOT redacted.** They honour no hook and
print the secret in full. There is no way for the SDK to intercept them, so this one is on you.

One more thing worth setting in production `php.ini`:

```ini
zend.exception_ignore_args = 1
```

Without it, a stack trace records the arguments each frame was called with, so an exception
thrown anywhere below `new DominaiteClient(...)` carries your plaintext secret into whatever
renders or ships that trace.

## Ping before your first session

One signed GET that creates nothing, so anything that fails here is your credentials, your
signing or your clock - not the payment:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Dominaite\DominaiteClient;

$client = new DominaiteClient(getenv('DOMINAITE_KEY_ID'), getenv('DOMINAITE_SECRET'));

print_r($client->ping());
// ['pong' => true, 'merchantId' => '...', 'serverTime' => '...', 'clockSkewSeconds' => 0]
```

Watch `clockSkewSeconds`: the gateway rejects requests once it passes 300, so a number that
keeps growing is your cue to fix NTP before payments start failing.

## Create a session (your `create_session.php`)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\RateLimitException;
use Dominaite\Exception\StorefrontException;
use Dominaite\Exception\TransportException;

$client = new DominaiteClient(getenv('DOMINAITE_KEY_ID'), getenv('DOMINAITE_SECRET'));

$amount = 2500;                              // minor units: 2500 = 25.00 EUR

try {
    $session = $client->createCheckoutSession([
        'amount'         => $amount,
        'currency'       => 'EUR',
        'orderReference' => 'order-1042',    // your own order id, shows up in your dashboard
        // Required. Same order + same amount = same key, so a reload or a retry gets the
        // session that is already open instead of a second payment.
        'idempotencyKey' => DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', $amount, 'EUR'),
        'customer'       => [
            // Pass everything you already know - prefilled fields are hidden from the
            // payer, so the checkout form stays short.
            'firstName' => 'Ana',
            'lastName'  => 'Kirova',
            'email'     => 'ana@example.com',
        ],
        'language'       => 'bg',            // widget UI language
        'theme'          => 'dark',
    ]);
} catch (CheckoutRefusedException $e) {
    // Machine-readable: $e->getErrorCode() - see the exception docblock for the codes.
    http_response_code(409);
    exit('Payment unavailable: ' . $e->getErrorCode());
} catch (StorefrontException $e) {
    // This website cannot take payments yet (or any more). Not retryable - see below.
    error_log('Dominaite storefront refusal: ' . $e->getErrorCode());
    http_response_code(503);
    exit('Online payments are not available on this site yet');
} catch (RateLimitException $e) {
    // You are over the rate limit. Nothing is retried for you - back off first.
    http_response_code(503);
    header('Retry-After: ' . ($e->getRetryAfterSeconds() ?? 5));
    exit('Payment temporarily unavailable');
} catch (TransportException $e) {
    // Network blip or a 5xx - retry with the same params, so the same key, never a fresh one.
    http_response_code(503);
    exit('Payment temporarily unavailable');
}

// Store $session['transactionId'] against your order, then render the widget:
?>
<div id="checkout"></div>
<script src="https://bp-checkout.dominaite.com/v2/launcher"
        data-cashier-key="<?= htmlspecialchars($session['cashierKey']) ?>"
        data-cashier-token="<?= htmlspecialchars($session['cashierToken']) ?>"></script>
```

`orderReference` is limited to 100 characters, counted as characters and not as bytes - a
100 character Cyrillic or Greek reference is 200 bytes and is fine. Emoji and rarer CJK
characters count double on the server, so stay a couple of characters clear of the limit if
your references contain them; the server has the final say either way.

### Storefront errors

If your merchant account has more than one website, each one is a storefront, and a
session is refused when its storefront cannot take payments. These come back as HTTP 4xx
and the SDK raises `StorefrontException` (an `ApiException`, so older catch blocks still see
it). Branch on `getErrorCode()`, using the constants on `DominaiteClient`:

| Code | HTTP | Meaning | What to do |
|------|------|---------|------------|
| `STOREFRONT_NOT_WHITELISTED` | 409 | The site's domain is not yet whitelisted with the payment provider. | Nothing to retry. Ask Dominaite to finish onboarding the domain. |
| `STOREFRONT_INACTIVE` | 409 | The storefront was deactivated or deleted. | Nothing to retry. Check the location in your dashboard. |
| `STOREFRONT_MISMATCH` | 400 | The API key belongs to another storefront than the request names. | Use the key issued for this site. |

Replaying a key that was first used for a different storefront answers `STOREFRONT_MISMATCH`
as an HTTP 200 refusal, so that one case arrives as `CheckoutRefusedException`.

That's the checkout half: the session call above, the script tag, and your domain bound to
your checkout by Dominaite during onboarding. The other half is the webhook that tells you
the payment happened.

## Webhooks

Register an endpoint in the dashboard (Developers, Webhooks): an HTTPS URL, the events you
want, and you get a signing secret `whsec_...` shown exactly once. Store it like the API
secret. Regenerating it kills the old one.

### Receiving a delivery (your `webhook.php`)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Dominaite\DominaiteClient;

// The RAW body, before any parsing. Do not use $_POST and do not re-encode:
// the signature covers these exact bytes.
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

if (!DominaiteClient::verifyWebhook($payload, $signature, getenv('DOMINAITE_WEBHOOK_SECRET'))) {
    http_response_code(400);
    exit;
}

$event = json_decode($payload, true);

// Answer immediately, then do the work. Anything slow here eats into the delivery
// timeout and earns you a retry you did not need.
http_response_code(200);

if (!already_handled($event['id'])) {          // dedupe on the delivery id
    queue_fulfilment($event);                  // your own job queue
    mark_handled($event['id']);
}
```

`verifyWebhook($payload, $signatureHeader, $secret, $toleranceSeconds = 300, $now = null)`
returns `true` or `false`. It returns `false` for everything the sender controls: a bad
signature, a body that changed by one byte, a stale timestamp, a missing or garbled header.
It throws `InvalidArgumentException` only for your own mistakes, an empty secret or a
negative tolerance. Verify before you parse, always.

The header is `X-Webhook-Signature: t=<unix seconds>,v1=<hex>`, where `v1` is HMAC-SHA256
over `"{t}.{raw body}"` keyed with your endpoint secret. The timestamp is inside the MAC and
checked against a 300 second window, so a captured delivery cannot be replayed later.

### What arrives

```json
{
  "id": "delivery id, your dedupe key",
  "type": "payment.succeeded",
  "createdAt": "2026-08-20T14:00:00Z",
  "data": {
    "transactionId": "...",
    "status": "succeeded",
    "previousStatus": "pending",
    "kind": "sale",
    "amount": 8440,
    "grossAmount": 8701,
    "surchargeAmount": 261,
    "currency": "EUR",
    "originalTransactionId": null,
    "idempotencyKey": "order-123"
  }
}
```

Flat envelope, no `success` wrapper to branch on. Amounts are minor units: `amount` is what
you get paid, `grossAmount` is what moved on the card, `surchargeAmount` is the difference
when a surcharge applies.

Events: `payment.succeeded`, `payment.failed`, `payment.requires_capture`,
`payment.cancelled`, `payment.abandoned`, `payment.refunded`, `payment.disputed`.
`payment.succeeded` is the only one that means money in hand. In-flight states (`pending`,
`processing`) are not webhooked, so drive that part of your UX from the session status.

### Delivery guarantees

- At least once. The same delivery can arrive twice, so dedupe on `id` and make your
  handler idempotent.
- Respond 2xx fast and queue the work. Never fulfil the order inline in the request.
- Failed attempts retry up to your configured count (3 by default, 10 max), spaced 1m, 5m,
  30m, 2h, 12h.
- An endpoint that fails its initial attempt and every retry in a row gets disabled
  automatically, and re-enables itself on the next successful delivery. An endpoint you
  disable by hand stays disabled.
- Up to 25 active endpoints per merchant.

### Reconcile anyway

Webhooks complement a reconciliation sweep, they do not replace it. Keep a job that walks
your open orders and calls `getStatus()` on anything past its expected settle time. There
are windows where a delivery never lands: a chain parked on a disabled endpoint, or an
outage on our side between the payment and the publish. The sweep is what closes them, and
it is not optional if you care about your books.

## Amounts are minor units

`amount` is always an integer in the currency's minor unit, which depends on the currency's
ISO 4217 exponent. EUR has two decimals, so `2500` is 25.00 EUR. JPY has none, so `1000` is
1000 JPY, not 10.00. KWD has three, so `1250` is 1.250 KWD. The amount is locked
server-side - what you pass here is what gets charged; nothing in the browser can change it.

If your shop stores prices as decimal strings, convert with `toMinorUnits()` rather than
multiplying a float (`(int) (0.3 * 100)` is 29):

```php
DominaiteClient::toMinorUnits('0.30', 'EUR');   // 30
DominaiteClient::toMinorUnits('1000', 'JPY');   // 1000
DominaiteClient::toMinorUnits('1.250', 'KWD');  // 1250
DominaiteClient::toMinorUnits('25.505', 'EUR'); // throws: EUR has 2 decimal places
```

It takes a string, parses it without floats, and throws `InvalidArgumentException` for more
decimals than the currency has, for anything that is not plain digits with an optional dot,
and for a currency it does not know. Known today: EUR, USD, GBP, BGN, RON, CHF, PLN, CZK,
HUF, SEK, DKK, NOK (two decimals), JPY, KRW, ISK (none), BHD, KWD, OMR, JOD, TND (three).
`minorUnitExponent($currency)` returns the exponent on its own.

## Retries and double-charges

`idempotencyKey` is required on `createCheckoutSession()` and `chargePaymentMethod()`. Leave
it out and the SDK throws `InvalidArgumentException` before anything is sent. It used to
generate a random key for you; that is gone, because a random key per request turns every
page reload, back button or retried timeout into a second payment for the same order.

Derive the key from the order instead:

```php
$key = DominaiteClient::orderIdempotencyKey('checkout', $order->id, $amountMinor, $currency);
// "checkout-1042-2500-EUR"
```

The shape is `{scope}-{orderId}-{amountMinor}-{CURRENCY}`. The same order at the same amount
always gives the same key, so a reload or a retry replays the session that is already open
(same `transactionId`, same `cashierKey` and `cashierToken`) instead of opening a new one.
Change the amount or the currency and you get a new key, which is what you want: the gateway
refuses a known key sent with a different amount (`IDEMPOTENCY_KEY_REUSED`). `scope` keeps
two flows for one order apart (`checkout` and `renewal`, say); keep it a fixed string per
flow. The result follows the usual key rules (at most 100 printable ASCII characters), and
the helper throws if it would not.

On a timeout, retry with the same params and so the same key:

```php
$params['idempotencyKey'] = DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 2500, 'EUR');
$session = null;

for ($attempt = 1; $attempt <= 3 && $session === null; $attempt++) {
    try {
        $session = $client->createCheckoutSession($params);
    } catch (TransportException $e) {
        if ($attempt === 3) {
            throw $e;
        }
        sleep($attempt);
    }
}
```

`getLastIdempotencyKey()` still reads back the key of the last attempt that went out, for a
generic catch block that no longer has the params.

A replay only hands back the original session while that session is open and unexpired. If
the first attempt already completed, failed, or was sent with a different amount, the retry
answers HTTP 200 with `success=false` and a replay code (`DUPLICATE_REQUEST`,
`ALREADY_PROCESSED`, `PRIOR_ATTEMPT_FAILED` or `IDEMPOTENCY_KEY_REUSED`), which the SDK raises
as a `CheckoutRefusedException` carrying the transaction id to reconcile against; see
"Recovering from a replay refusal" below. Treat it as "go look up what the first attempt did",
not as an error to show the payer.

## Rate limits

60 requests per minute per API key, 120 per minute per source IP. The per-IP bucket is the
one that surprises people: several keys behind one egress address share it, so a key well
under 60/min can still be limited.

Over the limit the API answers HTTP 429 and the SDK raises `RateLimitException`. It is not
retried for you - a loop against a rate limiter just spends the next window too, and on
`createCheckoutSession()` retrying is a decision about a payment that belongs in your code.
`getRetryAfterSeconds()` gives the server's own number of seconds to wait, or `null` when it
did not send a usable one; treat `null` as "use your own backoff", not "retry now". When you
do retry, reuse the idempotency key.

`RateLimitException` extends `ApiException`, so code written before it existed still catches
a 429. Catch `RateLimitException` first if you want to branch on it.

The polling loop in "Fallback: status polling" is the usual way to hit this. Poll one
transaction every few seconds, not every transaction every second.

## Response size

Responses are read up to 10 MB and no further. A real merchant-API response is a few
kilobytes, so anything near that is a captive portal or an edge serving something that is
not us, and reading it to the end would grow a worker's memory for no reason. An oversized
response surfaces as `TransportException` - retryable, same as any other transport failure.

## Sessions expire

A session is valid for 2 hours. If the payer comes back later, create a new session - and
re-POST with the same order-derived idempotency key, not a fresh one: from a few minutes
past expiry the same key answers with a fresh session (see "Recovering from a replay
refusal").

## Stored payment methods (recurring)

Pass `'saveCard' => true` when you create a session and, once that payment is approved, the
gateway keeps the card on file. You never see the card number or the provider token:
`getStatus()` returns a `storedPaymentMethod` with an opaque `id` (`pm_` + 32 hex characters),
the `brand`, the `last4` and the expiry, and that `id` is what you charge and revoke with. Store
it against your customer. (`paymentMethod` on the same status is something else: the gateway's
string category of how the payer paid, `card`, `wallet` and so on.)

```php
use Dominaite\Exception\ChargeException;
use Dominaite\Exception\RevokeException;

$session = $client->createCheckoutSession([
    'amount'         => 2500,
    'currency'       => 'EUR',
    'orderReference' => 'sub-8817-first',
    'idempotencyKey' => DominaiteClient::orderIdempotencyKey('checkout', 'sub-8817-first', 2500, 'EUR'),
    'saveCard'       => true,
]);
// ... the payer completes the hosted checkout ...
$status = $client->getStatus($session['transactionId']);
$stored = $status['storedPaymentMethod'] ?? null; // absent until the payment is approved
if ($status['status'] === 'succeeded' && ($stored['status'] ?? null) === 'active') {
    $db->saveCard($customerId, $stored['id']); // pm_...
}

// Later, off-session, no payer present:
try {
    $charge = $client->chargePaymentMethod($paymentMethodId, [
        'amount'         => 2500,
        'currency'       => 'EUR',
        'orderReference' => 'sub-8817-2026-10',
        'description'    => 'Monthly plan, October',
        // Derived from the billing period, never random per attempt.
        'idempotencyKey' => DominaiteClient::orderIdempotencyKey('renewal', 'sub-8817-2026-10', 2500, 'EUR'),
    ]);

    switch ($charge['status']) {
        case 'succeeded':
            break;
        case 'pending':
            // Not terminal. Poll getStatus($charge['transactionId']), or wait for the webhook.
            break;
        case 'failed':
            // HTTP 402 from the gateway, but not an exception: branch on the class, log the code.
            // hard              - give up on this card, ask the customer for another one
            // soft_funds        - insufficient funds, retry later (not in a loop)
            // soft_sca_required - the issuer wants the customer present: send them through a
            //                     hosted session with saveCard and charge the new method
            // soft_other        - transient, one retry later is reasonable
            handleDecline($charge['declineClass'], $charge['declineCode']);
            break;
        case 'cancelled':
            // An authorization voided before capture; no money moved.
            break;
    }
} catch (ChargeException $e) {
    switch ($e->getErrorCode()) {
        case 'CHARGE_OUTCOME_UNKNOWN':
            // 502: the provider gave no verdict, the charge MAY have happened. Never retry
            // under a new key: poll the transaction the gateway attached instead.
            pollUntilSettled($e->getTransactionId());
            break;
        case 'DUPLICATE_REQUEST':
        case 'PAYMENT_METHOD_CHARGES_DISABLED':
        case 'PAYMENT_PROCESSING_UNAVAILABLE':
            // Nothing was charged; retry later with the SAME idempotency key.
            break;
        case 'PAYMENT_METHOD_NOT_ACTIVE':
            // Revoked or expired: bring the customer back for a hosted session with saveCard.
            break;
        case 'CHARGE_FAILED':
            // 502, nothing was charged. getCharge() is set when a row exists.
            break;
        case 'IDEMPOTENCY_KEY_REUSED':
            // Same key, different body or method: a bug on your side.
            break;
    }
}

// When the customer removes the card:
try {
    $client->revokePaymentMethod($paymentMethodId); // 204, returns nothing; 204 again if already revoked
} catch (RevokeException $e) {
    if ($e->getErrorCode() === 'MERCHANT_API_UNAVAILABLE') {
        // 503: nothing changed, retry later.
    } else {
        // 502 UPSTREAM_CONTRACT_ERROR: the provider refused for good, nothing changed. Contact support with the id.
    }
}
```

A charge is signed exactly like a session and carries a required `Idempotency-Key`, so a
retry after a timeout with the **same** key never charges the card twice: the gateway replays
its first answer, HTTP status included. `getLastIdempotencyKey()` reads the key back the same
way it does for a session. The HTTP status is the contract on this route: 201 (or 200 on a replay) returns
the charge, 402 returns the charge too (`status` `failed` plus `declineClass`), and 409, 422,
502 and 503 throw `ChargeException` with `getErrorCode()`, `getHttpStatus()`, the gateway's
message and, when the gateway attached the charge row, `getCharge()` and `getTransactionId()`.
Only authentication (401/403), an id that is not yours (404, `ApiException` with
`getErrorCode()` `PAYMENT_METHOD_NOT_FOUND`), validation (400, `ApiException`), rate limiting
(429) and network failures keep their generic exceptions. `declineClass` and `declineCode` are
`null` unless the charge was declined; the gateway omits them on the wire and the SDK reads
absent as null.

Revoking signs an empty key and an empty body, like `getStatus()`. A revoke that fails with
`RevokeException` changed nothing: `MERCHANT_API_UNAVAILABLE` (503) is retryable,
`UPSTREAM_CONTRACT_ERROR` (502) is not. After a revoke the status read keeps the
`storedPaymentMethod` with `status` `revoked`, and a charge against it is refused with
`PAYMENT_METHOD_NOT_ACTIVE`. An id that is not yours is an `ApiException` with
`getHttpStatus()` 404.

## Fallback: status polling

Use this when you have not registered a webhook endpoint yet, inside your reconciliation
sweep, or any time you need the current state of one specific payment on demand.

```php
$status = $client->getStatus($session['transactionId']);
// ['transactionId' => ..., 'orderReference' => 'order-1042', 'status' => 'succeeded',
//  'amount' => 2500, 'currency' => 'EUR', ...]
```

`status` is one of: `pending`, `processing`, `succeeded`, `failed`, `refunded`,
`partially_refunded`, `cancelled`, `disputed`, `requires_capture`, `abandoned`. While the
session is still payable the response also carries `expiresAt`; after that instant a `pending`
session can only become `abandoned`. An unknown transaction id throws an `ApiException` with
HTTP 404.

`succeeded` is the only value that means the payment is complete. Keep polling on `pending`,
`processing` and `requires_capture` - none of them is terminal.

`requires_capture` is **not** "unpaid": the payer has already paid and the funds are held
awaiting capture. Never treat it as an abandoned order.

Treat any status you do not recognise as still-open as well: a value the API adds later should
make you keep polling, never silently close an order that is still live.

Poll after the payer returns to you, or on your order timeout - not in a tight loop; the
endpoint is rate limited per key.

### Recovering from a replay refusal

When your idempotency key collides with an earlier attempt, the refusal names the transaction
it collided with, so you can reconcile instead of minting a second payment:

```php
try {
    $session = $client->createCheckoutSession($params);
} catch (CheckoutRefusedException $refusal) {
    if ($refusal->getTransactionId() !== null) {
        $status = $client->getStatus($refusal->getTransactionId());
        // Now you know what the earlier attempt actually did.
    }
}
```

`getTransactionId()` is `null` when the API did not name one (a concurrent-race
`DUPLICATE_REQUEST` knows the key is taken but not yet by which row), so check it before use.
`DUPLICATE_REQUEST` means a session for this key is open, or expired within the last few
minutes: re-POST the same key shortly, never a fresh one. The full refusal payload is on
`getResult()`.

One replay is not a refusal at all. A session that expired unpaid is superseded: from a few
minutes past its expiry, re-POSTing the same key returns an ordinary success with a fresh
session (new transaction id, same key), so a customer who comes back late just pays. Keep
the order-derived key for the life of the order to keep that path open. The band is not
endless - once the platform has independently closed the attempt (about an hour past
expiry), the replay answers `PRIOR_ATTEMPT_FAILED` and the key is spent; reconcile and use
a fresh key.
