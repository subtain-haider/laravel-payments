# Custom Gateways

Add any payment provider in three steps: implement the interface, register in config, use it. Your gateway automatically gets DB tracking, webhook handling, logging, sandbox support, key fingerprinting, and discount integration — no extra work.

---

## Step 1 — Implement the Interface

Create a class in your application implementing `PaymentGateway`:

```php
<?php

namespace App\Gateways;

use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\PaymentLogger;

class StripeGateway implements PaymentGateway
{
    public function __construct(private array $config) {}

    /**
     * Return a unique name for this gateway.
     * Must match the key used in config/lp_payments.php.
     */
    public function name(): string
    {
        return 'stripe';
    }

    /**
     * Initiate a checkout session and return a redirect URL.
     */
    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        PaymentLogger::info('checkout.initiated', [
            'invoice_id' => $request->invoiceId,
            'amount'     => $request->amount,
        ], gateway: 'stripe', category: 'checkout');

        try {
            // Call your gateway's API here
            $response = $this->callApi('/checkout/sessions', [
                'amount'      => (int) ($request->amount * 100),
                'currency'    => strtolower($request->currency),
                'success_url' => $request->successUrl,
                'cancel_url'  => $request->cancelUrl,
                'metadata'    => $request->metadata,
            ]);

            PaymentLogger::info('checkout.success', [
                'invoice_id'  => $request->invoiceId,
                'session_id'  => $response['id'],
                'redirect'    => $response['url'],
            ], gateway: 'stripe', category: 'checkout');

            return new CheckoutResult(
                redirectUrl:   $response['url'],
                transactionId: $response['id'],
                gateway:       'stripe',
                raw:           $response,
            );

        } catch (\Throwable $e) {
            PaymentLogger::error('checkout.failed', [
                'invoice_id' => $request->invoiceId,
                'error'      => $e->getMessage(),
            ], gateway: 'stripe', category: 'checkout');

            throw $e;
        }
    }

    /**
     * Parse an incoming webhook payload into a standardised WebhookResult.
     * Called by the package's WebhookController after signature verification.
     */
    public function parseWebhook(array $payload): WebhookResult
    {
        $event  = $payload['type'] ?? '';
        $object = $payload['data']['object'] ?? [];

        $status = match ($event) {
            'checkout.session.completed'         => PaymentStatus::PAID,
            'checkout.session.expired'           => PaymentStatus::CANCELLED,
            'charge.refunded'                    => PaymentStatus::REFUNDED,
            'payment_intent.payment_failed'      => PaymentStatus::FAILED,
            default                              => PaymentStatus::PENDING,
        };

        return new WebhookResult(
            status:        $status,
            invoiceId:     $object['metadata']['invoice_id'] ?? '',
            transactionId: $object['payment_intent'] ?? $object['id'] ?? '',
            amount:        isset($object['amount_total']) ? $object['amount_total'] / 100 : null,
            currency:      strtoupper($object['currency'] ?? ''),
            metadata:      $object,
            raw:           $payload,
        );
    }

    /**
     * Verify the webhook signature.
     * Return true to accept, false to reject with 401.
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        $secret    = $this->config['webhook_secret'] ?? '';
        $signature = $headers['stripe-signature'][0] ?? '';

        if (empty($secret) || empty($signature)) {
            PaymentLogger::warning('webhook.missing_signature', [
                'has_secret' => ! empty($secret),
            ], gateway: 'stripe', category: 'webhook');

            return false;
        }

        // Stripe uses a timestamp-based HMAC — implement per your gateway's docs
        return $this->validateStripeSignature($signature, $secret);
    }

    // ── Private helpers ────────────────────────────────────────────

    private function callApi(string $endpoint, array $payload): array
    {
        // Your HTTP client logic here
        // Use PaymentLogger::debug() for request/response logging
    }

    private function validateStripeSignature(string $signature, string $secret): bool
    {
        // Your signature validation logic here
    }
}
```

### Raw Body Signature Verification (Optional)

Some gateways (like Fanbasis) require the **raw request body** for HMAC verification — never re-serialised JSON. If your gateway needs this, implement `verifyWebhookSignature()` instead of `verifyWebhook()`:

```php
/**
 * If this method exists, the WebhookController will call it with the raw
 * request body string instead of calling verifyWebhook() with parsed payload.
 */
public function verifyWebhookSignature(string $rawBody, array $headers = []): bool
{
    $secret    = $this->config['webhook_secret'] ?? '';
    $signature = $headers['x-signature'][0] ?? '';

    return hash_equals(
        hash_hmac('sha256', $rawBody, $secret),
        $signature,
    );
}
```

---

## Step 2 — Register in Config

Add an entry to `config/lp_payments.php` under `gateways`:

```php
'gateways' => [

    // ... existing gateways ...

    'stripe' => [
        'driver'         => \App\Gateways\StripeGateway::class,
        'api_key'        => env('STRIPE_API_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Tell the package which fields are credentials for key fingerprinting.
        // The first non-null field becomes the fingerprint stored on lp_payments.
        'key_fields' => ['api_key', 'webhook_secret'],
    ],

],
```

If `key_fields` is omitted, the package falls back to checking `['api_key', 'api_token', 'secret', 'postback_key']`.

---

## Step 3 — Use It

Your gateway is now a first-class citizen. It works exactly like any built-in gateway:

```php
// Lightweight (no DB record)
Payment::gateway('stripe')->checkout(new CheckoutRequest(
    amount:     299.00,
    successUrl: 'https://app.com/success',
    webhookUrl: route('payments.webhook', 'stripe'),
));

// With DB tracking
app(PaymentService::class)->initiate(
    gateway: 'stripe',
    request: new CheckoutRequest(
        amount:       299.00,
        successUrl:   'https://app.com/success',
        webhookUrl:   route('payments.webhook', 'stripe'),
        discountCode: $request->input('discount_code'),
        userId:       auth()->id(),
    ),
    payable: $order,
);
```

Webhooks arrive at `POST /payments/webhook/stripe` and are handled automatically.

---

## What You Get for Free

By implementing the interface, your gateway automatically gets:

| Feature | What it does |
|---|---|
| **DB tracking** | `lp_payments` record created and updated per initiation/webhook |
| **Webhook handling** | Signature verification, status transitions, audit logs |
| **Events** | `PaymentSucceeded`, `PaymentFailed`, `WebhookReceived` dispatched automatically |
| **Sandbox mode** | Your gateway is sandboxed when `PAYMENTS_SANDBOX=true` — `SandboxGateway` replaces your `checkout()` call |
| **Key fingerprinting** | `first4****last4` of your API key stored on every payment record |
| **Logging** | Respects all logging config — channel routing, levels, redaction |
| **Discounts** | Pass `discountCode` on `CheckoutRequest` — works with any gateway |

---

## The PaymentGateway Interface

```php
interface PaymentGateway
{
    public function name(): string;

    public function checkout(CheckoutRequest $request): CheckoutResult;

    public function parseWebhook(array $payload): WebhookResult;

    public function verifyWebhook(array $payload, array $headers = []): bool;
}
```

Optionally implement `verifyWebhookSignature(string $rawBody, array $headers): bool` if your gateway needs raw body verification.

---

## Constructor Injection

Your gateway receives its config array (the entry from `config/lp_payments.php`) via constructor. The package's `PaymentManager` resolves gateways lazily and passes the config automatically:

```php
public function __construct(private array $config)
{
    // $this->config['api_key']        → from env('STRIPE_API_KEY')
    // $this->config['webhook_secret'] → from env('STRIPE_WEBHOOK_SECRET')
}
```

You can also bind your gateway as a singleton for direct DI injection, the same way the built-in `FanbasisClient`, `Match2PayClient`, and `RebornpayClient` are registered:

```php
// In your AppServiceProvider
$this->app->singleton(\App\Gateways\StripeGateway::class, function ($app) {
    return new \App\Gateways\StripeGateway(
        $app['config']->get('lp_payments.gateways.stripe', [])
    );
});
```

---

## Using PaymentLogger in Your Gateway

Always use `PaymentLogger` for any logging inside your gateway. This ensures your logs respect the developer's channel routing, level config, and redaction settings automatically.

```php
use Subtain\LaravelPayments\PaymentLogger;

// Log levels: debug, info, warning, error
PaymentLogger::info('checkout.initiated', ['invoice_id' => $id], gateway: 'stripe', category: 'checkout');
PaymentLogger::debug('api.request',  ['endpoint' => $url, 'payload' => $body], gateway: 'stripe', category: 'api');
PaymentLogger::debug('api.response', ['status' => 200, 'body' => $response], gateway: 'stripe', category: 'api');
PaymentLogger::error('api.error',    ['error' => $e->getMessage()], gateway: 'stripe', category: 'api');
PaymentLogger::warning('webhook.missing_signature', [], gateway: 'stripe', category: 'webhook');
```

Developers using your gateway can then route its logs in `config/lp_payments.php`:

```php
'logging' => [
    'channels' => [
        'stripe' => 'slack',   // Stripe logs → Slack
    ],
    'levels' => [
        'stripe' => 'debug',   // verbose for Stripe only
    ],
],
```
