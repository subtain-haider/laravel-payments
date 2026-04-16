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

All log output from every gateway, HTTP client, webhook handler, and service flows through a single central class — `PaymentLogger`. You control where logs go, at what level, and what sensitive data is masked, all from `config/payments.php`.

**Zero configuration required.** Out of the box, the package logs to the same channel as the rest of your application.

### Quick Setup

```php
// config/payments.php
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

### Events

| Event | Trigger |
|---|---|
| `PaymentSucceeded` | Payment confirmed |
| `PaymentFailed` | Payment failed |
| `WebhookReceived` | Any webhook (all statuses) |

---

## Built-in Discount Codes

A gateway-agnostic discount code system that lives in your database. You control the codes, validation, and limits — the payment gateway just receives the final discounted amount.

### Publish Migrations

```bash
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

This creates two tables (names configurable in `config/payments.php`):

| Table | Purpose |
|---|---|
| `lp_discount_codes` | Discount code definitions |
| `lp_discount_code_usages` | Usage audit trail (who used what, when, amounts) |

### Creating Discount Codes

```php
use Subtain\LaravelPayments\Models\DiscountCode;

// 99% off, unlimited uses (testing)
DiscountCode::create([
    'code'        => 'TEST99',
    'description' => 'Internal testing code',
    'type'        => 'percentage',
    'value'       => 99,
]);

// $50 off, max 100 total uses, one per user
DiscountCode::create([
    'code'               => 'LAUNCH50',
    'description'        => 'Launch promo — $50 off',
    'type'               => 'fixed',
    'value'              => 50,
    'max_total_uses'     => 100,
    'max_uses_per_user'  => 1,
    'expires_at'         => '2026-12-31 23:59:59',
]);

// 20% off (max $500 savings), valid only this week
DiscountCode::create([
    'code'                => 'FLASH20',
    'type'                => 'percentage',
    'value'               => 20,
    'max_discount_amount' => 500,
    'starts_at'           => '2026-04-14 00:00:00',
    'expires_at'          => '2026-04-20 23:59:59',
]);

// $10 off, minimum $100 order
DiscountCode::create([
    'code'             => 'SAVE10',
    'type'             => 'fixed',
    'value'            => 10,
    'min_order_amount' => 100,
]);

// 15% off, only for Fanbasis payments
DiscountCode::create([
    'code'     => 'FANBASIS15',
    'type'     => 'percentage',
    'value'    => 15,
    'gateways' => ['fanbasis'],
]);

// $25 off, valid for Fanbasis and PremiumPay only
DiscountCode::create([
    'code'     => 'MULTI25',
    'type'     => 'fixed',
    'value'    => 25,
    'gateways' => ['fanbasis', 'premiumpay'],
]);
```

### All Discount Code Fields

| Field | Type | Default | Description |
|---|---|---|---|
| `code` | string, unique | required | The code customers enter |
| `description` | string, nullable | null | Internal note |
| `type` | `percentage` or `fixed` | required | Discount type |
| `value` | decimal | required | 20 = 20% off or $20 off |
| `min_order_amount` | decimal, nullable | null (no min) | Minimum order to apply |
| `max_discount_amount` | decimal, nullable | null (no cap) | Cap on savings |
| `max_total_uses` | int, nullable | null (unlimited) | Global redemption limit |
| `max_uses_per_user` | int, nullable | null (unlimited) | Per-user redemption limit |
| `times_used` | int | 0 | Auto-incremented counter |
| `starts_at` | timestamp, nullable | null (immediate) | Not valid before this |
| `expires_at` | timestamp, nullable | null (never) | Not valid after this |
| `gateways` | JSON array, nullable | null (all gateways) | Restrict to specific gateways (e.g. `["fanbasis"]`) |
| `active` | bool | true | On/off toggle |

### Validating a Code

```php
use Subtain\LaravelPayments\DiscountService;

$discountService = app(DiscountService::class);

// Throws ValidationException with specific message if invalid
$discountCode = $discountService->validate(
    code: 'LAUNCH50',
    amount: 299.00,
    userId: $user->id,   // optional, for per-user limits
    gateway: 'fanbasis', // optional, for gateway-scoped codes
);
```

Validation checks (in order):
1. Code exists
2. Code is active
3. Not before `starts_at`
4. Not after `expires_at`
5. Not exceeded `max_total_uses`
6. Order amount >= `min_order_amount`
7. Gateway is in `gateways` list (if set)
8. User hasn't exceeded `max_uses_per_user`

### Applying a Code (Validate + Calculate)

```php
$result = $discountService->apply(
    code: 'LAUNCH50',
    amount: 299.00,
    userId: $user->id,
    gateway: 'fanbasis',  // optional
);

$result->discountCode;   // DiscountCode model
$result->originalAmount; // 299.00
$result->discountAmount; // 50.00
$result->finalAmount;    // 249.00
$result->toArray();      // ['discount_code' => 'LAUNCH50', 'discount_type' => 'fixed', ...]
```

### Recording Usage (After Payment)

Call this after the payment succeeds (e.g. in your order fulfillment listener):

```php
$discountService->recordUsage(
    result: $result,
    userId: $user->id,
    payable: $order,  // any Eloquent model (polymorphic)
);
```

This increments `times_used` on the discount code and creates an audit trail record in `lp_discount_code_usages`.

### Full Checkout Flow Example

```php
use Subtain\LaravelPayments\DiscountService;
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

// 1. Determine price
$amount = $challenge->price;  // 299.00
$discountResult = null;

if ($discountCode = $request->input('discount_code')) {
    $discountResult = app(DiscountService::class)->apply(
        code: $discountCode,
        amount: $amount,
        userId: auth()->id(),
    );
    $amount = $discountResult->finalAmount;  // 249.00
}

// 2. Create order with discounted amount
$order = Order::create(['amount' => $amount, ...]);

// 3. Initiate payment with final amount
$result = app(PaymentService::class)->initiate('fanbasis', new CheckoutRequest(
    amount: $amount,
    productName: $challenge->name,
    metadata: [
        'invoice_id'    => (string) $order->id,
        'discount_code' => $discountCode,
    ],
    webhookUrl: route('payments.webhook', 'fanbasis'),
    successUrl: $redirectUrl,
), $order);

// 4. After payment succeeds (in listener), record usage
if ($discountResult) {
    app(DiscountService::class)->recordUsage(
        result: $discountResult,
        userId: $user->id,
        payable: $order,
    );
}
```

### Validation Rule

Use in Form Requests to validate the discount code as part of the request:

```php
use Subtain\LaravelPayments\Rules\ValidDiscountCode;

public function rules(): array
{
    return [
        'discount_code' => ['nullable', 'string', new ValidDiscountCode(
            userId: $this->user()?->id,
            amount: 299.00,      // or resolve dynamically
            gateway: 'fanbasis', // optional, for gateway-scoped codes
        )],
    ];
}
```

### Model API

```php
use Subtain\LaravelPayments\Models\DiscountCode;

// Find by code (case-insensitive)
$code = DiscountCode::findByCode('LAUNCH50');

// Check redeemability (returns true or error string)
$result = $code->redeemable(userId: 42, amount: 299.00);
if ($result !== true) {
    echo $result; // "Discount code has expired."
}

// Boolean shortcut
$code->isRedeemable(userId: 42, amount: 299.00);

// Calculate discount amount
$code->calculateDiscount(299.00); // 50.00

// Query scope — only valid codes
DiscountCode::valid()->get();

// Relationships
$code->usages;  // Collection of DiscountCodeUsage
$code->usages()->where('user_id', 42)->count();
```

### Extending with App-Specific Scoping

The package handles generic discount logic. If your app needs **product/challenge-specific** or **user-group-specific** codes, extend the model:

```php
// In your app
class AppDiscountCode extends \Subtain\LaravelPayments\Models\DiscountCode
{
    public function challenges()
    {
        return $this->belongsToMany(Challenge::class, 'discount_code_challenge');
    }

    public function redeemable(?int $userId = null, ?float $amount = null): true|string
    {
        $base = parent::redeemable($userId, $amount);
        if ($base !== true) return $base;

        // Add your custom checks here
        return true;
    }
}
```

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

// 2. Register in config/payments.php
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
