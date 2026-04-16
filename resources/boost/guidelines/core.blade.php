## subtain/laravel-payments

A unified payment SDK for Laravel. One interface for all gateways — `checkout()`, `parseWebhook()`, `verifyWebhook()`. Supports Fanbasis, PremiumPay, Match2Pay, and any custom gateway.

### Install

```bash
composer require subtain/laravel-payments
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

### Two ways to initiate a payment

**With DB tracking (always prefer this in production):**
```php
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$result = app(PaymentService::class)->initiate('fanbasis', new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    customerEmail: $user->email,
    successUrl: route('checkout.success'),
    webhookUrl: route('payments.webhook', 'fanbasis'),
    metadata: ['order_id' => (string) $order->id],
), $order); // $order is the polymorphic payable (any Eloquent model)

return redirect($result->redirectUrl);
```

**Lightweight (no DB record):**
```php
use Subtain\LaravelPayments\Facades\Payment;

$result = Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    webhookUrl: route('payments.webhook', 'fanbasis'),
));
```

### CheckoutRequest — all fields

```php
new CheckoutRequest(
    amount: 299.00,              // required — float, major units ($299.00)
    currency: 'USD',             // default 'USD'
    invoiceId: 'inv_123',        // your internal ID (auto-generated if omitted)
    customerEmail: 'a@b.com',
    customerName: 'John Doe',
    customerIp: request()->ip(), // required by PremiumPay
    productName: 'Pro Plan',
    productDescription: '...',
    successUrl: 'https://...',
    cancelUrl: 'https://...',
    webhookUrl: route('payments.webhook', 'gateway-name'), // always use this route
    metadata: ['key' => 'value'],  // passed through to webhook events
    extra: ['payment_currency' => 'USX'], // gateway-specific fields
)
```

### Gateway names

| Gateway | String key | Notes |
|---|---|---|
| Fanbasis | `'fanbasis'` | Cards, MoR. Full API. |
| PremiumPay | `'premiumpay'` | Cards. Requires `customerIp`. |
| Match2Pay | `'match2pay'` | Crypto (USDT etc). Pass `extra: ['payment_currency' => 'USX', 'payment_gateway_name' => 'USDT TRC20']`. |

### Webhook route

Webhooks are handled automatically. Register once and all gateways use it:
```
POST /payments/webhook/{gateway}
```
This route is published by the package. Do NOT create your own webhook routes for this package.

### Listening to payment events

```php
// In any module's EventServiceProvider:
use Subtain\LaravelPayments\Events\PaymentSucceeded;
use Subtain\LaravelPayments\Events\PaymentFailed;
use Subtain\LaravelPayments\Events\WebhookReceived;

protected $listen = [
    PaymentSucceeded::class => [
        \Modules\Order\app\Listeners\FulfillOrder::class,
    ],
    PaymentFailed::class => [
        \Modules\Order\app\Listeners\HandleFailedPayment::class,
    ],
];
```

In the listener:
```php
public function handle(PaymentSucceeded $event): void
{
    $orderId = $event->result->invoiceId;    // from your metadata or invoiceId
    $payment = $event->payment;              // Payment model (nullable if no DB tracking)
    $gateway = $event->result->gateway;      // 'fanbasis', 'match2pay', etc.
}
```

### Payment model

```php
use Subtain\LaravelPayments\Models\Payment;

$payment = Payment::findByInvoiceId('inv_123');
$payment->isPaid();           // bool
$payment->markAsPaid($txnId);
$payment->markAsRefunded();
$payment->payable;            // polymorphic owner (Order, User, etc.)
$payment->logs;               // webhook audit trail
```

Status machine: `pending → processing → paid → refunded` / `pending → failed`

### HasPayments trait (on your models)

```php
use Subtain\LaravelPayments\Traits\HasPayments;

class Order extends Model
{
    use HasPayments;
}

$order->payments;           // all payments for this order
$order->hasPaidPayment();   // bool
$order->latestPayment();    // most recent Payment model
```

### Discount codes

```php
use Subtain\LaravelPayments\DiscountService;

$service = app(DiscountService::class);

// Validate only (throws ValidationException if invalid)
$code = $service->validate(code: 'LAUNCH50', amount: 299.00, userId: $user->id, gateway: 'fanbasis');

// Validate + calculate final amount
$result = $service->apply(code: 'LAUNCH50', amount: 299.00, userId: $user->id);
$result->finalAmount;    // 249.00
$result->discountAmount; // 50.00

// Record usage after payment succeeds
$service->recordUsage(result: $result, userId: $user->id, payable: $order);
```

Use in Form Request validation:
```php
use Subtain\LaravelPayments\Rules\ValidDiscountCode;

'discount_code' => ['nullable', 'string', new ValidDiscountCode(userId: $this->user()?->id, amount: 299.00)],
```

### Adding a custom gateway

```php
// 1. Implement PaymentGateway interface
class StripeGateway implements \Subtain\LaravelPayments\Contracts\PaymentGateway
{
    public function name(): string { return 'stripe'; }
    public function checkout(CheckoutRequest $request): CheckoutResult { ... }
    public function parseWebhook(array $payload): WebhookResult { ... }
    public function verifyWebhook(array $payload, array $headers = []): bool { ... }
}

// 2. Register in config/payments.php
'stripe' => ['driver' => StripeGateway::class, 'secret' => env('STRIPE_SECRET')],

// 3. Use it — no other changes needed
Payment::gateway('stripe')->checkout($request);
```

### Common mistakes to avoid

```php
// ❌ NEVER call the gateway directly without PaymentService — you lose DB tracking
$gateway = new FanbasisGateway();
$gateway->checkout($request);

// ❌ NEVER hardcode the gateway name — read from config
$gateway = 'fanbasis'; // hardcoded

// ✅ CORRECT — use PaymentService for DB tracking
app(PaymentService::class)->initiate(config('payments.default'), $request, $payable);

// ✅ CORRECT — webhook URL always uses the package route
webhookUrl: route('payments.webhook', $gatewayName),
```
