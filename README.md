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

### What You Need from Fanbasis

1. **API Key** — go to your [FanBasis Dashboard → API Keys](https://www.fanbasis.com). Required for all API calls.
2. **Webhook Secret** — returned once when you create a webhook subscription (via API or dashboard). Used for HMAC-SHA256 signature verification.
3. **Creator Handle** — your FanBasis username/handle (visible in your profile URL). Only needed for embedded checkout URLs.

That's it. No OAuth, no client ID/secret pairs, no separate sandbox keys — Fanbasis uses a single API key + separate test/live environments.

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
    metadata: ['invoice_id' => 'inv_123'],  // sent as api_metadata in webhooks
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

### Fanbasis Discount Codes (Gateway-Level)

```php
// Pre-apply a Fanbasis discount code at checkout
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    extra: ['discount_code' => 'SUMMER20'],
));

// Let customer enter their own Fanbasis code on checkout page
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    extra: ['allow_discount_codes' => true],
));
```

> **Note:** Fanbasis discount codes are scoped to specific products via `service_ids` and managed through the Fanbasis API. For a **gateway-agnostic** discount code system that you control, see [Built-in Discount Codes](#built-in-discount-codes) below.

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

### Webhook Handling

The package handles all 12 Fanbasis webhook event types:

| Event | Package Status |
|---|---|
| `payment.succeeded` | `PAID` |
| `payment.failed` | `FAILED` |
| `payment.expired` | `CANCELLED` |
| `payment.canceled` | `CANCELLED` |
| `product.purchased` | `PAID` |
| `subscription.created` | `PAID` |
| `subscription.renewed` | `PAID` |
| `subscription.completed` | `CANCELLED` |
| `subscription.canceled` | `CANCELLED` |
| `refund.created` | `REFUNDED` |
| `dispute.created` | `FAILED` |
| `dispute.updated` | varies (`won`→PAID, `lost`→REFUNDED) |

**Signature verification** is automatic when `FANBASIS_WEBHOOK_SECRET` is set. Uses HMAC-SHA256 on the raw request body per [Fanbasis docs](https://apidocs.fan/#webhooks).

**Metadata round-trip:** Pass `metadata` in checkout → Fanbasis returns it as `api_metadata` in webhooks. The package handles this automatically — your `invoice_id` comes back in `$event->result->invoiceId`.

Manual verification:

```php
use Subtain\LaravelPayments\Gateways\Fanbasis\WebhooksService;

WebhooksService::verifySignature($request->getContent(), $request->header('x-webhook-signature'), $secret);
```

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
