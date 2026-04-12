# Fanbasis Gateway

Full Fanbasis API integration: checkout sessions, customers, subscribers, discount codes, products, transactions, refunds, and webhook management.

> **API Reference:** [apidocs.fan](https://apidocs.fan)

---

## Setup

```env
FANBASIS_API_KEY=your-api-key
FANBASIS_WEBHOOK_SECRET=whsec_your-secret
FANBASIS_CREATOR_HANDLE=your-handle   # only needed for embedded checkout
```

### What You Need from Fanbasis

1. **API Key** — [FanBasis Dashboard → API Keys](https://www.fanbasis.com). Required for all API calls.
2. **Webhook Secret** — returned once when you create a webhook subscription. Used for HMAC-SHA256 signature verification.
3. **Creator Handle** — your FanBasis username (visible in your profile URL). Only needed for embedded checkout URLs.

No OAuth, no client ID/secret pairs — Fanbasis uses a single API key with separate test/live environments.

---

## Checkout Modes

### One-Time Payment

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

### Subscription

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

Requires an existing Fanbasis product and `FANBASIS_CREATOR_HANDLE`.

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

Use a pre-built link from the Fanbasis dashboard. Metadata is appended as query params.

```php
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 0,
    extra: ['payment_link' => 'https://fanbasis.com/pay/your-link'],
    metadata: ['user_id' => '42'],
));
```

---

## Discount Codes (Gateway-Level)

These are Fanbasis-managed discount codes, scoped to products via `service_ids`.

```php
// Pre-apply a code
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    extra: ['discount_code' => 'SUMMER20'],
));

// Let customer enter their own code on the checkout page
Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount: 299.00,
    productName: 'Pro Plan',
    extra: ['allow_discount_codes' => true],
));
```

> For a **gateway-agnostic** discount system that lives in your database, see [Built-in Discount Codes](../../README.md#built-in-discount-codes) in the main README.

---

## Full API Access

```php
$fb = Payment::gateway('fanbasis');
```

### Checkout Sessions

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

### Customers

```php
$fb->customers()->list(['search' => 'jane@example.com']);
$fb->customers()->paymentMethods('cust_1');
$fb->customers()->charge('cust_1', [
    'payment_method_id' => 'pm_abc',
    'amount_cents'      => 1999,
    'description'       => 'Upgrade charge',
]);
```

### Subscribers

```php
$fb->subscribers()->list(['status' => 'active']);
$fb->subscribers()->forCheckoutSession('NLxj6');
$fb->subscribers()->forProduct('prod_1');
$fb->subscribers()->cancel('NLxj6', 'sub_1');
$fb->subscribers()->extend('NLxj6', ['user_id' => 'usr_1', 'duration_days' => 30]);
$fb->subscribers()->refundTransaction('txn_1');
```

### Discount Codes API

```php
$fb->discountCodes()->list();
$fb->discountCodes()->create([
    'code'          => 'SUMMER20',
    'discount_type' => 'percentage',
    'value'         => 20,
    'duration'      => 'once',
    'expiry'        => '2026-12-31',
    'one_time'      => true,
    'service_ids'   => [101, 102],
]);
$fb->discountCodes()->find(1);
$fb->discountCodes()->update(1, ['expiry' => '2027-06-30']);
$fb->discountCodes()->delete(1);
```

### Products

```php
$fb->products()->list(['page' => 1, 'per_page' => 20]);
```

### Transactions

```php
$fb->transactions()->find('txn_abc');
$fb->transactions()->list(['product_id' => 'NLxj6']);
```

### Refunds

```php
$fb->refunds()->create('txn_abc');                          // full
$fb->refunds()->create('txn_abc', ['amount' => 1500]);     // partial
$fb->refunds()->create('txn_abc', ['reason' => 'Unused']); // with reason
```

### Webhooks Management

```php
$fb->webhooks()->list();
$fb->webhooks()->create([
    'webhook_url'  => 'https://app.com/webhooks/fanbasis',
    'event_types'  => ['payment.succeeded', 'subscription.created'],
]);
$fb->webhooks()->delete('ws_abc');
$fb->webhooks()->test('ws_abc', ['event_type' => 'payment.succeeded']);
```

---

## Webhook Handling

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

Manual signature verification:

```php
use Subtain\LaravelPayments\Gateways\Fanbasis\WebhooksService;

WebhooksService::verifySignature($request->getContent(), $request->header('x-webhook-signature'), $secret);
```
