# Payments

Everything about tracking payments, the status machine, handling webhooks, and listening to events.

---

## Two Ways to Use the Package

### Lightweight (no DB record)

Call the gateway directly. No database record is created. You handle everything yourself.

```php
use Subtain\LaravelPayments\Facades\Payment;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$result = Payment::gateway('fanbasis')->checkout(new CheckoutRequest(
    amount:     299.00,
    productName: 'Pro Plan',
    webhookUrl:  route('payments.webhook', 'fanbasis'),
    successUrl:  'https://app.com/success',
));

return redirect($result->redirectUrl);
```

### With DB Tracking (recommended)

Use `PaymentService::initiate()`. The package creates an `lp_payments` record, calls the gateway, updates the record, and auto-records discount usage on successful webhook. Everything is handled for you.

```php
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$result = app(PaymentService::class)->initiate(
    gateway: 'fanbasis',
    request: new CheckoutRequest(
        amount:      299.00,
        productName: 'Pro Plan',
        webhookUrl:  route('payments.webhook', 'fanbasis'),
        successUrl:  'https://app.com/success',
        invoiceId:   (string) $order->id,    // your reference
        customerEmail: $user->email,
    ),
    payable: $order,   // any Eloquent model — polymorphic
    user:    $user,    // optional, used for per-user sandbox bypass
);

return redirect($result->redirectUrl);
```

---

## CheckoutRequest Fields

All fields except `amount` are optional. Unused fields are safely ignored by each gateway.

| Field | Type | Description |
|---|---|---|
| `amount` | float | **Required.** Amount in major units (e.g. 299.00 = $299) |
| `currency` | string | ISO 4217 code. Default: `'USD'` |
| `invoiceId` | string | Your internal reference. Auto-generated if omitted. |
| `customerEmail` | string | Customer email |
| `customerName` | string | Customer name |
| `customerIp` | string | Customer IP (required by some gateways) |
| `productName` | string | Product name shown on checkout page |
| `productDescription` | string | Product description |
| `successUrl` | string | Redirect on successful payment |
| `cancelUrl` | string | Redirect on cancel |
| `webhookUrl` | string | URL for gateway to send webhooks |
| `metadata` | array | Arbitrary key-value data attached to the payment |
| `extra` | array | Gateway-specific fields (crypto currency, embedded mode, etc.) |
| `discountCode` | string\|null | Discount code — package validates and applies automatically |
| `userId` | int\|null | Authenticated user ID — for per-user discount limits |

---

## Payment Model

Every `initiate()` call creates a row in `lp_payments`.

```php
use Subtain\LaravelPayments\Models\Payment;

$payment = Payment::findByInvoiceId('inv_123');
$payment = Payment::findByTransactionId('txn_abc');

// Status
$payment->isPaid();
$payment->isPending();
$payment->isFailed();
$payment->hasDiscount();

// Manual status transitions
$payment->markAsPaid('txn_abc');
$payment->markAsFailed();
$payment->markAsRefunded();

// Relations
$payment->payable;       // the Order/User/etc. that owns this payment
$payment->discountCode;  // DiscountCode model if a discount was applied
$payment->logs;          // all webhook audit records for this payment
```

### Key Columns

| Column | Description |
|---|---|
| `gateway` | Which gateway processed this (fanbasis, match2pay, etc.) |
| `invoice_id` | Your internal reference (unique) |
| `transaction_id` | Gateway's transaction/session ID |
| `amount` | Amount charged (already discounted if discount was applied) |
| `currency` | ISO 4217 |
| `status` | Current status (see Status Machine below) |
| `paid_at` | Timestamp when payment confirmed |
| `discount_code_id` | FK to lp_discount_codes (null if no discount) |
| `discount_amount` | How much was discounted |
| `user_id` | The user who made the payment |
| `is_sandbox` | True if this was a simulated payment |
| `key_fingerprint` | `first4****last4` of the API key active at initiation |
| `metadata` | JSON — anything you passed in CheckoutRequest |
| `gateway_response` | Raw JSON response from the gateway checkout call |

---

## Status Machine

Payments follow a strict state machine. Invalid transitions throw a `LogicException`.

```
pending ──→ processing ──→ paid ──→ refunded
   │              │
   └──→ failed ◄──┘
   │
   └──→ cancelled
```

| Transition | When |
|---|---|
| `pending → processing` | `initiate()` successfully calls the gateway |
| `processing → paid` | Webhook confirms successful payment |
| `processing → failed` | Webhook reports failure |
| `paid → refunded` | Refund webhook received |
| `failed → pending` | Retry (you call this manually) |
| `pending → cancelled` | You cancel the payment |

The `WebhookController` handles transitions automatically. Duplicate webhooks are safe — if the payment is already in the target status, the transition is skipped.

---

## HasPayments Trait

Add to any Eloquent model that owns payments.

```php
use Subtain\LaravelPayments\Traits\HasPayments;

class Order extends Model
{
    use HasPayments;
}
```

This gives you:

```php
$order->payments;              // MorphMany — all payments for this order
$order->latestPayment();       // most recent Payment model
$order->hasPaidPayment();      // bool — any confirmed payment exists?
$order->paidPayments();        // MorphMany — all paid payments
$order->createPayment([...]);  // manually create a linked Payment record
```

---

## Webhooks

The package registers a single route that handles all gateways:

```
POST /payments/webhook/{gateway}
```

For example:
- `POST /payments/webhook/fanbasis`
- `POST /payments/webhook/match2pay`
- `POST /payments/webhook/rebornpay`
- `POST /payments/webhook/premiumpay`

### What the WebhookController does automatically

1. Resolves the gateway driver by `{gateway}` name
2. Verifies the webhook signature (HMAC-SHA256, MD5, SHA-384 — varies per gateway)
3. Rejects with `401` if signature is invalid
4. Parses the payload into a standardised `WebhookResult`
5. Finds the `lp_payments` record by `invoice_id` or `transaction_id`
6. Logs the raw payload to `lp_payment_logs`
7. Transitions the payment status via the state machine
8. Dispatches `WebhookReceived` (always), then `PaymentSucceeded` or `PaymentFailed`
9. Auto-records discount usage if applicable (see [Discounts](discounts.md))
10. Returns `{"status": "ok"}` — gateways expect a 200

### Configuring the webhook path

Default path is `payments/webhook`. Change in `config/lp_payments.php`:

```php
'webhook_path' => 'my/custom/webhook/path',
// → POST /my/custom/webhook/path/{gateway}
```

### Adding middleware to webhook routes

```php
'webhook_middleware' => ['throttle:60,1'],
```

> Do NOT add `auth` middleware to webhook routes — they receive unauthenticated requests from gateway servers.

### CSRF exclusion

Webhook routes are automatically excluded from Laravel's CSRF verification because they are registered via the package's route loader, not through your `web` middleware group.

---

## Events

Listen to these events in your `EventServiceProvider` to run your business logic after payment events.

```php
use Subtain\LaravelPayments\Events\PaymentSucceeded;
use Subtain\LaravelPayments\Events\PaymentFailed;
use Subtain\LaravelPayments\Events\WebhookReceived;

protected $listen = [
    PaymentSucceeded::class => [
        \App\Listeners\FulfillOrder::class,
        \App\Listeners\SendConfirmationEmail::class,
    ],
    PaymentFailed::class => [
        \App\Listeners\NotifyCustomer::class,
    ],
    WebhookReceived::class => [
        \App\Listeners\LogToAnalytics::class,
    ],
];
```

### PaymentSucceeded

Dispatched when a webhook confirms a successful payment.

```php
public function handle(PaymentSucceeded $event): void
{
    $result  = $event->result;   // WebhookResult
    $payment = $event->payment;  // Payment model (null if not using DB tracking)

    $invoiceId     = $result->invoiceId;     // your reference
    $transactionId = $result->transactionId; // gateway's reference
    $amount        = $result->amount;
    $currency      = $result->currency;

    // Link back to your model via the payment
    $order = $payment->payable;   // Order, User, etc.

    // Provision accounts, send emails, etc.
}
```

### PaymentFailed

```php
public function handle(PaymentFailed $event): void
{
    $result  = $event->result;
    $payment = $event->payment;

    // Notify customer, log failure, etc.
}
```

### WebhookReceived

Fired for **every** webhook regardless of status. Useful for analytics, raw logging, or handling statuses not covered by Succeeded/Failed.

```php
public function handle(WebhookReceived $event): void
{
    $result  = $event->result;   // WebhookResult (includes raw payload)
    $payment = $event->payment;  // Payment model or null
    $status  = $result->status;  // PaymentStatus enum
}
```

---

## WebhookResult Fields

The standardised DTO returned by every gateway's `parseWebhook()`:

```php
$result->invoiceId;      // string — your internal reference
$result->transactionId;  // string — gateway's reference
$result->status;         // PaymentStatus enum (PAID, FAILED, CANCELLED, etc.)
$result->amount;         // float|null
$result->currency;       // string|null
$result->metadata;       // array — gateway-specific extra data
$result->raw;            // array — full raw payload from gateway
$result->isSuccessful(); // bool — true when status === PAID
$result->isFailed();     // bool — true when status === FAILED
```

---

## Payment Logs (Webhook Audit Trail)

Every webhook received and every checkout initiation is written to `lp_payment_logs`. This is separate from the file/channel logging system — it is a permanent, queryable database record.

```php
$payment = Payment::findByInvoiceId('inv_123');

// All events for this payment, in order
$payment->logs;

// Latest
$payment->logs()->latest()->first();
```

Each log record contains:
- `event` — what happened (`checkout_initiated`, `webhook_received`, `checkout_failed`, etc.)
- `payload` — the raw data at that moment
- `headers` — request headers (for webhooks)
- `status` — the status at time of recording
- `is_sandbox` — whether this was a sandboxed event

---

## Finding Payments

```php
use Subtain\LaravelPayments\Models\Payment;
use Subtain\LaravelPayments\PaymentService;

// By invoice ID
$payment = Payment::findByInvoiceId('inv_123');

// By gateway transaction ID
$payment = Payment::findByTransactionId('txn_abc');

// Via PaymentService
$payment = app(PaymentService::class)->findByInvoice('inv_123');
$payment = app(PaymentService::class)->findByTransaction('txn_abc');

// Via your model (with HasPayments trait)
$order->payments;
$order->latestPayment();
$order->hasPaidPayment();
```
