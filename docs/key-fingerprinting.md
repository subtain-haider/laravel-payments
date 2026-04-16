# API Key Fingerprinting

Every payment record captures a non-reversible fingerprint of the gateway API key that was active at the moment the payment was initiated. This solves a specific production problem: after a key rotation, you can look at any historical payment and know exactly which key version processed it — without storing the key itself.

---

## The Problem It Solves

Imagine this scenario:

1. You rotate your Fanbasis API key on April 10th.
2. A payment from April 9th fails to reconcile.
3. You need to know: was this payment processed with the old key or the new key?

Without fingerprinting, you have no way to know. With fingerprinting, every `lp_payments` row tells you exactly which key was active.

---

## How It Works

At the moment `PaymentService::initiate()` is called, the package reads the gateway's API key from config and computes a `first4****last4` mask:

```
sk_live_abc123xyz789  →  sk_l****z789
```

This mask is:
- **Non-reversible** — you cannot reconstruct the original key from it
- **Unique enough** — the first 4 and last 4 characters identify a specific key across rotations
- **Never sensitive** — it cannot be used to authenticate with any API

The fingerprint is stored on the `lp_payments` row and included in every log entry from that checkout.

---

## In the Database

Every `lp_payments` row has a `key_fingerprint` column:

```php
$payment = Payment::findByInvoiceId('inv_123');

echo $payment->key_fingerprint;  // "sk_l****z789"
```

Query all payments processed with a specific key version:

```php
Payment::where('key_fingerprint', 'sk_l****z789')->get();
```

Find payments processed with an old key (before a rotation):

```php
Payment::where('key_fingerprint', 'sk_l****abc1')
    ->where('created_at', '<', '2026-04-10')
    ->get();
```

---

## In the Logs

Every checkout log entry automatically includes `_key_fingerprints` in its context. No extra code needed:

```
[payments:fanbasis:checkout] checkout.initiated
context: {
    "invoice_id": "inv_123",
    "amount": 299.00,
    "_key_fingerprints": {
        "api_key": "sk_l****z789",
        "webhook_secret": "whs_****3z8x"
    }
}
```

Note that `_key_fingerprints` shows fingerprints for **all** fields listed in `key_fields` for that gateway — not just the primary one.

---

## How the Primary Fingerprint Is Chosen

Each gateway config can declare a `key_fields` array — the ordered list of fields that are credentials:

```php
'fanbasis' => [
    'api_key'        => env('FANBASIS_API_KEY'),
    'webhook_secret' => env('FANBASIS_WEBHOOK_SECRET'),
    'key_fields'     => ['api_key', 'webhook_secret'],  // first non-null wins
],
```

The package reads `key_fields` in order and uses the **first non-null field value** as the primary fingerprint stored on `lp_payments`. All fields are included in the log context.

If `key_fields` is omitted, the package falls back to checking `['api_key', 'api_token', 'secret', 'postback_key']`.

If no credential is found (e.g. a gateway without an API key), `key_fingerprint` is `null` and a warning is logged.

---

## Custom Gateway Setup

When building a custom gateway, add `key_fields` to its config:

```php
// config/lp_payments.php
'stripe' => [
    'driver'         => \App\Gateways\StripeGateway::class,
    'api_key'        => env('STRIPE_API_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'key_fields'     => ['api_key', 'webhook_secret'],
],
```

No other changes needed — fingerprinting happens automatically in `PaymentService::initiate()`.

---

## Security Properties

- **No key storage** — only the `first4****last4` mask is ever persisted or logged. The raw key value is never written anywhere by this package.
- **Non-reversible** — the fingerprint cannot be used to reconstruct or brute-force the original key.
- **Computed after redaction** — fingerprints are computed in `KeyFingerprint`, which runs before log redaction. The raw key value never appears in log output.
- **Read-only** — the fingerprint is set once at payment initiation and never updated.
