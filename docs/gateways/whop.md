# Whop Gateway

One-time and recurring (subscription) payment processing via Whop's hosted checkout.
Supports 195 countries, 100+ payment methods, and automatic subscription billing.

> **API Reference:** [docs.whop.com/api-reference](https://docs.whop.com/api-reference)
> **Webhooks:** [docs.whop.com/developer/guides/webhooks](https://docs.whop.com/developer/guides/webhooks)

---

## Setup

```env
WHOP_API_KEY=your-company-api-key
WHOP_WEBHOOK_SECRET=your-webhook-signing-secret
WHOP_COMPANY_ID=biz_xxxxxxxxxxxxx
```

### What You Need from Whop

1. **Company API Key** — [Whop Dashboard → Settings → API Keys](https://whop.com/dashboard/settings/api).
   Required for all checkout API calls. Use a **Company API Key** (not a user OAuth token).

2. **Webhook Signing Secret** — [Whop Dashboard → Developer → Webhooks](https://whop.com/dashboard/developer).
   Created when you register a webhook endpoint. Whop displays this secret **once** — store it in your `.env` immediately.
   The secret is base64-encoded — paste the value exactly as shown in the dashboard.

3. **Company ID** (`biz_xxx`) — [Whop Dashboard → Settings](https://whop.com/dashboard/settings).
   Required when creating checkout configurations with an inline plan. Visible in your dashboard URL and settings.

---

## Checkout — One-Time Payment

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\Facades\Payment;

$result = Payment::gateway('whop')->checkout(new CheckoutRequest(
    amount:        299.00,
    currency:      'USD',
    invoiceId:     'order_123',          // your internal order ID — returned in webhook
    customerEmail: 'john@example.com',
    productName:   'Funded Account $10K',
    successUrl:    'https://app.com/payment/success',
    metadata:      ['user_id' => 42],    // any extra data you want back in the webhook
));

return redirect($result->redirectUrl);  // Whop-hosted checkout page
// $result->transactionId → checkout configuration ID (ch_xxx)
```

---

## Checkout — Recurring Subscription

Set `extra['plan_type'] = 'renewal'` and provide a `billing_period` (number of days between charges).

```php
$result = Payment::gateway('whop')->checkout(new CheckoutRequest(
    amount:     29.99,
    currency:   'USD',
    invoiceId:  'sub_order_456',
    successUrl: 'https://app.com/subscription/activated',
    extra: [
        'plan_type'      => 'renewal',
        'billing_period' => 30,           // charge every 30 days
    ],
));
```

### Subscription with Trial Period

```php
$result = Payment::gateway('whop')->checkout(new CheckoutRequest(
    amount:     29.99,
    currency:   'USD',
    invoiceId:  'sub_trial_789',
    successUrl: 'https://app.com/subscription/activated',
    extra: [
        'plan_type'         => 'renewal',
        'billing_period'    => 30,
        'trial_period_days' => 7,         // 7-day free trial before first charge
        'renewal_price'     => 24.99,     // optional: different price for renewals
    ],
));
```

### Subscription with Different Initial vs. Renewal Price

```php
$result = Payment::gateway('whop')->checkout(new CheckoutRequest(
    amount:     9.99,                     // initial (discounted) price
    currency:   'USD',
    invoiceId:  'promo_sub_001',
    extra: [
        'plan_type'      => 'renewal',
        'billing_period' => 30,
        'renewal_price'  => 29.99,        // standard price after first cycle
    ],
));
```

---

## Checkout — Existing Plan ID

If you manage plans in the Whop dashboard, pass `extra['plan_id']` to generate a checkout session
for a specific plan without creating a new one.

```php
$result = Payment::gateway('whop')->checkout(new CheckoutRequest(
    amount:    0,                          // amount is ignored; governed by the existing plan
    invoiceId: 'order_999',
    extra: [
        'plan_id' => 'plan_xxxxxxxxxxxxx',
    ],
));
```

---

## `extra` Parameters Reference

| Key | Type | Description |
|---|---|---|
| `plan_type` | `string` | `'one_time'` (default) or `'renewal'` for subscriptions |
| `billing_period` | `int` | Days between subscription renewals. Required when `plan_type = 'renewal'` |
| `renewal_price` | `float` | Price for renewal cycles if different from `amount` (initial price) |
| `trial_period_days` | `int` | Free trial days before the first charge |
| `access_pass_id` | `string` | Whop product ID to attach the plan to (`pass_xxx`) |
| `plan_id` | `string` | Pre-existing Whop plan ID. When set, skips inline plan creation |
| `allow_promo_codes` | `bool` | Whether to show the promo code field. Defaults to `true` |
| `affiliate_code` | `string` | Affiliate tracking code to attribute this checkout |

---

## Using with PaymentService (DB Tracking)

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\PaymentService;

$result = app(PaymentService::class)->initiate(
    gateway: 'whop',
    request: new CheckoutRequest(
        amount:    299.00,
        currency:  'USD',
        invoiceId: $order->id,
        extra: [
            'plan_type' => 'one_time',
        ],
    ),
    payable: $order,     // any Eloquent model
);

// $result->redirectUrl → redirect the user here
```

The `PaymentService` creates an `lp_payments` record before redirecting. When Whop sends a
`payment.succeeded` webhook, the `WebhookController` finds the record, transitions it to `paid`,
and dispatches `PaymentSucceeded`. Your listener receives the event with a populated `$event->result`.

---

## Webhook Handling

### Register the Webhook Endpoint

In your Whop dashboard under **Developer → Webhooks**, add a new webhook pointing to:

```
POST https://yourapp.com/payments/webhook/whop
```

Select the events you need (see table below). Store the signing secret in `WHOP_WEBHOOK_SECRET`.

### Event → Status Mapping

| Whop Event | Package `PaymentStatus` | When to act |
|---|---|---|
| `payment.succeeded` | `PAID` | Safe to fulfil the order |
| `payment.failed` | `FAILED` | Card declined / all retries exhausted — notify the user |
| `payment.pending` | `PENDING` | Payment processing — do not fulfil yet |
| `payment.refunded` | `REFUNDED` | Reverse the fulfilment |
| `refund.created` | `REFUNDED` | Refund issued via API or dashboard |
| `membership.activated` | `PAID` | Subscription started or renewed — grant / extend access |
| `membership.deactivated` | `CANCELLED` | **Revoke access.** Fired for ALL of: user cancelled (period ended), failed payment after all retries, admin revoked, plan expired |
| `membership.cancel_at_period_end_changed` | `PENDING` | User scheduled a future cancellation (still active until period end). **Do not revoke access.** Show a UI warning instead. Also fires when user un-cancels (`cancel_at_period_end = false`) |
| `dispute.created` | `FAILED` | Chargeback opened — suspend access until resolved |

> **Key distinction — cancellation has two stages:**
> 1. User hits "Cancel" → `membership.cancel_at_period_end_changed` fires immediately (`PENDING`). Access continues until the billing period ends.
> 2. Billing period ends → `membership.deactivated` fires (`CANCELLED`). Revoke access here.
>
> Only act on `membership.deactivated` to revoke. Never revoke on `cancel_at_period_end_changed`.

### Handling Subscription Renewals

Whop fires `payment.succeeded` for each recurring charge **and** `membership.activated` when the
membership remains valid. Both arrive for each successful renewal cycle. You only need to listen
to one — use `membership.activated` to control access, and `payment.succeeded` to record revenue.

### Failed Renewal Payments

When a renewal payment fails, Whop **retries automatically** over several days. During this time:
- `payment.failed` fires for each failed attempt
- The membership status goes to `past_due` — access continues while retrying
- If all retries are exhausted → `membership.deactivated` fires (`CANCELLED`) → revoke access

Do **not** revoke access on `payment.failed` alone for subscriptions. Only revoke on `membership.deactivated`.

```php
// In your EventServiceProvider
use Subtain\LaravelPayments\Events\PaymentSucceeded;

protected $listen = [
    PaymentSucceeded::class => [
        FulfillWhopOrderListener::class,
    ],
];
```

```php
// FulfillWhopOrderListener.php
public function handle(PaymentSucceeded $event): void
{
    $result = $event->result;

    // $result->invoiceId     → your internal order/invoice ID
    // $result->transactionId → Whop payment ID (pay_xxx) or membership ID
    // $result->amount        → amount charged (USD equivalent)
    // $result->currency      → currency code (e.g. 'usd')
    // $result->metadata      → array passed in CheckoutRequest::$metadata
    // $result->raw           → full raw webhook payload

    $order = Order::find($result->invoiceId);
    // ... fulfil the order
}
```

### Signature Verification

Signature verification is **automatic** in the generic `WebhookController` when `WHOP_WEBHOOK_SECRET` is set.

Whop uses the [Standard Webhooks](https://www.standardwebhooks.com) specification:

- Headers: `webhook-id`, `webhook-timestamp`, `webhook-signature`
- Algorithm: HMAC-SHA256 over `"{webhook-id}.{webhook-timestamp}.{raw_body}"`
- Key: base64-decoded webhook secret
- Replay protection: webhooks older than 5 minutes are rejected

For manual verification in a custom controller, always pass the **raw request body** (not the parsed array) for reliable byte-for-byte comparison:

```php
$gateway = Payment::gateway('whop');

$isValid = $gateway->verifyWebhookSignature(
    $request->getContent(),       // raw body string
    $request->headers->all(),     // all headers as array
);

if (! $isValid) {
    return response('Unauthorized', 401);
}
```

---

## Invoice ID Round-Trip

The `invoiceId` you pass to `CheckoutRequest` is stored inside `metadata.invoice_id` in the Whop
checkout configuration. Whop sends this metadata back in every webhook event under `data.metadata`.
The package extracts it automatically — your listener always sees it in `$event->result->invoiceId`.

```php
// CheckoutRequest — set it here:
new CheckoutRequest(
    invoiceId: 'order_123',
    metadata: ['user_id' => 42],
    // ...
)

// Webhook payload received from Whop:
// {
//   "type": "payment.succeeded",
//   "data": {
//     "id": "pay_xxx",
//     "metadata": { "invoice_id": "order_123", "user_id": 42 },
//     ...
//   }
// }

// In your listener:
$event->result->invoiceId;   // → "order_123"
$event->result->metadata;    // → ["invoice_id" => "order_123", "user_id" => 42]
```

---

## Direct Client Access

For API calls beyond checkout and webhooks, use the underlying `WhopClient` directly:

```php
use Subtain\LaravelPayments\Gateways\Whop\WhopClient;

$client = app(WhopClient::class);

// Retrieve a specific membership
$membership = $client->get('memberships/mem_xxxxxxxxxxxxx');

// List payments for your company
$payments = $client->get('payments', ['company_id' => 'biz_xxxxxxxxxxxxx']);

// Cancel a membership
$client->patch('memberships/mem_xxxxxxxxxxxxx', ['cancel_at_end_of_period' => true]);
```

Or via the gateway facade:

```php
$whop = Payment::gateway('whop');
$client = $whop->client();

$data = $client->get('checkout-configurations/ch_xxxxxxxxxxxxx');
```

---

## Delivery Guarantees

Whop delivers webhooks **at-least-once** — you may receive the same event more than once.
Make your handlers idempotent: check whether you've already processed the `transactionId`
before taking action.

Whop retries failed deliveries up to 3 times (after 10s, 20s, 40s). Events are not
guaranteed to arrive in order. Use the `webhook-id` header (or `$payload['id']`) as
a deduplication key.

---

## Sandbox / Testing

Use the package's built-in sandbox mode for local and staging environments. No Whop
credentials are needed:

```env
PAYMENTS_SANDBOX=true
PAYMENTS_SANDBOX_GATEWAYS=whop
PAYMENTS_SANDBOX_REDIRECT_URL=/sandbox/payment-pending
```

To simulate a successful payment in QA:

```
GET /payments/webhook/sandbox/confirm/{invoice_id}
```

This fires `PaymentSucceeded` exactly as a real Whop webhook would.
