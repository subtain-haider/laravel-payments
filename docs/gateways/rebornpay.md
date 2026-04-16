# Rebornpay Gateway

UPI and IMPS payment gateway for Indian payments via the Rebornpay API. Used as "UPI" in user-facing contexts.

> **API Reference:** [prod.api.rbpcloud.pro](https://prod.api.rbpcloud.pro)

---

## Setup

```env
REBORNPAY_API_KEY=your-api-key
REBORNPAY_CLIENT_ID=your-client-uuid
REBORNPAY_CLIENT_POSTBACK_KEY=your-postback-signing-key
REBORNPAY_BASE_URL=https://prod.api.rbpcloud.pro  # optional, this is the default
```

### What You Need from Rebornpay

Contact your Rebornpay account manager to obtain:

1. **API Key** — `X-API-Key` header for all API calls.
2. **Client UUID** — your unique `client_id` sent with every transaction.
3. **Client Postback Key** — used to verify incoming webhook (postback) signatures.
4. **Webhook URL registration** — tell your account manager your `postback_url` and `withdrawal_postback_url`. These are configured per-client in Rebornpay's system, not in your API requests.

---

## Checkout

Creates a pay-in transaction and returns a `payment_page_url` to redirect the customer to.

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\Facades\Payment;

$result = Payment::gateway('rebornpay')->checkout(new CheckoutRequest(
    amount:       92000.00,           // Amount in INR (currency conversion is your responsibility)
    currency:     'INR',
    invoiceId:    'order_123',        // Used as client_transaction_id — required for webhook matching
    customerName: 'Raj Kumar',        // Used as client_user
    successUrl:   'https://app.com/payment/success',  // Appended as redirect_success_url
));

// Redirect customer to:
$result->redirectUrl;    // https://pay.rbpcloud.pro?...&redirect_success_url=https://app.com/payment/success
$result->transactionId;  // txn-rbp-xxx — Rebornpay's system transaction ID
```

### IMPS Payment

```php
$result = Payment::gateway('rebornpay')->checkout(new CheckoutRequest(
    amount:    92000.00,
    currency:  'INR',
    invoiceId: 'order_123',
    extra: [
        'payment_option' => 'IMPS',  // default is 'UPI'
    ],
));
```

### INR Conversion with amount_override

If you store prices in USD and convert to INR before checkout:

```php
$usdAmount  = 299.00;
$inrRate    = 92.0;    // from your settings/config
$inrAmount  = round($usdAmount * $inrRate, 2);

$result = Payment::gateway('rebornpay')->checkout(new CheckoutRequest(
    amount:    $usdAmount,        // stored on the payment record as USD
    currency:  'INR',
    invoiceId: 'order_123',
    extra: [
        'amount_override' => $inrAmount,  // sent to Rebornpay as actual INR amount
    ],
));
```

---

## Webhook Handling

Rebornpay sends a postback to your configured `postback_url` when a transaction changes state.

The package's generic `WebhookController` at `POST /payments/webhook/rebornpay` handles everything automatically:

1. Verifies signature (MD5 + Python-style serialization)
2. Parses the payload into a `WebhookResult`
3. Finds the `lp_payments` record by `client_transaction_id` (= your `invoiceId`)
4. Updates the payment status
5. Dispatches `PaymentSucceeded` or `PaymentFailed` event

### Status Mapping

| Rebornpay `postback_is_fake` | Package Status |
|---|---|
| `false` | `PAID` — safe to credit the user |
| `true` | `FAILED` — fraudulent, do NOT credit the user |

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

    $invoiceId     = $result->invoiceId;      // your client_transaction_id
    $transactionId = $result->transactionId;  // Rebornpay's system transaction ID
    $amount        = $result->amount;         // transaction_amount from Rebornpay
    $currency      = $result->currency;       // INR

    // Extra Rebornpay-specific data:
    $postbackIsFake  = $result->metadata['postback_is_fake'];
    $creationType    = $result->metadata['creation_type'];  // "auto" or "manual"
    $paymentDetails  = $result->metadata['payment_details']; // UPI ID, etc.
}
```

---

## Signature Verification

Rebornpay uses a custom MD5 signature algorithm based on Python's `repr()` + `urlencode()` format. The package handles this automatically via `SignatureService`.

> **Important — Float Precision:** Rebornpay sends amounts as floats (e.g. `2000.0`). PHP's `json_decode()` may convert `2000.0` to integer `2000`, breaking the signature. The package uses the raw request body for verification to avoid this.

### Manual Verification

```php
use Subtain\LaravelPayments\Gateways\Rebornpay\SignatureService;

// Preferred: use the raw body (preserves float precision)
$valid = SignatureService::verifyFromRawBody(
    $request->getContent(),
    config('payments.gateways.rebornpay.postback_key'),
);

// Fallback: use parsed payload (may fail if amounts like 2000.0 are in payload)
$valid = SignatureService::verify(
    $request->all(),
    config('payments.gateways.rebornpay.postback_key'),
);
```

---

## Full API Access

```php
$rbp = Payment::gateway('rebornpay');
```

### Transaction Status Checks

```php
// By Rebornpay system transaction ID
$status = $rbp->transactions()->checkByTransactionId('txn-rbp-xxx');

// By your own client transaction ID
$status = $rbp->transactions()->checkByClientTransactionId(
    clientId: config('payments.gateways.rebornpay.client_id'),
    clientTransactionId: 'order_123',
);

// By bank UTR reference
$status = $rbp->transactions()->checkByUtr('HDFC123456789');

// Response:
// $status['status']               → 'activated' | 'fake' | 'non_activated' | 'non_paid'
// $status['transaction_id']       → system transaction ID
// $status['amount']               → transaction amount
// $status['activation_timestamp'] → unix timestamp (null if not yet paid)
```

### Status Reference

| Status | Final? | Description |
|---|---|---|
| `activated` | ✓ Final | Payment confirmed. Safe to credit. |
| `fake` | ✓ Final | Fraudulent. Do NOT credit. |
| `non_activated` | Pending | Received but not yet verified. |
| `non_paid` | Pending | Awaiting customer payment. |

### UTR Storage

```php
// Store UTR by system transaction ID
$rbp->transactions()->storeUtrByTransactionId(
    clientId: 'your-client-uuid',
    transactionId: 'txn-rbp-xxx',
    utr: 'HDFC123456789',
);

// Store UTR by your client transaction ID
$rbp->transactions()->storeUtrByClientTransactionId(
    clientId: 'your-client-uuid',
    clientTransactionId: 'order_123',
    utr: 'HDFC123456789',
);
```

---

## Using with PaymentService (DB Tracking)

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\PaymentService;

$result = app(PaymentService::class)->initiate(
    gateway: 'rebornpay',
    request: new CheckoutRequest(
        amount:       92000.00,
        currency:     'INR',
        invoiceId:    'order_123',
        customerName: 'Raj Kumar',
        successUrl:   'https://app.com/payment/success',
    ),
    payable: $order,  // your Eloquent model with HasPayments trait
);
```

The service creates an `lp_payments` record, calls `checkout()`, updates the record to `processing`, and returns the `CheckoutResult`. When the webhook arrives, the generic `WebhookController` automatically finds the record by `invoiceId` and transitions it to `paid` or `failed`.
