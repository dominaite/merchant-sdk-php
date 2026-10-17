# dominaite/merchant-sdk

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
composer require dominaite/merchant-sdk
```

PHP 7.4 or newer with `ext-curl`, `ext-json` and `ext-mbstring`. No other dependencies.

## Credentials

You get two values from Dominaite (shown once - store them like passwords):

- `dmk_...` - your API key id. Identifies you; not secret by itself.
- `dms_...` - your API secret. Server-side only: environment variable or config outside the
  web root. Never in a browser, never in git, never in logs.

Every request is signed with the secret (HMAC-SHA256) and timestamped. Keep your server
clock on NTP - signatures older than 5 minutes are rejected.

### Base URL

The third constructor argument is the base URL. It is required: there is no default.

| Environment | Base URL | Constant |
|-------------|----------|----------|
| Sandbox | `https://api.staging.dominaite.com/payments` | `DominaiteClient::SANDBOX_BASE_URL` |
| Production | `https://api.dominaite.com/payments` | `DominaiteClient::PRODUCTION_BASE_URL` |

The examples below read it from `DOMINAITE_BASE_URL`:

```bash
export DOMINAITE_BASE_URL=https://api.staging.dominaite.com/payments
```

A missing, empty or whitespace-only base URL throws `InvalidArgumentException` at
construction. An unset environment variable therefore fails loudly instead of sending your
sandbox key to production.

The base URL has to be `https://`: the key id and the signature travel in headers, and a
captured signed request stays replayable for the whole 5 minute clock window. `http://` is
accepted only for `localhost`, `127.0.0.1` and `::1`, so a local mock still works. Anything
else throws `InvalidArgumentException` at construction rather than on your first live payment.

The private `DEFAULT_BASE_URL` constant is removed in 0.6.0. Use
`DominaiteClient::PRODUCTION_BASE_URL`.

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

### Scopes: a payments key and a reporting key

Each key holds scopes. The scopes decide which routes the key can call:

- `payments`: sessions and their status, charges and revokes, refunds, plans and agreements.
  A key issued before scopes existed holds this scope.
- `transactions:read`: `listTransactions()`.
- `webhooks:manage`: `createWebhookEndpoint()`, `listWebhookEndpoints()` and
  `deleteWebhookEndpoint()`.

A `payments` key never holds the other two scopes. A payments key always gets HTTP 403
`INSUFFICIENT_SCOPE` on the methods of those scopes. Use a separate reporting key for them, in
a second client. If your dashboard has no reporting key section, ask us (Dominaite support)
for the key. A reporting key covers your whole account. We cannot bind it to a storefront or
a location. The scopes of a key never change: for other scopes you need a new key. Every key
can call `ping()` and `getIntegrationContract()`.

A key without the scope of a route gets HTTP 403 `INSUFFICIENT_SCOPE`, and the SDK throws
`AuthenticationException`. Its message says that the scope is the problem. The signature was
valid, so your key id, secret and clock are not the cause. Only a 401 points at those three.

## What we switch on for your account

These features work only after we switch them on for your account: ask us (Dominaite support).

- Stored-card charges: `chargePaymentMethod()`. Without this switch, the API answers HTTP 503
  `PAYMENT_METHOD_CHARGES_DISABLED`.
- Subscription sessions. Without this switch, a session with `agreementId` answers
  `success=false` with `SUBSCRIPTIONS_NOT_ENABLED`.
- Scheduled charges. Without this switch, the platform runs no scheduled charge and no retry,
  even when the subscription session works.
- Verify-only: a session with `'verifyOnly' => true`. Without this switch, the API answers
  HTTP 403 `VERIFY_ONLY_NOT_ENABLED`.
- Card fields: enabled per merchant on request (see "Card fields").

The webhook endpoint methods and `listTransactions()` need a second key, the reporting key (see
"Scopes" above). A payments key always gets HTTP 403 `INSUFFICIENT_SCOPE` on these methods.

## Ping before your first session

One signed GET that creates nothing, so anything that fails here is your credentials, your
signing or your clock - not the payment:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Dominaite\DominaiteClient;

$client = new DominaiteClient(getenv('DOMINAITE_KEY_ID'), getenv('DOMINAITE_SECRET'), getenv('DOMINAITE_BASE_URL'));

print_r($client->ping());
// ['pong' => true, 'merchantId' => '...', 'serverTime' => '...', 'clockSkewSeconds' => 0]
```

Watch `clockSkewSeconds`: the gateway rejects requests once it passes 300, so a number that
keeps growing is your cue to fix NTP before payments start failing.

## Client options

The fourth constructor argument is an array of options. All are optional. An unknown key
throws `InvalidArgumentException`.

| Option | What |
|--------|------|
| `userAgent` | Your own identifier, for example `'shop/1.2'`. The SDK sends `dominaite-php/<version> (php <version>)`, then one space and this value. It helps support find your calls. A control character throws `InvalidArgumentException`. |
| `logger` | Receives the SDK's warnings, see [Logging and warnings](#logging-and-warnings). |
| `debug` | `true` writes one line per request. Off by default. |
| `maxRetries` | How many times the client sends a failed request again, with the same idempotency key. Default `2`. `0` sends every request once. See [Automatic retries](#automatic-retries). |
| `timeout` | The limit in seconds for one attempt, an int or a float above 0. Default `15`. With retries, one call can take up to `(maxRetries + 1) x timeout`, plus the waits between attempts. |

```php
$client = new DominaiteClient(
    getenv('DOMINAITE_KEY_ID'),
    getenv('DOMINAITE_SECRET'),
    getenv('DOMINAITE_BASE_URL'),
    ['userAgent' => 'shop/1.2', 'logger' => $logger]
);
```

## Logging and warnings

The SDK warns you about two things:

- The API sent a `Dominaite-Sdk-Notice` header: this SDK version is deprecated. The warning
  names the current version, the sunset date and a link when the API sends them. The SDK
  warns once per distinct notice per client. The SDK reads the header on every response,
  errors included.
- `ping()` found a clock skew of more than 60 seconds. Requests fail at 300 seconds.

The `logger` option takes any object with `warning(string $message, array $context = [])`,
so a PSR-3 `LoggerInterface` fits. The SDK does not require `psr/log`. Without a logger,
warnings go to `trigger_error($message, E_USER_DEPRECATED)`. Frameworks log a deprecation and
do not throw on it. If your logger or your error handler throws, the SDK drops the error:
a warning never fails a request.

With `'debug' => true`, the SDK writes one line per request to the logger's `debug()`, or to
`error_log()` without a logger:

```
dominaite-php: POST /merchant-api/checkout/sessions 201 184ms request_id=4f1c... idempotency_key=checkout-order-1042-2500-EUR
```

The line holds the method, the path without the query string, the status (`error` when the
request did not complete), the time, the request id and the idempotency key you sent. A
retried attempt adds `attempt=2`, `attempt=3` and so on, one line per attempt. Give support
the request id, see [Request id](#request-id). No line holds your secret, the signature,
the `cashierToken` or a request or response body.

## Read the integration contract

`getIntegrationContract()` returns the public integration contract of the API: the routes,
the fields, the status values and the error codes as the API uses them now. Every key can
read it, and it creates nothing.

```php
$contract = $client->getIntegrationContract();
echo $contract['version'];
```

The SDK itself never reads the contract at runtime. Use it to check your own integration, for
example in a test that fails when a value you depend on changes.

## Create a session (your `create_session.php`)

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Dominaite\DominaiteClient;
use Dominaite\Exception\CheckoutRefusedException;
use Dominaite\Exception\RateLimitException;
use Dominaite\Exception\StorefrontException;
use Dominaite\Exception\TransportException;

$client = new DominaiteClient(getenv('DOMINAITE_KEY_ID'), getenv('DOMINAITE_SECRET'), getenv('DOMINAITE_BASE_URL'));

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
            // Billing address, all optional. The checkout asks for these only when
            // the payment processor needs them, and never for one you send.
            'address'    => '12 Vitosha Blvd',  // one line, up to 200 characters
            'city'       => 'Sofia',            // up to 100 characters
            'postalCode' => '1000',             // up to 20 characters
            // 'state'   => 'CA',               // up to 50; only for the US, Canada and Australia
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
    // Network blip or a 5xx, still failing after the client's own retries. Retry later
    // with the same params, so the same key, never a fresh one.
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
as an HTTP 200 refusal. It arrives as `StorefrontException` too, with `getHttpStatus()` 200
and the refusal (with the `transactionId` it collided with) on `getResult()`.

That's the checkout half: the session call above, the script tag, and your domain bound to
your checkout by Dominaite during onboarding. The other half is the webhook that tells you
the payment happened.

## Webhooks

Register an endpoint in the dashboard (Developers, Webhooks): an HTTPS URL, the events you
want, and you get a signing secret `whsec_...` shown exactly once. Store it like the API
secret. Regenerating it kills the old one. The dashboard page needs no API key.

### Manage endpoints from your server

You can also manage endpoints through the API. These calls need a reporting key with the
`webhooks:manage` scope (see "Scopes" above).

```php
$reporting = new DominaiteClient(
    getenv('DOMINAITE_REPORTING_KEY_ID'),
    getenv('DOMINAITE_REPORTING_SECRET'),
    getenv('DOMINAITE_BASE_URL')
);

$endpoint = $reporting->createWebhookEndpoint([
    'url'            => 'https://shop.example/webhook.php',
    'events'         => ['payment.succeeded', 'payment.failed'],
    'idempotencyKey' => 'endpoint-shop-main',
]);
$vault->store('whsec', $endpoint['secret']); // in this first answer only

foreach ($reporting->listWebhookEndpoints() as $item) {
    // id, url, events, status (active or inactive), createdAt. Never the secret.
}

$reporting->deleteWebhookEndpoint($endpoint['id']);
```

- The secret is in the first answer only. On a replay of the same key `secret` is `null`, and
  the list never has it. If you lose it, delete the endpoint and create it again.
- The client never retries `createWebhookEndpoint()` on its own, whatever `maxRetries` says.
  After a timeout or a 503, retry it yourself with the same key. If that answer has no
  secret (`null`), delete that endpoint and create it again with a new key.
- The SDK does not check event types. The API refuses an unknown type with
  `VALIDATION_ERROR`.
- A key used again more than a day later, while its endpoint still exists, answers 409
  `WEBHOOK_ALREADY_REGISTERED`. `$e->getResult()['error']['params']['webhookId']` is that
  endpoint. Use a fresh key for a new endpoint.
- Deleting an unknown or deleted endpoint answers 404 `WEBHOOK_NOT_FOUND`. When the answer to
  a delete was lost, treat that 404 as done.

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
  "apiVersion": "2026-09-25",
  "createdAt": "2026-08-20T14:00:00Z",
  "data": {
    "transactionId": "...",
    "orderReference": "order-123",
    "orderId": "dom_9a8b7c6d5e4f",
    "pspReference": "psp_8f3a21c4",
    "description": "Pro plan",
    "status": "succeeded",
    "previousStatus": "pending",
    "kind": "sale",
    "amount": 8440,
    "grossAmount": 8701,
    "surchargeAmount": 261,
    "currency": "EUR",
    "paymentMethod": "card",
    "walletType": null,
    "paymentMethodBrand": "visa",
    "paymentMethodLast4": "4242",
    "originalTransactionId": null,
    "idempotencyKey": "order-123",
    "storedPaymentMethod": null,
    "verifyOnly": false,
    "declineClass": null
  }
}
```

Flat envelope, no `success` wrapper to branch on. Amounts are minor units: `amount` is what
you get paid, `grossAmount` is what moved on the card, `surchargeAmount` is the difference
when a surcharge applies.

`orderReference` is your own order reference from create session and the field to match
events to your orders on. It is null when the payment did not start through the API or the
session had none, and refund and cancel events carry the original payment's value. `orderId` is
the hosted checkout order id, the same as on `getStatus()`, null on refunds, cancellations and
payments taken outside hosted checkout. `description` is what you sent on create session, null
when you sent none and on refund and cancel events. `paymentMethodBrand` (lower-cased, `visa`,
`mastercard`, ...) and `paymentMethodLast4` are set once a card payment was attempted and null
otherwise. `idempotencyKey` is the key you sent on create session, null on refund and dispute
events. `originalTransactionId` points at the earlier transaction: the refunded payment on
`payment.refunded`, the authorization on the `payment.succeeded` of a capture. The event is a
plain array, so read any of these with `?? null`: an event recorded before a field existed
arrives without it.

`apiVersion` is the date of the payload shape the event was rendered in, currently
`2026-09-25`. Fields are only ever added under a version, never renamed or removed, so
ignore fields you do not recognise. A retry resends the bytes of the first attempt, so an
event recorded before the field existed arrives without it: read it as
`$event['apiVersion'] ?? null`. The signature covers the raw body whatever it carries,
so nothing changes in how you verify.

Events: `payment.succeeded`, `payment.failed`, `payment.requires_capture`,
`payment.cancelled`, `payment.abandoned`, `payment.refunded`, `payment.disputed`
(`DominaiteClient::PAYMENT_WEBHOOK_EVENTS`). The subscription events are in
`RECURRING_WEBHOOK_EVENTS` and the verify-only ones in `VERIFICATION_WEBHOOK_EVENTS`.
`payment.succeeded` is the only one that means money in hand. In-flight states (`pending`,
`processing`) are not webhooked, so drive that part of your UX from the session status.

### Processor reference on payment events

`payment.*` events carry `data.pspReference`, the payment processor's reference for the
transaction, and `getStatus()` returns the same value as `pspReference`. It is null until
the reference is known, and a sale settled by reconciliation without a processor webhook can
keep it null. Refund and cancel events carry the original sale's reference. `charge.*`
events do not have it. Older gateways leave the key out, so read it as
`$event['data']['pspReference'] ?? null`.

### Saved card on payment events

`payment.succeeded` and `payment.requires_capture` carry `data.storedPaymentMethod` when the
payment saved a card (`saveCard`): the same object `getStatus()` returns as
`storedPaymentMethod`, with the same fields and values. On every other `payment.*` event it
is null.

```json
"storedPaymentMethod": {
  "id": "pm_0a1b2c3d4e5f60718293a4b5c6d7e8f9",
  "brand": "visa",
  "last4": "4242",
  "expiryMonth": 12,
  "expiryYear": 2030,
  "status": "active",
  "retiredReason": null
}
```

`status` is `active`, `revoked`, `expired` or `retired`; `retiredReason` is `hard_decline`,
`chargeback`, `source_sale_reversed` or null. Webhook nulls arrive as an explicit `null`,
but events recorded before the field existed do not carry it at all, so read it as
`$event['data']['storedPaymentMethod'] ?? null`.

> It can also be null when a card WAS saved: on a server-to-server sale approved
> synchronously, on a sale whose outcome the platform confirmed later, and whenever the card
> was stored after the approval was announced. `getStatus()` is the source of truth. On a
> `saveCard` payment whose event has no card, read the status to pick it up.

### Agreement and charge events: order by sequence

`agreement.activated`, `agreement.past_due`, `agreement.cancelled`, `charge.succeeded`,
`charge.failed` and `charge.retrying` ride the same signed envelope. Their `data` carries an
integer `sequence` that counts the announced changes of one object. It only ever rises, and
a redelivery carries the same number.

> Deliveries can arrive out of order. Keep the highest sequence you have processed per
> object and discard any event whose sequence is not higher; when you need current state,
> read the object by id. createdAt can repeat across events, so order by sequence, not
> createdAt. A sequence of 0 only comes from events recorded before the counter existed;
> treat it as older than any positive number.

The object is:

- `agreement.*`: the agreement, `data.id`.
- `charge.*` the platform placed for an agreement: the agreement period, `data.agreementId`
  plus `data.periodNumber`, so the attempts of one period compare with each other.
- `charge.*` for a one-off `chargePaymentMethod()`: `data.chargeId` (`agreementId` is null).

```php
$data = $event['data'];
if (strpos($event['type'], 'agreement.') === 0) {
    $object = 'agreement:' . $data['id'];
} elseif ($data['agreementId'] !== null) {
    $object = 'period:' . $data['agreementId'] . ':' . $data['periodNumber'];
} else {
    $object = 'charge:' . $data['chargeId'];
}

$sequence = $data['sequence'] ?? 0;           // absent on events recorded before the counter
$highest = $store->highestSequence($object);  // null when you have not seen this object yet
if ($highest !== null && $sequence <= $highest) {
    return;                                   // stale or repeated: newer state is already applied
}
apply_event($event);
$store->saveHighestSequence($object, $sequence);
```

Keep deduping on the envelope `id` as well: that catches the same delivery twice, the
sequence catches an older delivery arriving after a newer one. `payment.*` events carry no
sequence.

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

`amount` is always an integer in the currency's minor unit, which depends on how many
decimals the gateway counts for that currency. EUR has two, so `2500` is 25.00 EUR. JPY has
none, so `1000` is 1000 JPY, not 10.00. HUF has none either: the gateway counts whole
forints, although ISO 4217 lists two, so `5000` is 5000 HUF. KWD has three, so `1250` is
1.250 KWD. The amount is locked server-side - what you pass here is what gets charged;
nothing in the browser can change it.

If your shop stores prices as decimal strings, convert with `toMinorUnits()` rather than
multiplying a float (`(int) (0.3 * 100)` is 29):

```php
DominaiteClient::toMinorUnits('0.30', 'EUR');   // 30
DominaiteClient::toMinorUnits('1000', 'JPY');   // 1000
DominaiteClient::toMinorUnits('5000', 'HUF');   // 5000, whole forints
DominaiteClient::toMinorUnits('1.250', 'KWD');  // 1250
DominaiteClient::toMinorUnits('25.000', 'EUR'); // throws: EUR has 2 decimal places
```

It takes a string, parses it without floats, and throws `InvalidArgumentException` for more
decimals than the currency has (zeros included, so `"25.000"` EUR is refused), for anything
that is not plain digits with an optional dot, and for a currency it does not know. Known
today: EUR, USD, GBP, CAD, AUD, CHF, BGN, RON, PLN, CZK, SEK, DKK, NOK (two decimals), JPY
and HUF (none), BHD and KWD (three). ISK, KRW, OMR, JOD and TND throw as not supported,
because ISO 4217 and the gateway disagree on their decimals and either guess could be off
by 10x or 100x. `minorUnitExponent($currency)` returns the exponent on its own.

## Retries and double-charges

`idempotencyKey` is required on `createCheckoutSession()`, `chargePaymentMethod()` and
`createRefund()`. Leave it out and the SDK throws `InvalidArgumentException` before anything is sent. It used to
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
flow. The result follows the usual key rules (1 to 100 visible ASCII characters, 0x21-0x7E,
so no spaces), and
the helper throws if it would not.

The client retries a timeout for you with the same key, see
[Automatic retries](#automatic-retries). When it still fails, the call throws
`TransportException`. Retry later with the same params, and so the same key.

`getLastIdempotencyKey()` reads back the key of the last call that went out, for a generic
catch block that no longer has the params.

A replay only hands back the original session while that session is open and unexpired. If
the first attempt already completed, failed, or was sent with a different amount, the retry
answers HTTP 200 with `success=false` and a replay code (`DUPLICATE_REQUEST`,
`ALREADY_PROCESSED`, `PRIOR_ATTEMPT_FAILED` or `IDEMPOTENCY_KEY_REUSED`), which the SDK raises
as a `CheckoutRefusedException` carrying the transaction id to reconcile against; see
"Recovering from a replay refusal" below. Treat it as "go look up what the first attempt did",
not as an error to show the payer.

### Automatic retries

The client sends a failed request again on its own, up to `maxRetries` times (default 2).
Every attempt sends the same idempotency key, the same path and the same body. Only the
timestamp and the signature are new.

The client retries a request only when the API can replay it:

- every GET;
- every POST with an idempotency key: sessions, charges, refunds, plans and agreements;
- never `createWebhookEndpoint()`, see [Manage endpoints](#manage-endpoints-from-your-server);
- never PATCH (`updatePlan()`) or DELETE (`revokePaymentMethod()`, `deletePlan()`,
  `cancelAgreement()`, `deleteWebhookEndpoint()`).

The client retries after these failures only:

- no answer: a connection error, a reset or a timeout;
- an HTTP 5xx, except `CHARGE_OUTCOME_UNKNOWN`, `CHARGE_FAILED`,
  `PAYMENT_METHOD_CHARGES_DISABLED` and `UPSTREAM_CONTRACT_ERROR`. Each of these is an answer
  about your request, not an outage;
- a `PAYMENT_PROCESSING_UNAVAILABLE` refusal, as a 503 or as a 200 on a session.

A 429, a 4xx and an answer over the size cap are never retried. Before retry `n` (counted
from 0), the client waits a random time from 0 to `min(8 s, 500 ms x 2^n)`: up to 0.5 s, then
up to 1 s. The last attempt's answer or exception is what your code gets.

The `timeout` option (default 15 seconds) is the limit for ONE attempt. With the defaults,
one call can take up to 3 x 15 s plus about 1.5 s of waits. Set `maxRetries` to `0` to send
every request once, for example in a web request that must answer fast.

## Rate limits

60 requests per minute per API key, 120 per minute per source IP. The per-IP bucket is the
one that surprises people: several keys behind one egress address share it, so a key well
under 60/min can still be limited.

Over the limit the API answers HTTP 429 and the SDK raises `RateLimitException`. The client
never retries a 429, whatever `maxRetries` says - a loop against a rate limiter just spends
the next window too, and on `createCheckoutSession()` retrying is a decision about a payment
that belongs in your code.
`getRetryAfterSeconds()` gives the server's own number of seconds to wait, or `null` when it
did not send a usable one; treat `null` as "use your own backoff", not "retry now". When you
do retry, reuse the idempotency key.

`RateLimitException` extends `ApiException`, so code written before it existed still catches
a 429. Catch `RateLimitException` first if you want to branch on it.

`getErrorCode()` names the limit you hit:

- `RATE_LIMIT_EXCEEDED`: the request limits above. After too many failed authentication
  attempts from one IP, the API also blocks that IP for a while with this code. That 429 has
  no `Retry-After`.
- `CHARGE_VELOCITY_EXCEEDED` (`chargePaymentMethod()` only): too many charges on the card, or
  too much money in the currency, in the window.
- `CHARGE_DECLINE_RATE_EXCEEDED` (`chargePaymentMethod()` only): the key collected too many
  declines, so its charges are locked for a while.

Nothing was charged under any of them. The code is `null` when the 429 did not come from the
API itself. Treat a code you do not know as a rate limit.

The polling loop in "Fallback: status polling" is the usual way to hit this. Poll one
transaction every few seconds, not every transaction every second.

## Response size

Responses are read up to 10 MB and no further. A real merchant-API response is a few
kilobytes, so anything near that is a captive portal or an edge serving something that is
not us, and reading it to the end would grow a worker's memory for no reason. An oversized
response surfaces as `TransportException`. The client does not retry it on its own, because
the next answer is most likely the same. You can retry it with the same idempotency key.

## Request id

The API gives every request an id. Give it to support with the time of the call.

- `$client->getLastRequestId()` is the id of the last answer, after a result or an error.
  It is `null` when no answer came back, or the answer had none. The next call overwrites it.
- Every SDK exception has `getRequestId()`, the id of the answer that raised it.

The SDK reads the `Dominaite-Request-Id` response header, and `metadata.requestId` in the
body when the header is absent. Results stay plain arrays: the id is not a key in them.

## Sessions expire

A session is valid for 2 hours. If the payer comes back later, create a new session - and
re-POST with the same order-derived idempotency key, not a fresh one: from a few minutes
past expiry the same key answers with a fresh session (see "Recovering from a replay
refusal").

## Billing address

`customer` takes four optional billing fields next to the name, email and phone:

| Field | Limit | Notes |
|---|---|---|
| `address` | 200 characters | Street address, one line. |
| `city` | 100 characters | |
| `postalCode` | 20 characters | |
| `state` | 50 characters | ISO 3166-2 subdivision code, for example `CA` or `NSW`. Only used for the United States, Canada and Australia. |

The checkout asks the payer for address, city and postal code only when the payment processor
needs them. It does not ask for a field that the session already carries, so send what your own
checkout already collected. The SDK sends the fields as given and does not check the limits. The
API refuses a value over its limit with a 400, which arrives as `ApiException` with
`getErrorCode()` `VALIDATION_ERROR`. `$e->getResult()['error']['validationErrors']` names the
field, for example `['field' => 'customer.postalCode', 'code' => 'MAX_LENGTH']`. Do not retry:
fix the value first.

## Card fields

Instead of the hosted widget, you can render card fields inside your own checkout page. Card
fields are enabled per merchant on request: ask Dominaite support to switch them on. Until then a
session with `'integration' => 'fields'` is rejected with a 400 (`INVALID_SELECTION` on
`integration`).

Pass `'integration' => DominaiteClient::INTEGRATION_FIELDS` when you create the session. Leave it
out (or pass `INTEGRATION_WIDGET`) for the hosted widget. It is part of the idempotency identity,
so a replay of the same key with a different `integration` is refused with
`IDEMPOTENCY_KEY_REUSED`.

```php
$session = $client->createCheckoutSession([
    'amount' => 8440,
    'currency' => 'EUR',
    'orderReference' => 'order-1042',
    'integration' => DominaiteClient::INTEGRATION_FIELDS,
    'idempotencyKey' => DominaiteClient::orderIdempotencyKey('checkout', 'order-1042', 8440, 'EUR'),
]);
// $session['integration'] === 'fields', $session['clientSecret'] is set

$checkoutConfig = [
    'transactionId' => $session['transactionId'],
    'integration' => $session['integration'],
    'cashierKey' => $session['cashierKey'],
    'cashierToken' => $session['cashierToken'],
    'clientSecret' => $session['clientSecret'],
];
?>
<div id="checkout"></div>
<script src="https://pay.dominaite.com/v1/checkout.js"></script>
<script>
  const checkout = Dominaite.checkout(<?= json_encode($checkoutConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)
  checkout.on('success', () => { /* show a "thank you, confirming" state */ })
  checkout.mount('#checkout')
</script>
```

Hand those five values (`transactionId`, `integration`, `cashierKey`, `cashierToken`,
`clientSecret`) to the payer's page and nothing else. `clientSecret` is what lets the browser
charge this one session: keep it out of your logs. A widget session has no `clientSecret` key, so
read it with `$session['clientSecret'] ?? null`.

The page saying "success" is not proof of payment. Mark the order paid only from the
`payment.succeeded` webhook or a `getStatus()` read, exactly as with the widget.

## Stored payment methods (recurring)

Pass `'saveCard' => true` when you create a session and, once that payment is approved, the
gateway keeps the card on file. You never see the card number or the provider token:
`getStatus()` returns a `storedPaymentMethod` with an opaque `id` (`pm_` + 32 hex characters),
the `brand`, the `last4` and the expiry, and that `id` is what you charge and revoke with. Store
it against your customer. (`paymentMethod` on the same status is something else: the gateway's
string category of how the payer paid, `card`, `wallet` and so on.)

`saveCard` works on every account. `chargePaymentMethod()` works only after we switch
stored-card charges on for your account. Until then, the API answers HTTP 503
`PAYMENT_METHOD_CHARGES_DISABLED`. The API also answers this code when we pause charges for a
short time. The code is the same in both cases. If we never switched stored-card charges on
for your account, a retry does not help: ask us (Dominaite support). If they are on, retry
later with the same idempotency key.

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
            // null              - no class, for example nobody declined: handle it like soft_other
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
        case 'PAYMENT_PROCESSING_UNAVAILABLE':
            // Nothing was charged; retry later with the SAME idempotency key.
            break;
        case 'PAYMENT_METHOD_CHARGES_DISABLED':
            // 503, nothing was charged. If we never switched stored-card charges on for your
            // account, a retry does not help: ask us. If they are on, retry later with the
            // SAME idempotency key.
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
the charge, 402 returns the charge too (`status` `failed`), and 404, 409, 422,
502 and 503 throw `ChargeException` with `getErrorCode()`, `getHttpStatus()`, the gateway's
message and, when the gateway attached the charge row, `getCharge()` and `getTransactionId()`.
An id that is not yours is a 404 `ChargeException` with `getErrorCode()`
`PAYMENT_METHOD_NOT_FOUND` (or `AGREEMENT_NOT_FOUND` for an `agreementId`). A 5xx with a code
the charge route does not own is a `TransportException` that keeps the code. Only
authentication (401/403), validation (400, `ApiException`), rate limiting (429) and network
failures keep their generic exceptions. `declineClass` and `declineCode` are
`null` unless the charge was declined; the gateway omits them on the wire and the SDK reads
absent as null. `declineClass` can be null, for example when nobody declined the payment.
Handle a null class on a 402 like `soft_other`.

Revoking signs an empty key and an empty body, like `getStatus()`. A revoke that fails with
`RevokeException` changed nothing: `MERCHANT_API_UNAVAILABLE` (503) is retryable,
`UPSTREAM_CONTRACT_ERROR` (502) is not. After a revoke the status read keeps the
`storedPaymentMethod` with `status` `revoked`, and a charge against it is refused with
`PAYMENT_METHOD_NOT_ACTIVE`. An id that is not yours is an `ApiException` with
`getHttpStatus()` 404.

The platform can also retire a card on its own: `status` `retired`, with `retiredReason` set to
`hard_decline`, `chargeback` or `source_sale_reversed`
(`DominaiteClient::STORED_PAYMENT_METHOD_RETIRED_REASON_VOCABULARY`). A retired card is refused
with `PAYMENT_METHOD_NOT_ACTIVE` too and never becomes active again, so ask the customer to save
a card again. `retiredReason` is `null` on every other card.

### Save a card without a payment (verify-only)

Send `'verifyOnly' => true` to save a card with no payment, and leave `amount` out or send 0.
The checkout places a small hold to check the card and saves the card. Then the platform
releases the hold. You and the payer are never charged.

```php
$session = $client->createCheckoutSession([
    'currency'       => 'EUR',
    'orderReference' => 'card-check-' . $customerId,
    // orderIdempotencyKey() needs a positive amount, so build this key yourself.
    'idempotencyKey' => 'verify-' . $customerId,
    'verifyOnly'     => true,
]);
```

- Verify-only is off by default. We switch it on for your account. Before that, the API
  answers HTTP 403 `VERIFY_ONLY_NOT_ENABLED` and the SDK throws `AuthenticationException`.
- The API refuses `'saveCard' => false`, the card fields integration and `agreementId` on a
  verify-only session.
- The status read has `verifyOnly` set to `true`. Its `status` stays the raw status:
  `requires_capture`, then `cancelled` when the hold is released. A non-null
  `storedPaymentMethod` tells you that the card is verified.
- A verify-only session fires `verification.succeeded` or `verification.failed`, never a
  `payment.*` event.

## Subscriptions: plans and agreements

A plan is a price that repeats: an amount, a currency and an interval. An agreement puts one
customer on one plan. Every payments key can create plans and agreements.

Subscription sessions and scheduled charges are two separate switches: ask us (Dominaite
support) for both. Until we switch subscription sessions on, a session with `agreementId`
answers `SUBSCRIPTIONS_NOT_ENABLED`, and the agreement stays `pending`.
With scheduled charges on, the platform charges the saved card once
per period, and you do not call `chargePaymentMethod()` for these charges. Until we switch
scheduled charges on, the platform runs no scheduled charge and no retry.

1. Create the plan once.
2. Create an agreement for each customer. It starts `pending` and charges nothing.
3. Send the customer through a subscription session: `createCheckoutSession()` with the
   `agreementId`. The session takes the first payment, saves the card and activates the
   agreement.

```php
$plan = $client->createPlan([
    'name'           => 'Pro monthly',
    'amount'         => 2500,    // minor units
    'currency'       => 'EUR',
    'intervalUnit'   => 'month', // day, week, month or year
    'intervalCount'  => 1,
    'idempotencyKey' => 'plan-pro-monthly',
]);

$agreement = $client->createAgreement([
    'planId'            => $plan['id'], // plan_...
    'customerReference' => 'customer-8817',
    'idempotencyKey'    => 'agreement-customer-8817-pro',
]);

$session = $client->createCheckoutSession([
    'amount'         => 2500,
    'currency'       => 'EUR',
    'orderReference' => 'customer-8817-pro-first',
    'idempotencyKey' => 'subscribe-' . $agreement['id'],
    'agreementId'    => $agreement['id'], // agr_...
    'consent'        => [
        'textVersion' => 'recurring-2026-09', // a version from the contract's recurring.consentTextVersions
        'acceptedAt'  => $acceptedAt,         // when the customer accepted, ISO 8601 UTC
        'ip'          => $customerIp,
    ],
]);
```

- `listPlans()` returns at most 200 plans, active first, with no pages. `getPlan()` reads one.
- `updatePlan()` changes only `name`, `trialDays` and `cancelUrl`. The amount, the currency and
  the interval never change: create a new plan instead.
- `deletePlan()` archives the plan. Agreements on it continue on their own copy of it.
- `getAgreement()` reads an agreement. `cancelAgreement()` cancels it: no further charge is
  scheduled, and a charge already placed is not reversed. Cancelling twice is safe.
- `createPlan()` and `createAgreement()` need an `idempotencyKey`. The same key with the same
  body answers the first object again. Derive the key from your own record, never at random.
- A refusal is an `ApiException`. Branch on `getErrorCode()`, for example `PLAN_NOT_FOUND` or
  `AGREEMENT_CONSENT_REQUIRED`.

The status read of the subscription session has `agreement`: its `id`, `status` and
`nextChargeAt`. The `status` turns `active` when the first payment is approved. After a decline
or an abandoned session it stays `pending`. To follow the agreement after that, use the
`agreement.*` and `charge.*` events (see "Agreement and charge events: order by sequence").

## Refunds

`createRefund()` refunds a payment, all of it or part of it. `$transactionId` is the id
`createCheckoutSession()` or `chargePaymentMethod()` returned; only card-not-present payments
of your own account can be refunded, any other id is `PAYMENT_NOT_FOUND`. A refund is always
in the payment's currency, and the amount is in minor units by the same rules as a payment
(see [Amounts are minor units](#amounts-are-minor-units)).

`createRefund()` on a payment made on a terminal answers 404 `PAYMENT_NOT_FOUND`. You can
refund at most the paid amount. Whoever holds your payments key can refund, so keep that key
on your server.

```php
use Dominaite\Exception\RefundException;

try {
    // Part of a HUF payment: HUF has no decimals on the gateway, so this is 1500 HUF.
    $refund = $client->createRefund($transactionId, [
        'amount'         => DominaiteClient::toMinorUnits('1500', 'HUF'),
        'reason'         => 'Returned item',              // optional, at most 500 characters
        // Derived from YOUR refund (return or credit-note id), never random per attempt.
        'idempotencyKey' => 'refund-' . $creditNoteId,
    ]);
    $db->saveRefund($creditNoteId, $refund['refundId']);  // re_..., status pending

    // Everything still refundable: leave amount out and no amount is sent.
    // $client->createRefund($transactionId, ['idempotencyKey' => 'refund-' . $creditNoteId]);
} catch (RefundException $e) {
    switch ($e->getErrorCode()) {
        case 'REFUND_AMOUNT_EXCEEDED':  // 422: more than what is left, the message says how much is
        case 'PAYMENT_NOT_REFUNDABLE':  // 422: not paid, already fully refunded, or all of it in progress
            // Nothing was queued and the key is not burnt.
            break;
        case 'DUPLICATE_REQUEST':       // 409: this key is in flight, retry the SAME key shortly
            break;
        case 'IDEMPOTENCY_KEY_REUSED':  // 422: the key was used for a different refund, a bug on your side
        case 'PAYMENT_NOT_FOUND':       // 404: not a card-not-present payment of yours
            break;
    }
}
```

The refund runs asynchronously: the 202 means queued, not done. `idempotencyKey` is
required and signed like a charge's, and the same key always answers the same `refundId`
and never refunds twice; a replay answers with the refund as it stands now. A 500 (a
`TransportException`) means nothing was queued: retry with the **same** key,
`getLastIdempotencyKey()` reads it back. `RefundException` extends `ApiException` and
`isRetryable()` tells the two retryable codes (`DUPLICATE_REQUEST`, `REFUND_NOT_FOUND`) from
the rest.

Read the outcome with `getRefund()`, or wait for the `payment.refunded` webhook:

```php
$refund = $client->getRefund($transactionId, $refundId);
// ['refundId' => 're_...', 'transactionId' => ..., 'status' => 'succeeded', 'amount' => 1500,
//  'currency' => 'HUF', 'failureCode' => null, 'failureMessage' => null,
//  'completedAt' => '2026-09-26T10:05:40.1200000Z']
```

- `status` is `pending` (queued), `processing` (with the payment provider), `succeeded` or
  `failed`. `succeeded` and `failed` are final. A failed refund is final for its key: a new
  attempt needs a new key.
- `amount` is the amount requested while `pending` (null for a full refund), the amount being
  refunded while `processing` (null until a full refund has been sized), the amount actually
  refunded once it has succeeded, and always null on a failed refund.
- `failureCode` is set on a failed refund only: `REFUND_FAILED`, `REFUND_AMOUNT_EXCEEDED` or
  `PAYMENT_NOT_REFUNDABLE` (`DominaiteClient::REFUND_FAILURE_CODES`). Treat a code you do not
  know as `REFUND_FAILED`. `REFUND_FAILED` is never an HTTP error.
- Right after the 202 the refund may not be picked up yet: `getRefund()` then throws
  `RefundException` with `REFUND_NOT_FOUND`. Poll again for up to 60 seconds.
- The gateway omits null fields on the wire; the SDK reads them as null, so every key is
  always there.

A completed refund fires `payment.refunded`, once per refund, partial or full. On it,
`data.transactionId` is the refund's own transaction id, `data.amount` is that refund's
amount and `data.originalTransactionId` is the payment you refunded. **A failed refund fires
no webhook**: poll `getRefund()` if you need to know about failures.

### List the refunds of a payment

`listRefunds()` returns the refunds of one payment, with its totals:

```php
$list = $client->listRefunds($transactionId);
// ['transactionId' => ..., 'currency' => 'HUF', 'refundedAmount' => 1500,
//  'refundableAmount' => 6940, 'refunds' => [[... the shape of getRefund() ...]]]
```

- `refunds` has the refunds created through this API, and is empty when there are none.
  Pending refunds come first, then processing ones, then the final ones. Each group is newest
  first.
- `refundedAmount` also counts refunds made in the dashboard. `refundableAmount` is what a new
  refund can take now.
- An unknown payment is a `RefundException` with `PAYMENT_NOT_FOUND`.

## List your transactions

`listTransactions()` lists your card-not-present payments in a time window, page by page. It
needs a reporting key with the `transactions:read` scope (see "Scopes" above).

```php
$reporting = new DominaiteClient(
    getenv('DOMINAITE_REPORTING_KEY_ID'),
    getenv('DOMINAITE_REPORTING_SECRET'),
    getenv('DOMINAITE_BASE_URL')
);

$params = ['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-08T00:00:00Z', 'limit' => 100];
do {
    $page = $reporting->listTransactions($params);
    foreach ($page['items'] as $item) {
        // transactionId, orderReference, type, status, amount, currency, createdAt,
        // completedAt, storedPaymentMethodId, refundedAmount,
        // fee, netAmount, settledAt, payoutId
    }
    $params['cursor'] = $page['nextCursor'];
} while ($page['nextCursor'] !== null);
```

- `from` is inclusive and `to` is exclusive, on `createdAt`. Both are required, at most 31 days
  apart. Use the `Z` form, for example `gmdate('Y-m-d\TH:i:s\Z', $time)`.
- `status` is optional and keeps the payments that have that status now. `limit` is 1 to 100,
  50 when you leave it out.
- Rows come oldest first. Send `nextCursor` back as `cursor`, with the same `from`, `to`,
  `settledFrom`, `settledTo` and `status`, until it is `null`. Paging never repeats or skips a row.
- `type` is `payment`, `charge` or `verification`. A `verification` is not a purchase, so leave
  it out of revenue.
- A refund is not a row of its own. It shows on its payment as `refundedAmount` and `status`.
- `fee`, `netAmount`, `settledAt` and `payoutId` are `null` until the acquirer settles the
  payment, usually the next business day. `payoutId` stays `null` until we pay the money out.
- `netAmount` is `amount` minus the fee of this payment only. Deductions on the whole payout are
  not in it. `fee` and `netAmount` can change, so read a settlement day again before you close
  your books.
- To get one settlement day, send `'settledFrom' => '2026-10-13', 'settledTo' => '2026-10-14'`
  (dates only), with a `from` and `to` that cover when those payments were created.
- The SDK sends the parameters in the query string. The signature covers the path only.

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
session is still payable, `expiresAt` says until when; after that instant a `pending` session
can only become `abandoned`. An unknown transaction id throws an `ApiException` with HTTP 404.

`orderReference`, `refundedAmount`, `updatedAt` and `expiresAt` are always in the array, and
null when they have no value.

`succeeded` is the only value that means the payment is complete. Keep polling on `pending`,
`processing` and `requires_capture` - none of them is terminal.

`requires_capture` is **not** "unpaid": the payer has already paid and the funds are held
awaiting capture. Never treat it as an abandoned order.

Treat any status you do not recognise as still-open as well: a value the API adds later should
make you keep polling, never silently close an order that is still live.

`paymentMethod` says how the payer paid (`card`, `wallet`, `bank_transfer` or `sepa`) and
`walletType` names the wallet when it is `wallet` (`apple_pay`, `google_pay`, `samsung_pay`).
Both are null while the payment is still open and on older transactions, `walletType` is null
for non-wallet payments, and older gateways leave the keys out, so read them with `?? null`. This is reporting data, not a money-flow switch: a wallet payment refunds, captures
and disputes like a card. A `walletType` not in `DominaiteClient::WALLET_TYPES` is still a
valid wallet, not an error.

The status read also has these fields. The API leaves some of them out when they have no
value, so read each one with `??`:

- `declineClass`: why a failed payment was declined (`DominaiteClient::DECLINE_CLASS_VOCABULARY`).
  It is null on every status other than `failed`. It can be null on `failed` too, for example
  when nobody declined the payment.
- `isRecurring`: `true` for a charge on a saved card, `false` for a checkout session.
- `verifyOnly`: `true` for a verify-only session. Other sessions do not have it.
- `agreement`: the `id`, `status` and `nextChargeAt` of the subscription agreement, or null.
- `sequence`: the `data.sequence` of the latest `charge.*` event about this payment, 0 before
  the first one.

Two helpers encode those rules so you do not have to:

```php
DominaiteClient::isPaid($status['status']);     // true only for 'succeeded'
DominaiteClient::isTerminal($status['status']); // stop polling: succeeded, failed, cancelled,
                                                // abandoned, refunded, partially_refunded
```

Everything else, `disputed` and unknown values included, is not terminal.

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

## Versioning and support

The SDK is 0.x on purpose. A minor release (for example 0.6 to 0.7) can contain a breaking
change. A patch release (0.6.0 to 0.6.1) does not. `CHANGELOG.md` lists every breaking change
and what to do about it.

Composer's caret constraint stops at the minor version on 0.x: `^0.6` allows 0.6.1 but not
0.7.0. So `composer update` never takes you to a release that can break your code. Upgrade on
purpose with `composer require dominaite/merchant-sdk:^0.7`, and read `CHANGELOG.md` first.

The SDK needs PHP 7.4 or later with `ext-curl`, `ext-json` and `ext-mbstring`. CI tests it on
PHP 7.4 and PHP 8.3.

When the API marks your SDK version as deprecated, it sends a `Dominaite-Sdk-Notice` header.
The client turns it into one warning for each distinct notice (see
[Logging and warnings](#logging-and-warnings)). The warning names the version to upgrade to and
the sunset date when the notice carries them. Pass a `logger`, or make sure your framework
logs `E_USER_DEPRECATED`, so you see the notice before the sunset date. A response without the
header changes nothing.
