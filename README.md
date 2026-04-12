# Laravel Payments

A complete multi-gateway payment package for Laravel. Handles gateway communication, payment records, webhook processing, status tracking, and audit logging — so you don't have to.

Ships with **Fanbasis**, **PremiumPay**, and **Match2Pay**. Adding your own gateway takes 3 steps.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## Features

- **One interface, any gateway** — every gateway implements the same 3-method contract
- **Payment records** — `lp_payments` table tracks every payment attempt with polymorphic owner
- **Webhook audit log** — `lp_payment_logs` table logs every webhook, status change, and error
- **Status machine** — `pending → processing → paid → refunded` with guard rails
- **Automatic webhook handling** — finds payment, updates status, logs payload, dispatches events
- **Idempotent webhooks** — duplicate webhooks are silently skipped
- **Gateway-agnostic DTOs** — `CheckoutRequest`, `CheckoutResult`, `WebhookResult`
- **Event-driven** — `PaymentSucceeded`, `PaymentFailed`, `WebhookReceived`
- **Polymorphic ownership** — attach payments to any model (Order, User, Subscription, etc.)
- **`HasPayments` trait** — add `$model->payments()`, `$model->hasPaidPayment()` to any model
- **Configurable table names** — no conflicts with your existing schema
- **Laravel 10, 11 & 12** compatible

## Installation

```bash
composer require subtain/laravel-payments
```

The package auto-discovers its service provider and facade.

### Publish Config & Migrations

```bash
php artisan vendor:publish --tag=payments-config
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

## Quick Start

### 1. Set Environment Variables

```env
PAYMENT_GATEWAY=fanbasis

# Fanbasis
FANBASIS_API_KEY=your-fanbasis-key
FANBASIS_BASE_URL=https://www.fanbasis.com/public-api

# PremiumPay
PREMIUMPAY_API_KEY=your-premiumpay-key
PREMIUMPAY_BASE_URL=https://pre.api.premiumpay.pro/api/v1

# Match2Pay
MATCH2PAY_API_TOKEN=your-match2pay-token
MATCH2PAY_API_SECRET=your-match2pay-secret
MATCH2PAY_API_URL=https://api.match2pay.com/api/v2
```

### 2. Create a Checkout (with DB tracking)

The recommended way — creates a `Payment` record, calls the gateway, and updates the record:

```php
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$service = app(PaymentService::class);

$result = $service->initiate('fanbasis', new CheckoutRequest(
    amount: 299.00,
    currency: 'USD',
    customerEmail: 'user@example.com',
    productName: 'Pro Plan',
    successUrl: 'https://yourapp.com/payment/success',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    metadata: ['user_id' => '42', 'plan_id' => 'pro'],
), $order); // $order is optional — any Eloquent model

return redirect($result->redirectUrl);
```

This creates a `Payment` record (status: `processing`), calls the gateway, stores the transaction ID, and logs everything.

### 3. Create a Checkout (lightweight, no DB)

If you don't need DB tracking, use the facade directly:

```php
use Subtain\LaravelPayments\Facades\Payment;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$result = Payment::gateway('premiumpay')->checkout(new CheckoutRequest(
    amount: 99.00,
    currency: 'USD',
    invoiceId: 'inv_67890',
    customerEmail: 'user@example.com',
    customerIp: request()->ip(),
    productName: 'Starter Plan',
    successUrl: 'https://yourapp.com/payment/success',
    webhookUrl: route('payments.webhook', 'premiumpay'),
));
```

### 4. Handle Webhooks

Webhooks are handled automatically at:

```
POST /payments/webhook/{gateway}
```

When a webhook arrives, the package:
1. Verifies the signature
2. Parses the payload into a `WebhookResult`
3. Finds the `Payment` record (by invoice ID or transaction ID)
4. Updates the payment status (with state machine guard rails)
5. Logs the webhook to `lp_payment_logs`
6. Dispatches events

**Listen to events in your app:**

```php
use Subtain\LaravelPayments\Events\PaymentSucceeded;
use Subtain\LaravelPayments\Events\PaymentFailed;

protected $listen = [
    PaymentSucceeded::class => [
        \App\Listeners\ProvisionAccount::class,
        \App\Listeners\SendReceiptEmail::class,
    ],
    PaymentFailed::class => [
        \App\Listeners\NotifyUserOfFailure::class,
    ],
];
```

**Example listener:**

```php
use Subtain\LaravelPayments\Events\PaymentSucceeded;

class ProvisionAccount
{
    public function handle(PaymentSucceeded $event): void
    {
        $result  = $event->result;   // WebhookResult DTO
        $payment = $event->payment;  // Payment model (or null)

        // Access the payable model (Order, User, etc.)
        if ($payment && $payment->payable) {
            $payment->payable->activate();
        }

        // Or find by invoice ID
        $order = Order::where('invoice_id', $result->invoiceId)->first();
        $order->markAsPaid();
    }
}
```

## Payment Model

The `Payment` model tracks every payment attempt:

```php
use Subtain\LaravelPayments\Models\Payment;

// Find payments
$payment = Payment::findByInvoiceId('inv_12345');
$payment = Payment::findByTransactionId('txn_abc');

// Check status
$payment->isPaid();
$payment->isPending();
$payment->isFailed();

// Status transitions (with guard rails)
$payment->markAsPaid('txn_abc');    // pending/processing → paid
$payment->markAsFailed();           // pending/processing → failed
$payment->markAsRefunded();         // paid → refunded
$payment->transitionTo(PaymentStatus::CANCELLED);

// Relationships
$payment->payable;  // The owning model (Order, User, etc.)
$payment->logs;     // All webhook logs for this payment
```

### Status Machine

Valid transitions are enforced. Invalid transitions throw `LogicException`:

```
pending → processing, paid, failed, cancelled
processing → paid, failed, cancelled
paid → refunded
failed → pending (retry)
cancelled → (terminal)
refunded → (terminal)
```

## HasPayments Trait

Add to any model that can have payments:

```php
use Subtain\LaravelPayments\Traits\HasPayments;

class Order extends Model
{
    use HasPayments;
}

// Now you can:
$order->payments;              // All payments
$order->latestPayment();       // Most recent payment
$order->hasPaidPayment();      // Any successful payment?
$order->paidPayments;          // Collection of paid payments
$order->createPayment([        // Create a payment manually
    'gateway'    => 'stripe',
    'invoice_id' => 'inv_123',
    'amount'     => 99.00,
    'currency'   => 'USD',
    'status'     => 'pending',
]);
```

## Payment Logs

Every webhook, status change, and checkout is logged automatically:

```php
use Subtain\LaravelPayments\Models\PaymentLog;

// Logs are created automatically by the package.
// You can also query them:
$logs = PaymentLog::where('gateway', 'fanbasis')
    ->where('event', 'webhook_received')
    ->latest()
    ->get();

// Each log has:
// - payment_id (nullable — linked to Payment if found)
// - gateway
// - event (webhook_received, checkout_initiated, checkout_failed, etc.)
// - status
// - payload (JSON)
// - headers (JSON)
```

## Available Gateways

### Fanbasis

Supports two modes:
- **Dynamic** — creates a checkout session via the Fanbasis API
- **Static** — uses a pre-configured payment link (pass `extra['payment_link']`)

```php
// Dynamic checkout
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Challenge Package',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    metadata: ['CustomID' => '42', 'ChallengeId' => 'inv_123'],
));

// Static payment link
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    invoiceId: 'inv_123',
    extra: ['payment_link' => 'https://www.fanbasis.com/pay/your-link'],
));
```

### PremiumPay

API-based checkout with bearer token authentication.

```php
Payment::gateway('premiumpay')->checkout(new CheckoutRequest(
    amount: 99.00,
    invoiceId: 'inv_456',
    customerEmail: 'user@example.com',
    customerIp: request()->ip(),
    productName: 'Starter Package',
    successUrl: 'https://yourapp.com/success',
    cancelUrl: 'https://yourapp.com/cancel',
    webhookUrl: route('payments.webhook', 'premiumpay'),
    extra: ['fail_url' => 'https://yourapp.com/failed'],
));
```

### Match2Pay

Crypto payment gateway with SHA-384 signature verification.

```php
Payment::gateway('match2pay')->checkout(new CheckoutRequest(
    amount: 50.00,
    currency: 'USD',
    webhookUrl: route('payments.webhook', 'match2pay'),
    extra: [
        'payment_currency' => 'USX',
        'payment_gateway_name' => 'USDT TRC20',
    ],
));
```

## Adding a Custom Gateway

### Step 1: Create the Gateway Class

```php
namespace App\Gateways;

use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;

class StripeGateway implements PaymentGateway
{
    public function __construct(protected array $config = []) {}

    public function name(): string { return 'stripe'; }

    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        $session = \Stripe\Checkout\Session::create([...]);

        return new CheckoutResult(
            redirectUrl: $session->url,
            transactionId: $session->id,
            gateway: $this->name(),
            raw: $session->toArray(),
        );
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        // Map Stripe payload to WebhookResult
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        // Verify Stripe signature
    }
}
```

### Step 2: Register in Config

```php
// config/payments.php → gateways
'stripe' => [
    'driver'         => \App\Gateways\StripeGateway::class,
    'secret_key'     => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
```

### Step 3: Use It

```php
// Lightweight (no DB)
Payment::gateway('stripe')->checkout($request);

// With DB tracking
app(PaymentService::class)->initiate('stripe', $request, $order);
```

## Configuration

```php
// config/payments.php
return [
    'default' => env('PAYMENT_GATEWAY', 'fanbasis'),

    'webhook_path' => 'payments/webhook',
    'webhook_middleware' => [],

    // Customize table names (prefixed to avoid conflicts)
    'tables' => [
        'payments'     => 'lp_payments',
        'payment_logs' => 'lp_payment_logs',
    ],

    'gateways' => [
        'fanbasis'   => [...],
        'premiumpay' => [...],
        'match2pay'  => [...],
    ],
];
```

## Events

| Event | When | Payload |
|---|---|---|
| `WebhookReceived` | Every incoming webhook | `WebhookResult`, `?Payment` |
| `PaymentSucceeded` | Webhook status = paid | `WebhookResult`, `?Payment` |
| `PaymentFailed` | Webhook status = failed | `WebhookResult`, `?Payment` |

```php
$event->result;   // WebhookResult DTO (always present)
$event->payment;  // Payment model (null if not tracked by package)
```

## Testing

```php
use Subtain\LaravelPayments\Facades\Payment;
use Subtain\LaravelPayments\DTOs\CheckoutResult;

// Mock the facade
Payment::shouldReceive('gateway->checkout')
    ->once()
    ->andReturn(new CheckoutResult(
        redirectUrl: 'https://test-payment-page.com',
        transactionId: 'test_txn_123',
        gateway: 'fanbasis',
    ));

// Or mock the PaymentService
$this->mock(PaymentService::class, function ($mock) {
    $mock->shouldReceive('initiate')->once()->andReturn(...);
});
```

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x

## License

MIT License. See [LICENSE](LICENSE) for details.

## Author

**Syed Subtain Haider** — [GitHub](https://github.com/subtain-haider)
