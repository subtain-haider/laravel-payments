# Sandbox Mode

Sandbox mode lets you run the complete payment flow — DB records, logs, events, listeners — without charging real money or calling real gateway APIs. It is designed for local development, staging environments, and production QA accounts.

---

## How It Works

When a payment is sandboxed:

1. `PaymentService::initiate()` detects the sandbox condition via `SandboxResolver`
2. Instead of calling the real gateway, a `SandboxGateway` returns an instant simulated `CheckoutResult`
3. The `lp_payments` record is created with `is_sandbox = true`
4. All `lp_payment_logs` entries are marked `is_sandbox = true`
5. A sandbox confirm URL is available to simulate the successful webhook

Your listeners, fulfillment logic, and discount recording all fire **identically** to a real payment. The only difference is no real money moves.

---

## Three Ways a Payment Gets Sandboxed

### 1. Environment-level (entire environment)

Set in `.env`:

```env
PAYMENTS_SANDBOX=true
```

All payments on this environment go through sandbox. Every gateway is affected unless you restrict it:

```env
PAYMENTS_SANDBOX_GATEWAYS=fanbasis,match2pay   # only these two
PAYMENTS_SANDBOX_GATEWAYS=*                    # all gateways (default)
```

Use this on `local` and `staging`. Never set this on `production`.

### 2. Per-user bypass (any environment, including production)

Specific user IDs or roles are always sandboxed, even on production. Useful for internal QA accounts that need to test the full flow without paying.

```php
// config/lp_payments.php
'sandbox' => [
    'bypass_user_ids' => [1, 42, 100],          // these user IDs always get sandbox
    'bypass_roles'    => ['admin', 'qa_tester'], // these roles always get sandbox
],
```

Pass the authenticated user to `initiate()`:

```php
app(PaymentService::class)->initiate(
    gateway: 'fanbasis',
    request: $checkoutRequest,
    payable: $order,
    user:    auth()->user(),  // ← sandbox bypass is checked against this user
);
```

If `$user` is null, per-user bypass is skipped.

### 3. Role resolver override

By default the package resolves roles via (in order):
1. `$user->getRoleNames()` — spatie/laravel-permission
2. `$user->roles->pluck('name')` — Eloquent hasMany relationship
3. `[$user->role]` — plain string column

If your app uses a different system, provide a callable:

```php
// config/lp_payments.php
'sandbox' => [
    'role_resolver' => fn ($user) => $user->permissions->pluck('name')->toArray(),
],
```

---

## Simulating a Successful Payment

Since sandboxed payments never receive a real webhook, the package provides a confirm endpoint:

```
GET /payments/webhook/sandbox/confirm/{invoice_id}
```

This endpoint:
- Finds the `lp_payments` record by `invoice_id`
- Verifies `is_sandbox = true` — **rejects any real payment record**, even if sandbox mode is currently on
- Fires `PaymentSucceeded` exactly as a real webhook would
- Transitions the payment to `paid`

**Only available when:**
- `sandbox.enabled = true` in config, OR
- App environment is `local` or `testing`

### Usage in a test or QA flow

```
1. User clicks "Buy" → initiate() → redirected to config('sandbox.redirect_url')
2. On that page: show a "Confirm Payment" button
3. Button calls GET /payments/webhook/sandbox/confirm/{invoice_id}
4. PaymentSucceeded fires → your listeners run → account provisioned
```

### Customise the sandbox redirect URL

```php
// config/lp_payments.php
'sandbox' => [
    'redirect_url' => env('PAYMENTS_SANDBOX_REDIRECT_URL', '/sandbox/payment-pending'),
],
```

Point it to a page in your app that explains the payment is simulated and shows a "Confirm" button.

---

## Full Config Reference

```php
// config/lp_payments.php
'sandbox' => [

    // Master switch — PAYMENTS_SANDBOX=true in .env
    'enabled' => env('PAYMENTS_SANDBOX', false),

    // Which gateways are sandboxed when enabled = true
    // '*' = all, or CSV: 'fanbasis,match2pay'
    'gateways' => env('PAYMENTS_SANDBOX_GATEWAYS', '*'),

    // User IDs always sandboxed (any environment, including prod)
    'bypass_user_ids' => [],

    // Roles always sandboxed (any environment, including prod)
    'bypass_roles' => [],

    // Optional: custom role resolver callable
    // Signature: fn(Authenticatable $user): array
    'role_resolver' => null,

    // The URL the SandboxGateway returns as the "checkout redirect"
    'redirect_url' => env('PAYMENTS_SANDBOX_REDIRECT_URL', '/sandbox/payment-pending'),

],
```

---

## Identifying Sandboxed Records

```php
use Subtain\LaravelPayments\Models\Payment;

// Find sandboxed payments
Payment::where('is_sandbox', true)->get();

// Check a specific payment
$payment->is_sandbox; // bool

// Check in a listener
public function handle(PaymentSucceeded $event): void
{
    if ($event->payment?->is_sandbox) {
        // This is a test — skip real provisioning if needed
    }
}
```

---

## Recommended Environment Setup

### `.env.local`

```env
PAYMENTS_SANDBOX=true
PAYMENTS_SANDBOX_GATEWAYS=*
PAYMENTS_SANDBOX_REDIRECT_URL=/sandbox/pending
```

### `.env.staging`

```env
PAYMENTS_SANDBOX=true
PAYMENTS_SANDBOX_GATEWAYS=fanbasis,match2pay
```

### `.env.production`

```env
# Do not set PAYMENTS_SANDBOX — defaults to false
```

With specific QA accounts in `config/lp_payments.php`:

```php
'sandbox' => [
    'enabled'         => env('PAYMENTS_SANDBOX', false),
    'bypass_user_ids' => [1, 5],         // your internal QA accounts
    'bypass_roles'    => ['qa_tester'],
],
```
