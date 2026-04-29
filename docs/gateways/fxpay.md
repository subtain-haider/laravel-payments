# FxPay Gateway

UPI payment gateway for Indian payments via the FxPay API. A second UPI provider alongside Rebornpay.

> **API Reference:** [fxpay.live](https://fxpay.live)

---

## Setup

```env
FXPAY_API_SECRET=sk_xxxxxxxxx
FXPAY_WEBHOOK_SECRET=secret_xxxxxxxxxx
FXPAY_BASE_URL=https://fxpay.live  # optional, this is the default
```

### What You Need from FxPay

Contact your FxPay account manager to obtain:

1. **API Secret** — Bearer token sent as `Authorization: Bearer sk_xxx` on all API calls.
2. **Webhook Secret** — Used to verify incoming webhook signatures (`X-PG-Signature` header).
3. **Webhook URL registration** — Register your `callback_url` with FxPay. This is sent per-order in the checkout request — no system-wide config needed.

---

## Checkout

Creates a payment order and returns a `payment_url` to redirect the customer to.

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\Facades\Payment;

$result = Payment::gateway('fxpay')->checkout(new CheckoutRequest(
    amount:        1500.50,
    invoiceId:     'INV-10001',          // Sent as merchant_order_id — required for webhook matching
    customerName:  'Rahul Sharma',
    customerEmail: 'rahul@example.com',
    webhookUrl:    route('payments.webhook', 'fxpay'),  // FxPay POSTs status updates here
    productName:   'Challenge Purchase',  // Sent as the order note (optional)
));

// Redirect customer to:
$result->redirectUrl;    // https://fxpay.live/pay/bcfcfb0561d40fa8...
$result->transactionId;  // ORD123456789 — FxPay's system order ID
```

### With Phone Number

FxPay accepts an optional customer phone number. Pass it via `extra`:

```php
$result = Payment::gateway('fxpay')->checkout(new CheckoutRequest(
    amount:    1500.50,
    invoiceId: 'INV-10001',
    extra: [
        'customer_phone' => '9123456789',
    ],
));
```

---

## Webhook Handling

FxPay POSTs a webhook to your `callback_url` when a payment is approved or rejected.

The package's generic `WebhookController` at `POST /payments/webhook/fxpay` handles everything automatically:

1. Verifies signature (HMAC-SHA256 of raw request body, `X-PG-Signature` header)
2. Parses the payload into a `WebhookResult`
3. Finds the `lp_payments` record by `merchant_order_id` (= your `invoiceId`)
4. Updates the payment status
5. Dispatches `PaymentSucceeded` or `PaymentFailed` event

Register this URL with FxPay as your callback: `https://yourapp.com/payments/webhook/fxpay`

### Status Mapping

| FxPay `data.status` | Package Status |
|---|---|
| `success` | `PAID` — safe to credit the user |
| `rejected` | `FAILED` — payment was rejected |
| anything else | `PENDING` |

### Listening to Events

```php
use Subtain\LaravelPayments\Events\PaymentSucceeded;
use Subtain\LaravelPayments\Events\PaymentFailed;

// In your EventServiceProvider:
protected $listen = [
    PaymentSucceeded::class => [
        \App\Listeners\HandleSuccessfulPayment::class,
    ],
    PaymentFailed::class => [
        \App\Listeners\HandleFailedPayment::class,
    ],
];
```

```php
// In your listener:
public function handle(PaymentSucceeded $event): void
{
    $result  = $event->result;    // WebhookResult
    $payment = $event->payment;   // Payment model (or null if not using DB tracking)

    $invoiceId     = $result->invoiceId;      // your merchant_order_id
    $transactionId = $result->transactionId;  // FxPay's system order_id
    $amount        = $result->amount;         // amount from FxPay webhook

    // Extra FxPay-specific data:
    $orderId    = $result->metadata['order_id'];
    $fxpayStatus = $result->metadata['fxpay_status'];  // 'success' or 'rejected'
    $note        = $result->metadata['note'];
}
```

---

## Signature Verification

FxPay signs webhooks using HMAC-SHA256 of the raw request body with your `webhook_secret`.
The signature is sent in the `X-PG-Signature` header (`X-Webhook-Signature` is accepted as a fallback).

The package handles this automatically via `verifyWebhookSignature()` which uses the raw body.

### Manual Verification

```php
$rawBody  = $request->getContent();
$secret   = config('lp_payments.gateways.fxpay.webhook_secret');
$received = $request->header('X-PG-Signature')
         ?? $request->header('X-Webhook-Signature');

$expected = hash_hmac('sha256', $rawBody, $secret);
$valid    = hash_equals($expected, $received);
```

---

## Using with PaymentService (DB Tracking)

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\PaymentService;

$result = app(PaymentService::class)->initiate(
    gateway: 'fxpay',
    request: new CheckoutRequest(
        amount:        1500.50,
        invoiceId:     'INV-10001',
        customerName:  'Rahul Sharma',
        customerEmail: 'rahul@example.com',
        webhookUrl:    route('payments.webhook', 'fxpay'),
        productName:   'Challenge Purchase',
        discountCode:  $request->input('discount_code'),  // optional
        userId:        auth()->id(),                       // optional — for discount tracking
    ),
    payable: $order,  // your Eloquent model with HasPayments trait
);
```

The service creates an `lp_payments` record, calls `checkout()`, updates it to `processing`, and returns the `CheckoutResult`. When the webhook arrives, the generic `WebhookController` automatically finds the record by `invoiceId` and transitions it to `paid` or `failed`.

---

## Discounts

Discounts work identically across all gateways. Pass `discountCode` and `userId` on the `CheckoutRequest` — the package validates, applies, and records the discount automatically. FxPay always receives the final discounted amount.

```php
$result = app(PaymentService::class)->initiate(
    gateway: 'fxpay',
    request: new CheckoutRequest(
        amount:       1500.50,
        invoiceId:    'INV-10001',
        webhookUrl:   route('payments.webhook', 'fxpay'),
        discountCode: 'SAVE10',
        userId:       auth()->id(),
    ),
    payable: $order,
);
```

---

## Direct API Access

```php
$fxpay = Payment::gateway('fxpay');

// Create an order directly
$data = $fxpay->orders()->create([
    'customer'          => ['name' => 'Rahul Sharma', 'email' => 'rahul@example.com'],
    'amount'            => 1500.50,
    'merchant_order_id' => 'INV-10001',
    'callback_url'      => 'https://yourapp.com/payments/webhook/fxpay',
    'note'              => 'Challenge Purchase',
]);

// $data['success']     → true
// $data['order_id']    → 'ORD123456789'
// $data['payment_url'] → 'https://fxpay.live/pay/...'
```

---

## Sandbox

FxPay is fully sandboxed when `PAYMENTS_SANDBOX=true` — the real API is never called, and a simulated `CheckoutResult` is returned instantly. All DB records, logs, events, and listeners fire identically to production.

```env
PAYMENTS_SANDBOX=true
PAYMENTS_SANDBOX_GATEWAYS=fxpay   # sandbox only fxpay; other gateways remain live
```

Use the sandbox confirm endpoint to simulate a successful webhook:

```
GET /payments/webhook/sandbox/confirm/{invoice_id}
```
