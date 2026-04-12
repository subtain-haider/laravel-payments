# Laravel Payments

Multi-gateway payment SDK for Laravel. One interface for every gateway. Ships with **Fanbasis**, **PremiumPay**, and **Match2Pay**.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## Install

```bash
composer require subtain/laravel-payments
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

## Environment

```env
PAYMENT_GATEWAY=fanbasis

FANBASIS_API_KEY=
FANBASIS_WEBHOOK_SECRET=
FANBASIS_CREATOR_HANDLE=          # required for embedded checkout
```

---

## Quick Start

### Checkout (with DB record)

```php
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$result = app(PaymentService::class)->initiate('fanbasis', new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    successUrl: 'https://app.com/success',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    metadata: ['invoice_id' => $order->id],
), $order);

return redirect($result->redirectUrl);
```

### Checkout (no DB)

```php
use Subtain\LaravelPayments\Facades\Payment;

$result = Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    webhookUrl: route('payments.webhook', 'fanbasis'),
));
```

### Webhooks

Handled automatically at `POST /payments/webhook/{gateway}`. The package verifies the signature, updates payment status, and dispatches events.

```php
// EventServiceProvider
protected $listen = [
    \Subtain\LaravelPayments\Events\PaymentSucceeded::class => [
        \App\Listeners\FulfillOrder::class,
    ],
];
```

```php
class FulfillOrder
{
    public function handle(PaymentSucceeded $event): void
    {
        $invoiceId = $event->result->invoiceId; // from metadata['invoice_id']
        $payment   = $event->payment;           // Payment model (nullable)
    }
}
```

---

## Fanbasis Gateway

Full Fanbasis API coverage: checkout, customers, subscribers, discount codes, products, transactions, refunds, and webhooks.

### One-Time Checkout

```php
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Funded Account',
    productDescription: '$10K challenge',
    successUrl: 'https://app.com/success',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    metadata: ['invoice_id' => 'inv_123'],
));
```

### Subscription Checkout

```php
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 29.99,
    productName: 'Pro Monthly',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    extra: [
        'subscription' => [
            'frequency_days'  => 30,
            'free_trial_days' => 7,
        ],
    ],
));
```

### Embedded Checkout (iframe)

```php
$result = Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 0,
    extra: [
        'embedded'   => true,
        'product_id' => 'NLxj6',
    ],
));
// $result->redirectUrl → https://embedded.fanbasis.io/session/{handle}/{id}/{secret}
```

### Static Payment Link

```php
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 0,
    extra: ['payment_link' => 'https://fanbasis.com/pay/your-link'],
    metadata: ['user_id' => '42'], // appended as query params
));
```

### Discount Codes

```php
// Pre-apply a code at checkout
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    extra: ['discount_code' => 'SUMMER20'],
));

// Let customer enter their own code
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    extra: ['allow_discount_codes' => true],
));
```

### Full API Access

```php
$fb = Payment::gateway('fanbasis');
```

#### Checkout Sessions

```php
$fb->checkoutSessions()->create([
    'product'      => ['title' => 'Pro Plan'],
    'amount_cents' => 29900,
    'type'         => 'onetime_non_reusable',
    'success_url'  => 'https://app.com/success',
]);
$fb->checkoutSessions()->find('NLxj6');
$fb->checkoutSessions()->delete('NLxj6');
$fb->checkoutSessions()->transactions('NLxj6');
$fb->checkoutSessions()->createEmbedded(['product_id' => 'NLxj6']);
$fb->checkoutSessions()->subscriptions('NLxj6');
$fb->checkoutSessions()->cancelSubscription('NLxj6', 'sub_1');
$fb->checkoutSessions()->extendSubscription('NLxj6', [
    'user_id' => 'usr_1', 'duration_days' => 30,
]);
$fb->checkoutSessions()->refundTransaction('txn_1', ['amount_cents' => 1500]);
```

#### Customers

```php
$fb->customers()->list(['search' => 'jane@example.com']);
$fb->customers()->paymentMethods('cust_1');
$fb->customers()->charge('cust_1', [
    'payment_method_id' => 'pm_abc',
    'amount_cents'      => 1999,
    'description'       => 'Upgrade charge',
]);
```

#### Subscribers

```php
$fb->subscribers()->list(['status' => 'active']);
$fb->subscribers()->forCheckoutSession('NLxj6');
$fb->subscribers()->forProduct('prod_1');
$fb->subscribers()->cancel('NLxj6', 'sub_1');
$fb->subscribers()->extend('NLxj6', ['user_id' => 'usr_1', 'duration_days' => 30]);
$fb->subscribers()->refundTransaction('txn_1');
```

#### Discount Codes

```php
$fb->discountCodes()->list();
$fb->discountCodes()->create([
    'code'          => 'SUMMER20',
    'discount_type' => 'percentage',  // or 'fixed'
    'value'         => 20,
    'duration'      => 'once',        // once, forever, multiple_months
    'expiry'        => '2026-12-31',
    'one_time'      => true,
    'service_ids'   => [101, 102],
]);
$fb->discountCodes()->find(1);
$fb->discountCodes()->update(1, ['expiry' => '2027-06-30']);
$fb->discountCodes()->delete(1);
```

#### Products

```php
$fb->products()->list(['page' => 1, 'per_page' => 20]);
```

#### Transactions

```php
$fb->transactions()->find('txn_abc');
$fb->transactions()->list(['product_id' => 'NLxj6']);
```

#### Refunds

```php
$fb->refunds()->create('txn_abc');                          // full
$fb->refunds()->create('txn_abc', ['amount' => 1500]);     // partial
$fb->refunds()->create('txn_abc', ['reason' => 'Unused']); // with reason
```

#### Webhooks Management

```php
$fb->webhooks()->list();
$fb->webhooks()->create([
    'webhook_url'  => 'https://app.com/webhooks/fanbasis',
    'event_types'  => ['payment.succeeded', 'subscription.created'],
]);
$fb->webhooks()->delete('ws_abc');
$fb->webhooks()->test('ws_abc', ['event_type' => 'payment.succeeded']);
```

### Webhook Signature Verification

Automatic when `FANBASIS_WEBHOOK_SECRET` is set. Manual:

```php
use Subtain\LaravelPayments\Gateways\Fanbasis\WebhooksService;

WebhooksService::verifySignature($request->getContent(), $request->header('x-webhook-signature'), $secret);
```

---

## Other Gateways

### PremiumPay

```php
Payment::gateway('premiumpay')->checkout(new CheckoutRequest(
    amount: 99.00,
    invoiceId: 'inv_456',
    customerEmail: 'user@example.com',
    customerIp: request()->ip(),
    productName: 'Starter Package',
    successUrl: 'https://app.com/success',
    webhookUrl: route('payments.webhook', 'premiumpay'),
));
```

### Match2Pay (Crypto)

```php
Payment::gateway('match2pay')->checkout(new CheckoutRequest(
    amount: 50.00,
    webhookUrl: route('payments.webhook', 'match2pay'),
    extra: ['payment_currency' => 'USX', 'payment_gateway_name' => 'USDT TRC20'],
));
```

---

## Payment Model

```php
use Subtain\LaravelPayments\Models\Payment;

$payment = Payment::findByInvoiceId('inv_123');
$payment->isPaid();
$payment->markAsPaid('txn_abc');
$payment->markAsRefunded();
$payment->payable;  // polymorphic owner
$payment->logs;     // webhook audit trail
```

### Status Machine

```
pending → processing → paid → refunded
pending → failed → pending (retry)
pending → cancelled (terminal)
```

## HasPayments Trait

```php
use Subtain\LaravelPayments\Traits\HasPayments;

class Order extends Model { use HasPayments; }

$order->payments;
$order->hasPaidPayment();
$order->latestPayment();
```

---

## Custom Gateway

```php
// 1. Implement the interface
class StripeGateway implements PaymentGateway
{
    public function name(): string { return 'stripe'; }
    public function checkout(CheckoutRequest $request): CheckoutResult { /* ... */ }
    public function parseWebhook(array $payload): WebhookResult { /* ... */ }
    public function verifyWebhook(array $payload, array $headers = []): bool { /* ... */ }
}

// 2. Register in config/payments.php
'stripe' => [
    'driver' => \App\Gateways\StripeGateway::class,
    'secret' => env('STRIPE_SECRET'),
],

// 3. Use it
Payment::gateway('stripe')->checkout($request);
```

---

## Events

| Event | Trigger |
|---|---|
| `PaymentSucceeded` | Payment confirmed |
| `PaymentFailed` | Payment failed |
| `WebhookReceived` | Any webhook (all statuses) |

## Testing

```php
Payment::shouldReceive('gateway->checkout')
    ->andReturn(new CheckoutResult(redirectUrl: 'https://test.com', gateway: 'fanbasis'));
```

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12

## License

MIT — [LICENSE](LICENSE)

## Author

**Syed Subtain Haider** — [GitHub](https://github.com/subtain-haider)
