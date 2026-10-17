<?php
// Proves that a value the SDK has never seen never throws. Every status, retiredReason,
// declineClass, failure code, interval unit, transaction type and event type below is
// made up. Each one goes through the real response parser of its route and comes back
// as the string the API sent.
// Run: php tests/lenient_vectors.php - exits non-zero on any mismatch.

require __DIR__ . '/../src/DominaiteClient.php';
require __DIR__ . '/../src/Exception/ApiException.php';
require __DIR__ . '/../src/Exception/AuthenticationException.php';
require __DIR__ . '/../src/Exception/ChargeException.php';
require __DIR__ . '/../src/Exception/RateLimitException.php';
require __DIR__ . '/../src/Exception/RefundException.php';
require __DIR__ . '/../src/Exception/TransportException.php';

use Dominaite\DominaiteClient;

$failures = 0;

set_exception_handler(static function (\Throwable $e): void {
    echo 'FAIL uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
});

function check(string $name, string $actual, string $expected): void
{
    global $failures;
    if ($actual === $expected) {
        echo "ok  $name\n";
    } else {
        echo "FAIL $name\n  expected $expected\n  actual   $actual\n";
        $failures++;
    }
}

const KEY_ID = 'dmk_0123456789abcdef';
const SECRET = 'dms_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const TRANSACTION_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
const PAYMENT_METHOD_ID = 'pm_0123456789abcdef0123456789abcdef';
const PLAN_ID = 'plan_0123456789abcdef0123456789abcdef';
const AGREEMENT_ID = 'agr_0123456789abcdef0123456789abcdef';

/** Answers every call with one canned body under one canned status, through the real parser. */
final class CannedClient extends DominaiteClient
{
    /** @var array<string,mixed> */
    private array $canned;
    private int $status;

    /** @param array<string,mixed> $canned */
    public function __construct(array $canned, int $status = 200)
    {
        parent::__construct(KEY_ID, SECRET, DominaiteClient::SANDBOX_BASE_URL);
        $this->canned = $canned;
        $this->status = $status;
    }

    protected function send(string $method, string $path, ?array $body, string $idempotencyKey): array
    {
        return $this->readResponse($this->status, (string) json_encode($this->canned), []);
    }
}

/** @param mixed $data */
function canned($data, int $status = 200, bool $success = true): CannedClient
{
    return new CannedClient(['success' => $success, 'data' => $data], $status);
}

/** Runs one read and reports what it threw, or the values it read, joined by spaces. */
function read(callable $fn): string
{
    try {
        return implode(' ', array_map(static function ($value): string {
            return is_string($value) ? $value : var_export($value, true);
        }, $fn()));
    } catch (\Throwable $e) {
        return 'threw ' . get_class($e) . ': ' . $e->getMessage();
    }
}

// --- getStatus ----------------------------------------------------------------------------
$status = ['transactionId' => TRANSACTION_ID, 'orderId' => 'ord_1', 'orderReference' => 'order-1', 'status' => 'on_hold',
    'amount' => 2500, 'currency' => 'EUR', 'refundedAmount' => null, 'createdAt' => '2026-10-01T08:00:00Z',
    'updatedAt' => null, 'expiresAt' => null, 'paymentMethod' => 'crypto', 'walletType' => 'future_pay',
    'declineClass' => 'soft_future', 'isRecurring' => false, 'sequence' => 0,
    'storedPaymentMethod' => ['id' => PAYMENT_METHOD_ID, 'status' => 'frozen', 'retiredReason' => 'future_reason'],
    'agreement' => ['id' => AGREEMENT_ID, 'status' => 'paused']];
check('getStatus reads unknown status, declineClass, paymentMethod and walletType',
    read(static function () use ($status): array {
        $read = canned($status)->getStatus(TRANSACTION_ID);
        return [$read['status'], $read['declineClass'], $read['paymentMethod'], $read['walletType']];
    }), 'on_hold soft_future crypto future_pay');
check('getStatus reads an unknown card status and retiredReason',
    read(static function () use ($status): array {
        $card = canned($status)->getStatus(TRANSACTION_ID)['storedPaymentMethod'];
        return [$card['status'], $card['retiredReason']];
    }), 'frozen future_reason');
check('getStatus reads an unknown agreement status',
    read(static function () use ($status): array {
        return [canned($status)->getStatus(TRANSACTION_ID)['agreement']['status']];
    }), 'paused');
check('an unknown status is neither paid nor terminal',
    var_export(DominaiteClient::isPaid('on_hold'), true) . ' ' . var_export(DominaiteClient::isTerminal('on_hold'), true),
    'false false');

// --- chargePaymentMethod -------------------------------------------------------------------
$chargeParams = ['amount' => 2500, 'currency' => 'EUR', 'orderReference' => 'order-1', 'idempotencyKey' => 'renewal-1'];
check('a 201 charge with an unknown status is a result',
    read(static function () use ($chargeParams): array {
        $charge = canned(['chargeId' => 'ch_1', 'status' => 'queued', 'transactionId' => TRANSACTION_ID], 201)
            ->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
        return [$charge['status']];
    }), 'queued');
check('a 402 decline with an unknown declineClass is still a result',
    read(static function () use ($chargeParams): array {
        $charge = canned(['chargeId' => 'ch_1', 'status' => 'failed', 'transactionId' => TRANSACTION_ID,
            'declineClass' => 'soft_future', 'declineCode' => '9999'], 402, false)
            ->chargePaymentMethod(PAYMENT_METHOD_ID, $chargeParams);
        return [$charge['status'], $charge['declineClass']];
    }), 'failed soft_future');

// --- refunds ------------------------------------------------------------------------------
$refund = ['refundId' => 're_1', 'transactionId' => TRANSACTION_ID, 'status' => 'reversed', 'currency' => 'EUR',
    'failureCode' => 'FUTURE_FAILURE'];
check('getRefund reads an unknown refund status and failure code',
    read(static function () use ($refund): array {
        $read = canned($refund)->getRefund(TRANSACTION_ID, 're_1');
        return [$read['status'], $read['failureCode']];
    }), 'reversed FUTURE_FAILURE');
check('listRefunds reads an unknown refund status',
    read(static function () use ($refund): array {
        $list = canned(['transactionId' => TRANSACTION_ID, 'currency' => 'EUR', 'refundedAmount' => 0,
            'refundableAmount' => 0, 'refunds' => [$refund]])->listRefunds(TRANSACTION_ID);
        return [$list['refunds'][0]['status']];
    }), 'reversed');

// --- plans and agreements -----------------------------------------------------------------
$plan = ['id' => PLAN_ID, 'name' => 'Gold', 'amount' => 2500, 'currency' => 'EUR', 'intervalUnit' => 'fortnight',
    'intervalCount' => 1, 'trialDays' => 0, 'status' => 'paused', 'createdAt' => '2026-10-01T08:00:00Z'];
check('getPlan reads an unknown plan status and interval unit',
    read(static function () use ($plan): array {
        $read = canned($plan)->getPlan(PLAN_ID);
        return [$read['status'], $read['intervalUnit']];
    }), 'paused fortnight');
check('listPlans reads an unknown plan status',
    read(static function () use ($plan): array {
        return [canned([$plan])->listPlans()[0]['status']];
    }), 'paused');
$agreement = ['id' => AGREEMENT_ID, 'planId' => PLAN_ID, 'customerReference' => 'c-1', 'status' => 'suspended',
    'amount' => 2500, 'currency' => 'EUR', 'intervalUnit' => 'fortnight', 'intervalCount' => 1, 'trialDays' => 0,
    'pastDueReason' => 'future_reason', 'version' => 1, 'sequence' => 0, 'createdAt' => '2026-10-01T08:00:00Z'];
check('getAgreement reads an unknown agreement status and pastDueReason',
    read(static function () use ($agreement): array {
        $read = canned($agreement)->getAgreement(AGREEMENT_ID);
        return [$read['status'], $read['pastDueReason']];
    }), 'suspended future_reason');

// --- webhook endpoints and transactions -----------------------------------------------------
$endpoint = ['id' => '3f2c9a1e-0b7d-4c55-9a8e-1d2f3a4b5c6d', 'url' => 'https://shop.example/hooks',
    'events' => ['future.event'], 'status' => 'suspended', 'createdAt' => '2026-10-01T08:00:00Z'];
check('listWebhookEndpoints reads an unknown endpoint status and event type',
    read(static function () use ($endpoint): array {
        $read = canned([$endpoint])->listWebhookEndpoints()[0];
        return [$read['status'], $read['events'][0]];
    }), 'suspended future.event');
check('createWebhookEndpoint sends and reads an unknown event type',
    read(static function () use ($endpoint): array {
        $read = canned($endpoint, 201)->createWebhookEndpoint(['url' => 'https://shop.example/hooks',
            'events' => ['future.event'], 'idempotencyKey' => 'endpoint-1']);
        return [$read['events'][0]];
    }), 'future.event');
check('listTransactions reads an unknown transaction type and status',
    read(static function (): array {
        $item = canned(['items' => [['transactionId' => TRANSACTION_ID, 'type' => 'payout', 'status' => 'on_hold',
            'amount' => 2500, 'currency' => 'EUR', 'createdAt' => '2026-10-01T08:00:00Z']], 'nextCursor' => null])
            ->listTransactions(['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z'])['items'][0];
        return [$item['type'], $item['status']];
    }), 'payout on_hold');

// --- webhooks -----------------------------------------------------------------------------
// verifyWebhook checks the signature over the raw bytes and never reads the event type.
$whsec = 'whsec_abababababababababababababababababababababababababababababababab';
$body = '{"id":"evt_1","type":"future.event","apiVersion":"2026-10-01","createdAt":"2026-10-01T08:00:00Z","data":{}}';
$header = 't=1759305600,v1=' . hash_hmac('sha256', '1759305600.' . $body, $whsec);
check('a delivery of an unknown event type verifies',
    var_export(DominaiteClient::verifyWebhook($body, $header, $whsec, 300, 1759305600), true), 'true');

exit($failures === 0 ? 0 : 1);
