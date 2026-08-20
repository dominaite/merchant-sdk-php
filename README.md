# dominaite/dominaite-php

Server-side PHP client for the Dominaite merchant API. One call from your backend opens a
hosted checkout session; a two-line script tag renders the payment widget on your page. Card
details go straight from your customer's browser into the payment widget - they never touch
your server, which keeps your PCI scope minimal (SAQ A).

Works on plain PHP 7.4+ with curl. No framework required.

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
    // Network blip - safe to retry with the same idempotencyKey.
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

That's the whole integration: the session call above, the script tag, and your domain bound
to your checkout by Dominaite during onboarding.

## Amounts are minor units

`amount` is always an integer in the currency's minor unit: `2500` is 25.00 EUR, `1000` is
10.00 JPY-equivalent in a two-decimal currency. The amount is locked server-side - what you
pass here is what gets charged; nothing in the browser can change it.

## Retries and double-charges

Every `createCheckoutSession` call carries an idempotency key (auto-generated, or pass your
own as `idempotencyKey`). Retrying with the same key never opens a second payment - on a
timeout, retry with the same key rather than generating a new one.

## Sessions expire

A session is valid for 2 hours. If the payer comes back later, create a new session.

## Status polling

```php
$status = $client->getStatus($session['transactionId']);
// ['transactionId' => ..., 'orderReference' => 'order-1042', 'status' => 'succeeded',
//  'amount' => 2500, 'currency' => 'EUR', ...]
```

`status` is one of: `pending`, `processing`, `succeeded`, `failed`, `refunded`,
`partially_refunded`, `cancelled`, `disputed`, `abandoned`. While the session is still
payable the response also carries `expiresAt`; after that instant a `pending` session can
only become `abandoned`. An unknown transaction id throws an `ApiException` with HTTP 404.

Poll after the payer returns to you, or on your order timeout - not in a tight loop; the
endpoint is rate limited per key.
