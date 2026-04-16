# Laravel Payments

A unified, production-ready payment SDK for Laravel. Write your checkout and webhook logic once — switch gateways, add new ones, and scale without changing a line of application code.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Latest Version](https://img.shields.io/packagist/v/subtain/laravel-payments.svg)](https://packagist.org/packages/subtain/laravel-payments)

---

## Features

- **4 production-ready gateways** — Fanbasis, Match2Pay (crypto), Rebornpay (UPI/India), PremiumPay
- **Payment records** — full DB tracking with polymorphic ownership, status machine, and audit trail
- **Automatic webhook handling** — signature verification, status transitions, event dispatch for every gateway
- **Discount codes** — gateway-agnostic, auto-applied and auto-recorded on webhook confirmation
- **Sandbox mode** — simulate full payment flows without real charges, on any environment
- **Centralized logging** — per-gateway channel routing, level control, sensitive field redaction
- **API key fingerprinting** — post-rotation audit trail on every payment record and log entry
- **Extensible** — add any PSP in 3 steps, gets all features for free

---

## Supported Gateways

| Gateway | Type | Checkout | Webhooks | Full API | Docs |
|---|---|---|---|---|---|
| **Fanbasis** | Cards, MoR | One-time, Subscriptions, Embedded, Static | 12 event types, HMAC-SHA256 | Customers, Subscribers, Discount Codes, Products, Transactions, Refunds, Webhooks | [→](docs/gateways/fanbasis.md) |
| **Match2Pay** | Crypto (USDT, BTC, ETH, BNB, 40+) | API checkout, 2-step selection | SHA-384 (DONE only) | Deposits, Withdrawals | [→](docs/gateways/match2pay.md) |
| **Rebornpay** | UPI / IMPS (India) | API checkout | MD5 + Python-repr | Pay-in, Status checks, UTR storage | [→](docs/gateways/rebornpay.md) |
| **PremiumPay** | Cards | API checkout | Callback-based | Checkout only | See below |
| **Your gateway** | Any | Implement interface | Your logic | Your logic | [→ Custom gateways](docs/custom-gateways.md) |

---

## Documentation

| Topic | Description |
|---|---|
| **[Payments](docs/payments.md)** | PaymentService, CheckoutRequest, Payment model, status machine, HasPayments trait, webhooks, events |
| **[Discount Codes](docs/discounts.md)** | Creating codes, auto-apply, validate, audit trail, model API, idempotency |
| **[Sandbox Mode](docs/sandbox.md)** | Environment sandbox, per-user bypass, confirm endpoint, QA flows |
| **[Logging](docs/logging.md)** | Channel routing, log levels, redaction, recipes for Slack/Telegram/DB/ClickHouse |
| **[Key Fingerprinting](docs/key-fingerprinting.md)** | Post-rotation auditing, database column, log context, custom gateways |
| **[Custom Gateways](docs/custom-gateways.md)** | Interface, registration, PaymentLogger usage, what you get for free |
| **[Fanbasis](docs/gateways/fanbasis.md)** | All checkout modes, full API suite, webhook event reference |
| **[Match2Pay](docs/gateways/match2pay.md)** | Crypto checkout, cryptocurrency reference, withdrawal API, wallet expiry |
| **[Rebornpay](docs/gateways/rebornpay.md)** | UPI/IMPS, INR conversion, status checks, UTR storage, signature verification |

---

## Install

```bash
composer require subtain/laravel-payments
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

---

## Quick Start

### 1. Set gateway credentials

```env
FANBASIS_API_KEY=your-key
FANBASIS_WEBHOOK_SECRET=your-secret
```

Each gateway has its own env vars. See the gateway docs linked above.

### 2. Initiate a payment

```php
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$result = app(PaymentService::class)->initiate(
    gateway: 'fanbasis',
    request: new CheckoutRequest(
        amount:      299.00,
        productName: 'Pro Plan',
        webhookUrl:  route('payments.webhook', 'fanbasis'),
        successUrl:  'https://app.com/success',
        invoiceId:   (string) $order->id,
    ),
    payable: $order,
);

return redirect($result->redirectUrl);
```

The package creates an `lp_payments` record, calls the gateway, and returns the checkout URL. When the webhook arrives, it verifies the signature, updates the payment status, and dispatches events — all automatically.

### 3. Handle the result

```php
// In your EventServiceProvider
protected $listen = [
    \Subtain\LaravelPayments\Events\PaymentSucceeded::class => [
        \App\Listeners\FulfillOrder::class,
    ],
    \Subtain\LaravelPayments\Events\PaymentFailed::class => [
        \App\Listeners\NotifyCustomer::class,
    ],
];
```

```php
class FulfillOrder
{
    public function handle(PaymentSucceeded $event): void
    {
        $payment = $event->payment;   // Payment model
        $order   = $payment->payable; // your Order model

        // Provision accounts, send emails, etc.
    }
}
```

**[→ Full payment documentation](docs/payments.md)**

---

## Gateways

### Fanbasis

```php
use Subtain\LaravelPayments\Facades\Payment;

// One-time
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00, productName: 'Pro Plan',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    successUrl: 'https://app.com/success',
));

// Subscription
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 29.99, productName: 'Monthly',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    extra: ['subscription' => ['frequency_days' => 30]],
));

// Full API access
$fb = Payment::gateway('fanbasis');
$fb->customers()->list(['search' => 'jane@example.com']);
$fb->refunds()->create('txn_abc');
$fb->webhooks()->create([...]);
```

**[→ Full Fanbasis documentation](docs/gateways/fanbasis.md)**

### Match2Pay (Crypto)

```php
Payment::gateway('match2pay')->checkout(new CheckoutRequest(
    amount: 299.00, currency: 'USD',
    webhookUrl: route('payments.webhook', 'match2pay'),
    successUrl: 'https://app.com/success',
    extra: [
        'payment_currency'     => 'USX',        // USDT TRC20
        'payment_gateway_name' => 'USDT TRC20', // omit for 2-step selection
    ],
));
```

**[→ Full Match2Pay documentation](docs/gateways/match2pay.md)**

### Rebornpay (UPI / IMPS — India)

```php
Payment::gateway('rebornpay')->checkout(new CheckoutRequest(
    amount: 92000.00, currency: 'INR',
    invoiceId: 'order_123', customerName: 'Raj Kumar',
    successUrl: 'https://app.com/success',
));
```

**[→ Full Rebornpay documentation](docs/gateways/rebornpay.md)**

### PremiumPay

```php
Payment::gateway('premiumpay')->checkout(new CheckoutRequest(
    amount: 99.00, invoiceId: 'inv_456',
    customerEmail: 'user@example.com',
    customerIp: request()->ip(),
    productName: 'Starter Package',
    successUrl: 'https://app.com/success',
    webhookUrl: route('payments.webhook', 'premiumpay'),
));
```

---

## Discount Codes

Gateway-agnostic. The package validates, calculates the discount, and records usage after webhook confirmation — automatically.

```php
// Pass discountCode on the CheckoutRequest — that's all
$result = app(PaymentService::class)->initiate(
    gateway: 'fanbasis',
    request: new CheckoutRequest(
        amount:       299.00,
        webhookUrl:   route('payments.webhook', 'fanbasis'),
        discountCode: $request->input('discount_code'), // 'LAUNCH50'
        userId:       auth()->id(),
    ),
    payable: $order,
);
// Gateway receives 249.00. usage is auto-recorded on successful webhook.
```

```php
use Subtain\LaravelPayments\Models\DiscountCode;

DiscountCode::create([
    'code' => 'LAUNCH50', 'type' => 'fixed', 'value' => 50,
    'max_total_uses' => 100, 'max_uses_per_user' => 1,
]);
```

**[→ Full discount documentation](docs/discounts.md)**

---

## Sandbox Mode

Simulate the full payment flow without real charges. Every DB record, log, and event fires identically.

```env
# .env.local or .env.staging
PAYMENTS_SANDBOX=true
```

QA trigger endpoint (simulates a successful webhook):
```
GET /payments/webhook/sandbox/confirm/{invoice_id}
```

Per-user bypass on production (internal QA accounts):
```php
'sandbox' => [
    'bypass_user_ids' => [1, 42],
    'bypass_roles'    => ['qa_tester'],
],
```

**[→ Full sandbox documentation](docs/sandbox.md)**

---

## Logging

Zero config required. Logs to your app's default channel out of the box.

```php
// config/lp_payments.php
'logging' => [
    'level'    => env('PAYMENTS_LOG_LEVEL', 'info'),
    'channels' => [
        'default'   => env('PAYMENTS_LOG_CHANNEL', null),
        'match2pay' => 'slack',     // route a gateway to Slack
        'rebornpay' => 'telegram',  // or Telegram
    ],
    'redact' => ['api_key', 'secret', 'token', 'password'],
],
```

Log format:
```
[payments:fanbasis:checkout] checkout.initiated
[payments:match2pay:webhook] webhook.signature_failed
```

**[→ Full logging documentation](docs/logging.md)**

---

## API Key Fingerprinting

Every `lp_payments` row stores a `first4****last4` fingerprint of the API key active at initiation time. Solve post-rotation audit questions instantly.

```php
$payment->key_fingerprint;  // "sk_l****z789"
Payment::where('key_fingerprint', 'sk_l****z789')->get();
```

**[→ Full key fingerprinting documentation](docs/key-fingerprinting.md)**

---

## Add a Custom Gateway

```php
// 1. Implement the interface (3 methods)
class StripeGateway implements PaymentGateway
{
    public function name(): string { return 'stripe'; }
    public function checkout(CheckoutRequest $request): CheckoutResult { /* ... */ }
    public function parseWebhook(array $payload): WebhookResult { /* ... */ }
    public function verifyWebhook(array $payload, array $headers = []): bool { /* ... */ }
}

// 2. Register in config/lp_payments.php
'stripe' => [
    'driver'     => \App\Gateways\StripeGateway::class,
    'api_key'    => env('STRIPE_API_KEY'),
    'key_fields' => ['api_key'],
],

// 3. Use it — identical to built-in gateways
Payment::gateway('stripe')->checkout($checkoutRequest);
app(PaymentService::class)->initiate('stripe', $checkoutRequest, $order);
```

Your gateway automatically gets DB tracking, webhook handling, sandbox support, logging, key fingerprinting, and discounts.

**[→ Full custom gateway documentation](docs/custom-gateways.md)**

---

## Testing

```php
Payment::shouldReceive('gateway->checkout')
    ->andReturn(new CheckoutResult(redirectUrl: 'https://test.com', gateway: 'fanbasis'));
```

For full integration tests, use `PAYMENTS_SANDBOX=true` in `phpunit.xml` and hit the sandbox confirm endpoint to simulate successful payments.

---

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12

---

## License

MIT — [LICENSE](LICENSE)

## Author

**Syed Subtain Haider** — [GitHub](https://github.com/subtain-haider)
