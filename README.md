# Laravel Payments

A unified payment SDK for Laravel. Write your payment logic once — switch gateways without changing a line of application code.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## Why This Package?

Every payment gateway has its own API, webhook format, and quirks. This package abstracts all of that behind a **single 3-method interface** so your application logic stays clean regardless of which PSP you use today or migrate to tomorrow.

- **One interface for all gateways** — `checkout()`, `parseWebhook()`, `verifyWebhook()`
- **Payment records** — `lp_payments` table tracks every attempt with polymorphic ownership
- **Webhook handling** — automatic signature verification, status updates, event dispatch
- **Status machine** — `pending → processing → paid → refunded` with guard rails
- **API key fingerprinting** — every payment record and log entry captures which key version was active, enabling post-rotation auditing
- **Built-in discount codes** — gateway-agnostic, with usage limits, validity windows, and audit trail
- **Extensible** — add any PSP in 3 steps (implement interface, register in config, use it)

---

## Supported Gateways

| Gateway | Type | Checkout | Webhooks | Full API | Docs |
|---|---|---|---|---|---|
| **[Fanbasis](docs/gateways/fanbasis.md)** | Cards, MoR | Dynamic, Embedded, Static, Subscriptions | 12 event types, HMAC-SHA256 | Customers, Subscribers, Discount Codes, Products, Transactions, Refunds, Webhooks | [Full docs →](docs/gateways/fanbasis.md) |
| **PremiumPay** | Cards | API checkout | Callback-based | Checkout only | See below |
| **[Match2Pay](docs/gateways/match2pay.md)** | Crypto (USDT, BTC, ETH, BNB, etc.) | API checkout, 2-step selection | SHA-384 signature (DONE only) | Deposits, Withdrawals | [Full docs →](docs/gateways/match2pay.md) |
| **[Rebornpay](docs/gateways/rebornpay.md)** | UPI / IMPS (India) | API checkout | MD5 + Python-repr signature | Pay-in, Transaction status, UTR storage | [Full docs →](docs/gateways/rebornpay.md) |
| *Your gateway* | *Any* | *Implement `PaymentGateway`* | *Your logic* | *Your logic* | [Add a gateway →](#add-a-custom-gateway) |

> More PSPs are coming. Each gateway gets a full dedicated implementation as we add it. Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Install

```bash
composer require subtain/laravel-payments
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

## Quick Start

### 1. Set your gateway credentials

```env
PAYMENT_GATEWAY=fanbasis          # or premiumpay, match2pay, rebornpay, your-custom-gateway

# Each gateway has its own env vars — see the gateway docs
FANBASIS_API_KEY=your-key
FANBASIS_WEBHOOK_SECRET=your-secret
```

### 2. Create a checkout

```php
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

// With DB tracking (recommended)
$result = app(PaymentService::class)->initiate('fanbasis', new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    successUrl: 'https://app.com/success',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    metadata: ['invoice_id' => $order->id],
), $order);

return redirect($result->redirectUrl);
```

```php
use Subtain\LaravelPayments\Facades\Payment;

// Lightweight (no DB record)
$result = Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    webhookUrl: route('payments.webhook', 'fanbasis'),
));
```

### 3. Handle webhooks

Webhooks are handled automatically at `POST /payments/webhook/{gateway}`. The package verifies the signature, updates payment status, and dispatches events.

```php
// EventServiceProvider
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
        $invoiceId = $event->result->invoiceId;  // from your metadata
        $payment   = $event->payment;            // Payment model (nullable)
    }
}
```

---

## Gateway Quick Reference

### Fanbasis

Full API integration — one-time, subscriptions, embedded checkout, discount codes, customers, refunds, and more.

```php
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    webhookUrl: route('payments.webhook', 'fanbasis'),
));
```

**[→ Full Fanbasis documentation](docs/gateways/fanbasis.md)** — checkout modes, subscriptions, embedded checkout, full API access, webhook event reference.

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

Crypto payments supporting USDT, BTC, ETH, BNB, and 40+ cryptocurrencies across all major networks.

```php
Payment::gateway('match2pay')->checkout(new CheckoutRequest(
    amount:     299.00,
    currency:   'USD',
    webhookUrl: route('payments.webhook', 'match2pay'),
    successUrl: 'https://app.com/payment/success',
    extra: [
        'payment_currency'     => 'USX',         // USDT TRC20
        'payment_gateway_name' => 'USDT TRC20',  // omit both for 2-step selection
    ],
));
```

**[→ Full Match2Pay documentation](docs/gateways/match2pay.md)** — checkout flows, cryptocurrency reference, withdrawal API, callback signature verification, wallet expiry.

### Rebornpay (UPI / IMPS — India)

Indian UPI and IMPS payments. Amounts should be sent in INR.

```php
Payment::gateway('rebornpay')->checkout(new CheckoutRequest(
    amount:       92000.00,        // INR amount
    currency:     'INR',
    invoiceId:    'order_123',     // used for webhook reconciliation
    customerName: 'Raj Kumar',
    successUrl:   'https://app.com/payment/success',
));
```

**[→ Full Rebornpay documentation](docs/gateways/rebornpay.md)** — checkout, INR conversion, IMPS, transaction status checks, UTR storage, signature verification.

---

## Logging

All log output from every gateway, HTTP client, webhook handler, and service flows through a single central class — `PaymentLogger`. You control where logs go, at what level, and what sensitive data is masked, all from `config/lp_payments.php`.

**Zero configuration required.** Out of the box, the package logs to the same channel as the rest of your application.

### Quick Setup

```php
// config/lp_payments.php
'logging' => [
    'enabled' => true,
    'level'   => env('PAYMENTS_LOG_LEVEL', 'info'),  // 'debug' in local, 'info' in prod
    'channels' => [
        'default' => env('PAYMENTS_LOG_CHANNEL', null),  // null = app's default channel
        // 'match2pay'  => 'slack',        // route a gateway to Slack
        // 'rebornpay'  => 'telegram',     // or Telegram
        // 'fanbasis'   => 'clickhouse',   // or ClickHouse
        // 'default'    => 'payments',     // or a dedicated file
    ],
    'levels' => [
        // 'match2pay' => 'debug',  // per-gateway level override
    ],
    'redact' => [
        'api_key', 'api_token', 'secret', 'signature', 'password', 'token',
        // add any sensitive fields from your custom gateways
    ],
],
```

### Log message format

Every entry is prefixed for easy filtering:

```
[payments:match2pay:checkout] checkout.initiated
[payments:fanbasis:api] api.error
[payments:rebornpay:webhook] webhook.signature_failed
```

### What gets logged

| Category | Events |
|---|---|
| `checkout` | initiated, success, empty_url, http_error, gateway_error |
| `webhook` | parsed, verification_skipped, missing_signature, signature_failed |
| `api` | request (debug), response (debug), error, exception |

### Custom gateways

Use `PaymentLogger` in your own gateway implementations so they respect the same routing and level config:

```php
use Subtain\LaravelPayments\PaymentLogger;

PaymentLogger::info('checkout.initiated', ['invoice_id' => $id], gateway: 'stripe', category: 'checkout');
PaymentLogger::error('checkout.failed',   ['error' => $e->getMessage()], gateway: 'stripe');
```

**[→ Full logging documentation](docs/logging.md)** — all config options, recipes for Slack, Telegram, DB, ClickHouse, custom redaction, channel resolution logic, and how to use `PaymentLogger` in your own gateway.

---

## Core Features

### Payment Model

```php
use Subtain\LaravelPayments\Models\Payment;

$payment = Payment::findByInvoiceId('inv_123');
$payment->isPaid();
$payment->markAsPaid('txn_abc');
$payment->markAsRefunded();
$payment->payable;  // polymorphic owner (Order, User, etc.)
$payment->logs;     // webhook audit trail
```

### Status Machine

```
pending → processing → paid → refunded
pending → failed → pending (retry)
pending → cancelled (terminal)
```

### HasPayments Trait

```php
use Subtain\LaravelPayments\Traits\HasPayments;

class Order extends Model { use HasPayments; }

$order->payments;
$order->hasPaidPayment();
$order->latestPayment();
```

### API Key Fingerprinting

The package automatically captures a **non-reversible fingerprint** (`first4****last4`) of the gateway API key at the moment every payment is initiated. This solves the post-rotation audit problem: after a key change, you can look at any historical payment and know exactly which key version processed it.

**No configuration required** — works automatically for all built-in gateways.

#### In the database

Every row in `lp_payments` has a `key_fingerprint` column:

```php
$payment = Payment::findByInvoiceId('inv_123');
echo $payment->key_fingerprint;  // "sk_l****7890"
```

Query all payments processed with a specific key:

```php
Payment::where('key_fingerprint', 'sk_l****7890')->get();
```

#### In the logs

Every log entry automatically includes a `_key_fingerprints` context array — no extra code needed:

```
[payments:fanbasis:checkout] checkout.initiated
context: {
    "invoice_id": "inv_123",
    "_key_fingerprints": {
        "api_key": "sk_l****7890",
        "webhook_secret": "whs_****3z8x"
    }
}
```

#### Security properties

- **Non-reversible** — the fingerprint cannot be used to reconstruct the original key.
- **No key storage** — only the `first4****last4` mask is ever persisted or logged.
- Fingerprints are computed **after** log redaction — raw key values never appear in output.

#### Custom gateways

Add `key_fields` to your gateway config so fingerprinting picks up your credentials:

```php
// config/lp_payments.php
'my_gateway' => [
    'driver'     => \App\Gateways\MyGateway::class,
    'api_key'    => env('MY_GATEWAY_KEY'),
    'api_secret' => env('MY_GATEWAY_SECRET'),
    'key_fields' => ['api_key', 'api_secret'],  // ← add this
],
```

If `key_fields` is omitted, the package falls back to checking `['api_key', 'api_token', 'secret', 'postback_key']`.

### Events

| Event | Trigger |
|---|---|
| `PaymentSucceeded` | Payment confirmed |
| `PaymentFailed` | Payment failed |
| `WebhookReceived` | Any webhook (all statuses) |

---

## Built-in Discount Codes

A gateway-agnostic discount code system. The package validates, calculates, and records usage automatically — the gateway only ever receives the final discounted amount.

**[→ Full discount documentation](docs/discounts.md)** — all scenarios, auto vs manual mode, model API, audit trail, idempotency.

### Setup

```bash
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

Enable automatic usage recording in `.env`:

```env
PAYMENTS_AUTO_RECORD_DISCOUNT=true
```

### The One-Liner Checkout (Recommended)

Pass `discountCode` and `userId` on `CheckoutRequest`. The package does everything else.

```php
$result = app(PaymentService::class)->initiate(
    gateway: 'fanbasis',
    request: new CheckoutRequest(
        amount:       299.00,
        productName:  'Pro Challenge',
        webhookUrl:   route('payments.webhook', 'fanbasis'),
        successUrl:   $redirectUrl,
        discountCode: $request->input('discount_code'), // e.g. 'LAUNCH50'
        userId:       auth()->id(),
    ),
    payable: $order,
);
```

**What the package does automatically:**
1. Validates the code — throws `ValidationException` with a specific message if invalid
2. Calculates: 299.00 → 249.00
3. Sends 249.00 to the gateway (gateway never sees the code)
4. Stores `discount_code_id`, `discount_amount`, `user_id` on `lp_payments`
5. When webhook confirms → increments `times_used` and writes audit row (idempotent)

### Creating Discount Codes

```php
use Subtain\LaravelPayments\Models\DiscountCode;

// $50 off, max 100 uses, one per user, expires end of year
DiscountCode::create([
    'code'              => 'LAUNCH50',
    'type'              => 'fixed',
    'value'             => 50,
    'max_total_uses'    => 100,
    'max_uses_per_user' => 1,
    'expires_at'        => '2026-12-31 23:59:59',
]);

// 20% off, max $500 savings, valid this week only
DiscountCode::create([
    'code'                => 'FLASH20',
    'type'                => 'percentage',
    'value'               => 20,
    'max_discount_amount' => 500,
    'starts_at'           => '2026-04-14 00:00:00',
    'expires_at'          => '2026-04-20 23:59:59',
]);

// 15% off, Fanbasis only
DiscountCode::create([
    'code'     => 'CARD15',
    'type'     => 'percentage',
    'value'    => 15,
    'gateways' => ['fanbasis'],
]);
```

### Validation Rule (Form Request)

```php
use Subtain\LaravelPayments\Rules\ValidDiscountCode;

'discount_code' => ['nullable', 'string', new ValidDiscountCode(
    userId:  $this->user()?->id,
    amount:  299.00,
    gateway: 'fanbasis',
)],
```

### Model API

```php
$code = DiscountCode::findByCode('LAUNCH50');  // case-insensitive

$code->redeemable(userId: 42, amount: 299.00); // true or "Discount code has expired."
$code->isRedeemable(userId: 42, amount: 299.00); // bool
$code->calculateDiscount(299.00);               // 50.00
$code->usages;                                  // audit trail

DiscountCode::valid()->get();                   // only currently valid codes
```

**[→ Full discount documentation](docs/discounts.md)**

---

## Add a Custom Gateway

```php
// 1. Implement the interface
class StripeGateway implements PaymentGateway
{
    public function name(): string { return 'stripe'; }
    public function checkout(CheckoutRequest $request): CheckoutResult { /* ... */ }
    public function parseWebhook(array $payload): WebhookResult { /* ... */ }
    public function verifyWebhook(array $payload, array $headers = []): bool { /* ... */ }
}

// 2. Register in config/lp_payments.php
'stripe' => [
    'driver' => \App\Gateways\StripeGateway::class,
    'secret' => env('STRIPE_SECRET'),
],

// 3. Use it
Payment::gateway('stripe')->checkout($request);
```

---

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
