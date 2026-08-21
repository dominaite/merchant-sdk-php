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

## Credentials

You get two values from Dominaite (shown once - store them like passwords):

- `dmk_...` - your API key id. Identifies you; not secret by itself.
- `dms_...` - your API secret. Server-side only: environment variable or config outside the
  web root. Never in a browser, never in git, never in logs.

Every request is signed with the secret (HMAC-SHA256) and timestamped. Keep your server
clock on NTP - signatures older than 5 minutes are rejected.

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
use Dominaite\Exception\TransportException;

$client = new DominaiteClient(getenv('DOMINAITE_KEY_ID'), getenv('DOMINAITE_SECRET'));

try {
    $session = $client->createCheckoutSession([
        'amount'         => 2500,            // minor units: 2500 = 25.00 EUR
        'currency'       => 'EUR',
        'orderReference' => 'order-1042',    // your own order id, shows up in your dashboard
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
} catch (TransportException $e) {
    // Network blip - retry with $client->getLastIdempotencyKey(), never a fresh key.
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

`amount` is always an integer in the currency's minor unit: `2500` is 25.00 EUR, `1000` is
10.00 JPY-equivalent in a two-decimal currency. The amount is locked server-side - what you
pass here is what gets charged; nothing in the browser can change it.

## Retries and double-charges

Every `createCheckoutSession` call carries an idempotency key (auto-generated, or pass your
own as `idempotencyKey`). Retrying with the same key never opens a second payment - on a
timeout, retry with the same key rather than generating a new one. When you let the SDK
generate the key, read it back with `getLastIdempotencyKey()` so the retry can reuse it:

```php
$session = null;
$key = null;

for ($attempt = 1; $attempt <= 3 && $session === null; $attempt++) {
    if ($key !== null) {
        $params['idempotencyKey'] = $key;
    }
    try {
        $session = $client->createCheckoutSession($params);
    } catch (TransportException $e) {
        // Read the key here, in the catch - store it against your order before you
        // sleep or hand off, because the next create() call overwrites it.
        $key = $client->getLastIdempotencyKey();
        if ($attempt === 3) {
            throw $e;
        }
        sleep($attempt);
    }
}
```

**A replay does not hand you the original session back.** If the first attempt did reach the
gateway, the retry answers HTTP 200 with `success=false` and a replay code - `DUPLICATE_REQUEST`,
`ALREADY_PROCESSED`, `PRIOR_ATTEMPT_FAILED` or `IDEMPOTENCY_KEY_REUSED` - which the SDK raises
as a `CheckoutRefusedException`. The original session's `cashierKey` and `cashierToken` are not
in that response, so a retry cannot be your only path to rendering the widget. What the refusal
gives you is the transaction id to reconcile against; see "Recovering from a replay refusal"
below. Store `transactionId` and the idempotency key when a create succeeds, and treat the
replay refusal as "go look up what the first attempt did", not as an error to show the payer.

## Sessions expire

A session is valid for 2 hours. If the payer comes back later, create a new session.

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
The full refusal payload is on `getResult()`.
