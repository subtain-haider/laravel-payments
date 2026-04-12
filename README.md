# Laravel Payments

A clean, extensible multi-gateway payment package for Laravel. Ships with **Fanbasis**, **PremiumPay**, and **Match2Pay** — and makes it dead simple to add your own gateways.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## Features

- **One interface, any gateway** — every gateway implements the same 3-method contract
- **Manager pattern** — swap gateways with one config change, no code changes
- **Automatic webhook handling** — parses webhooks and dispatches Laravel events
- **Gateway-agnostic DTOs** — `CheckoutRequest`, `CheckoutResult`, `WebhookResult`
- **Event-driven** — `PaymentSucceeded`, `PaymentFailed`, `WebhookReceived`
- **Zero business logic** — the package handles gateway communication; your app handles what happens next
- **Laravel 10, 11 & 12** compatible

## Installation

```bash
composer require subtain/laravel-payments
```

The package auto-discovers its service provider and facade. No manual registration needed.

### Publish Config

```bash
php artisan vendor:publish --tag=payments-config
```

This creates `config/payments.php` where you configure gateways and credentials.

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
MATCH2PAY_SECRET=your-match2pay-secret
MATCH2PAY_BASE_URL=https://api.match2pay.com
```

### 2. Create a Checkout

```php
use Subtain\LaravelPayments\Facades\Payment;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

// Using the default gateway
$result = Payment::gateway()->checkout(new CheckoutRequest(
    amount: 299.00,
    currency: 'USD',
    invoiceId: 'inv_12345',
    customerEmail: 'user@example.com',
    productName: 'Pro Plan',
    productDescription: 'Monthly subscription to Pro Plan',
    successUrl: 'https://yourapp.com/payment/success',
    webhookUrl: route('payments.webhook', 'fanbasis'),
    metadata: ['user_id' => '42', 'plan_id' => 'pro'],
));

// Redirect user to payment page
return redirect($result->redirectUrl);
```

### 3. Use a Specific Gateway

```php
// Explicitly choose a gateway
$result = Payment::gateway('premiumpay')->checkout(new CheckoutRequest(
    amount: 99.00,
    currency: 'USD',
    invoiceId: 'inv_67890',
    customerEmail: 'user@example.com',
    customerIp: request()->ip(), // Required by PremiumPay
    productName: 'Starter Plan',
    successUrl: 'https://yourapp.com/payment/success',
    webhookUrl: route('payments.webhook', 'premiumpay'),
));
```

### 4. Handle Webhooks

Webhooks are handled automatically. The package registers a route at:

```
POST /payments/webhook/{gateway}
```

When a webhook arrives, the package:
1. Resolves the correct gateway
2. Verifies the signature (if supported)
3. Parses the payload into a `WebhookResult`
4. Dispatches events

**Listen to events in your app:**

```php
// In your EventServiceProvider
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
namespace App\Listeners;

use Subtain\LaravelPayments\Events\PaymentSucceeded;

class ProvisionAccount
{
    public function handle(PaymentSucceeded $event): void
    {
        $result = $event->result;
        
        // $result->invoiceId      — your order/invoice ID
        // $result->transactionId  — gateway's transaction ID
        // $result->gateway        — 'fanbasis', 'premiumpay', etc.
        // $result->amount         — amount paid
        // $result->metadata       — the metadata you sent during checkout
        // $result->raw            — full raw webhook payload
        
        $order = Order::where('invoice_id', $result->invoiceId)->first();
        $order->markAsPaid($result->transactionId);
    }
}
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
    metadata: ['user_id' => '42'],
));
```

### PremiumPay

API-based checkout with bearer token authentication.

```php
Payment::gateway('premiumpay')->checkout(new CheckoutRequest(
    amount: 99.00,
    invoiceId: 'inv_456',
    customerEmail: 'user@example.com',
    customerIp: request()->ip(), // Required
    productName: 'Starter Package',
    successUrl: 'https://yourapp.com/success',
    cancelUrl: 'https://yourapp.com/cancel',
    webhookUrl: route('payments.webhook', 'premiumpay'),
    extra: ['fail_url' => 'https://yourapp.com/failed'],
));
```

### Match2Pay

Crypto payment gateway with HMAC-SHA256 signature verification.

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

Adding a new gateway takes **3 steps**:

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
    protected string $secretKey;

    public function __construct(array $config = [])
    {
        $this->secretKey = $config['secret_key'] ?? '';
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        // Call Stripe API to create a checkout session
        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $request->currency,
                    'product_data' => ['name' => $request->productName],
                    'unit_amount' => (int) ($request->amount * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'metadata' => $request->metadata,
        ]);

        return new CheckoutResult(
            redirectUrl: $session->url,
            transactionId: $session->id,
            gateway: $this->name(),
            raw: $session->toArray(),
        );
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        $object = $payload['data']['object'] ?? [];
        
        return new WebhookResult(
            status: $this->mapStatus($object['status'] ?? ''),
            invoiceId: $object['metadata']['invoice_id'] ?? '',
            transactionId: $object['id'] ?? '',
            gateway: $this->name(),
            amount: ($object['amount_total'] ?? 0) / 100,
            currency: strtoupper($object['currency'] ?? 'USD'),
            metadata: $object['metadata'] ?? [],
            raw: $payload,
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        // Verify Stripe webhook signature
        $signature = $headers['stripe-signature'][0] ?? '';
        try {
            \Stripe\Webhook::constructEvent(
                json_encode($payload),
                $signature,
                config('payments.gateways.stripe.webhook_secret')
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function mapStatus(string $status): PaymentStatus
    {
        return match ($status) {
            'complete', 'paid' => PaymentStatus::PAID,
            'expired'          => PaymentStatus::FAILED,
            default            => PaymentStatus::PENDING,
        };
    }
}
```

### Step 2: Register in Config

```php
// config/payments.php

'gateways' => [
    // ... existing gateways ...

    'stripe' => [
        'driver'         => \App\Gateways\StripeGateway::class,
        'secret_key'     => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
],
```

### Step 3: Use It

```php
Payment::gateway('stripe')->checkout(new CheckoutRequest(
    amount: 49.99,
    currency: 'USD',
    productName: 'Premium Plan',
    successUrl: 'https://yourapp.com/success',
    webhookUrl: route('payments.webhook', 'stripe'),
));
```

That's it. The webhook controller, event dispatching, and signature verification all work automatically.

## Configuration

Full config file (`config/payments.php`):

```php
return [
    // Default gateway when none specified
    'default' => env('PAYMENT_GATEWAY', 'fanbasis'),

    // Webhook route path (POST {APP_URL}/{webhook_path}/{gateway})
    'webhook_path' => 'payments/webhook',

    // Middleware for webhook routes
    'webhook_middleware' => [],

    // Gateway configurations
    'gateways' => [
        'fanbasis' => [
            'driver'   => \Subtain\LaravelPayments\Gateways\FanbasisGateway::class,
            'base_url' => env('FANBASIS_BASE_URL', 'https://www.fanbasis.com/public-api'),
            'api_key'  => env('FANBASIS_API_KEY'),
        ],
        'premiumpay' => [
            'driver'   => \Subtain\LaravelPayments\Gateways\PremiumPayGateway::class,
            'base_url' => env('PREMIUMPAY_BASE_URL', 'https://pre.api.premiumpay.pro/api/v1'),
            'api_key'  => env('PREMIUMPAY_API_KEY'),
        ],
        'match2pay' => [
            'driver'    => \Subtain\LaravelPayments\Gateways\Match2PayGateway::class,
            'base_url'  => env('MATCH2PAY_BASE_URL'),
            'api_token' => env('MATCH2PAY_API_TOKEN'),
            'secret'    => env('MATCH2PAY_SECRET'),
        ],
    ],
];
```

## Events

| Event | When | Payload |
|---|---|---|
| `WebhookReceived` | Every incoming webhook | `WebhookResult` |
| `PaymentSucceeded` | Webhook status = paid | `WebhookResult` |
| `PaymentFailed` | Webhook status = failed | `WebhookResult` |

All events carry a `WebhookResult` with standardized fields:

```php
$result->status;         // PaymentStatus enum
$result->invoiceId;      // Your order ID
$result->transactionId;  // Gateway's transaction ID
$result->gateway;        // Gateway name
$result->amount;         // Amount paid
$result->currency;       // Currency code
$result->metadata;       // Your metadata
$result->raw;            // Full raw payload
```

## API Reference

### Facade Methods

```php
Payment::gateway('fanbasis');           // Get a specific gateway
Payment::gateway();                     // Get the default gateway
Payment::getDefaultDriver();            // Get default gateway name
```

### CheckoutRequest

```php
new CheckoutRequest(
    amount: 299.00,              // Required
    currency: 'USD',             // Default: 'USD'
    invoiceId: 'inv_123',        // Your internal ID
    customerEmail: 'a@b.com',    // Customer email
    customerName: 'John',        // Customer name
    customerIp: '1.2.3.4',       // Required by some gateways
    productName: 'Pro Plan',     // Product name
    productDescription: '...',   // Product description
    successUrl: 'https://...',   // Redirect on success
    cancelUrl: 'https://...',    // Redirect on cancel
    webhookUrl: 'https://...',   // Webhook URL
    metadata: [...],             // Arbitrary key-value pairs
    extra: [...],                // Gateway-specific fields
);

// Or from array:
CheckoutRequest::fromArray($validatedData);
```

### CheckoutResult

```php
$result = Payment::gateway('fanbasis')->checkout($request);

$result->redirectUrl;     // URL to redirect customer to
$result->transactionId;   // Gateway's session/transaction ID
$result->gateway;         // Gateway name
$result->raw;             // Full raw API response
$result->successful();    // true if redirectUrl is not empty
```

## Testing

You can mock the Payment facade in tests:

```php
use Subtain\LaravelPayments\Facades\Payment;
use Subtain\LaravelPayments\DTOs\CheckoutResult;

Payment::shouldReceive('gateway->checkout')
    ->once()
    ->andReturn(new CheckoutResult(
        redirectUrl: 'https://test-payment-page.com',
        transactionId: 'test_txn_123',
        gateway: 'fanbasis',
    ));
```

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x, or 12.x

## License

MIT License. See [LICENSE](LICENSE) for details.

## Author

**Syed Subtain Haider** — [GitHub](https://github.com/subtain-haider)
