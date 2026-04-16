# Discount Codes

Gateway-agnostic discount codes stored in your database. The package validates, calculates, and tracks usage automatically — the payment gateway always receives only the final discounted amount and never knows a discount code exists.

---

## How It Works

```
User submits discount_code at checkout
              │
              ▼
PaymentService::initiate() — package runs validate + apply internally
              │
              ├── Validates: active? expired? usage limits? gateway restriction?
              ├── Calculates: finalAmount = originalAmount - discount
              ├── Stores on lp_payments: discount_code_id, discount_amount, user_id
              └── Sends finalAmount to gateway (gateway sees no discount code)
              │
              ▼
Gateway webhook fires → WebhookController → PaymentSucceeded
              │
              ▼
Package auto-records usage (if auto_record_discount_usage = true):
  → increments times_used on lp_discount_codes
  → writes row to lp_discount_code_usages
  → idempotency guard: skips if already recorded (safe for duplicate webhooks)
```

---

## Setup

### 1. Publish and run migrations

```bash
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

This creates:
- `lp_payments` — includes `discount_code_id`, `discount_amount`, `user_id`
- `lp_discount_codes` — discount code definitions
- `lp_discount_code_usages` — usage audit trail

### 2. Enable auto-recording (recommended)

In your `.env`:

```env
PAYMENTS_AUTO_RECORD_DISCOUNT=true
```

Or in `config/lp_payments.php`:

```php
'auto_record_discount_usage' => true,
```

**Default is `true`** — you only need to set this if you want to disable it.

---

## Creating Discount Codes

```php
use Subtain\LaravelPayments\Models\DiscountCode;

// Percentage discount, unlimited uses
DiscountCode::create([
    'code'        => 'WELCOME20',
    'description' => '20% off for new users',
    'type'        => 'percentage',
    'value'       => 20,
]);

// Fixed discount, 100 total uses, one per user, with expiry
DiscountCode::create([
    'code'              => 'LAUNCH50',
    'type'              => 'fixed',
    'value'             => 50,
    'max_total_uses'    => 100,
    'max_uses_per_user' => 1,
    'expires_at'        => '2026-12-31 23:59:59',
]);

// Percentage with cap on savings and minimum order
DiscountCode::create([
    'code'                => 'FLASH20',
    'type'                => 'percentage',
    'value'               => 20,
    'max_discount_amount' => 500,   // never save more than $500
    'min_order_amount'    => 100,   // only on orders $100+
    'starts_at'           => '2026-04-14 00:00:00',
    'expires_at'          => '2026-04-20 23:59:59',
]);

// Gateway-restricted — only valid for Fanbasis payments
DiscountCode::create([
    'code'     => 'CARD15',
    'type'     => 'percentage',
    'value'    => 15,
    'gateways' => ['fanbasis'],
]);
```

### All Fields

| Field | Type | Default | Description |
|---|---|---|---|
| `code` | string, unique | required | The code customers enter (stored uppercase) |
| `description` | string, nullable | null | Internal note |
| `type` | `percentage` or `fixed` | required | Discount type |
| `value` | decimal | required | 20 = 20% off or $20 off |
| `min_order_amount` | decimal, nullable | null | Minimum order to qualify |
| `max_discount_amount` | decimal, nullable | null | Cap on savings (e.g. max $500 off) |
| `max_total_uses` | int, nullable | null (unlimited) | Global redemption limit |
| `max_uses_per_user` | int, nullable | null (unlimited) | Per-user redemption limit |
| `times_used` | int | 0 | Auto-incremented, do not set manually |
| `starts_at` | timestamp, nullable | null (immediate) | Not valid before this |
| `expires_at` | timestamp, nullable | null (never) | Not valid after this |
| `gateways` | JSON array, nullable | null (all) | Restrict to specific gateways |
| `active` | bool | true | On/off toggle |

---

## Validating a Code and Getting the Discount Amount

Use `DiscountService::apply()` when you need to validate a code and see the calculated amounts **before** initiating a payment — for example, to show the user a price preview, or to store the discounted amount on your own order record first.

```php
use Subtain\LaravelPayments\DiscountService;

$discountService = app(DiscountService::class);

$result = $discountService->apply(
    code:    'LAUNCH50',
    amount:  299.00,
    userId:  auth()->id(),   // optional — needed for per-user limit checks
    gateway: 'fanbasis',     // optional — needed for gateway-scoped codes
);

$result->discountCode;    // DiscountCode model
$result->originalAmount;  // 299.00
$result->discountAmount;  // 50.00
$result->finalAmount;     // 249.00
$result->toArray();       // ['discount_code' => 'LAUNCH50', 'discount_type' => 'fixed', ...]
```

If the code is invalid, `apply()` throws a `ValidationException` with a human-readable message before returning anything:
- `"Invalid discount code."`
- `"Discount code has expired."`
- `"Discount code has reached its maximum number of uses."`
- `"You have already used this discount code the maximum number of times."`
- `"Order amount must be at least $100 to use this code."`
- `"Discount code is not valid for this payment method."`

If you only want to validate without calculating amounts (e.g. a real-time "is this code valid?" check as the user types), use `validate()` instead — it returns the `DiscountCode` model or throws:

```php
$discountCode = $discountService->validate(
    code:    'LAUNCH50',
    amount:  299.00,
    userId:  auth()->id(),
    gateway: 'fanbasis',
);
```

---

## Using Discounts at Checkout — Automatic Mode (Recommended)

Pass `discountCode` and `userId` on the `CheckoutRequest`. The package handles everything else.

```php
use Subtain\LaravelPayments\PaymentService;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;

$result = app(PaymentService::class)->initiate(
    gateway: 'fanbasis',
    request: new CheckoutRequest(
        amount:       299.00,
        productName:  'Pro Challenge',
        successUrl:   $successUrl,
        webhookUrl:   route('payments.webhook', 'fanbasis'),
        discountCode: $request->input('discount_code'), // e.g. 'LAUNCH50'
        userId:       auth()->id(),                     // for per-user limit checks
    ),
    payable: $order,
);

return redirect($result->redirectUrl);
```

**What happens automatically:**
1. Package validates the code (active? expired? limits? gateway restriction?)
2. Calculates the discounted amount (299.00 → 249.00)
3. Sends 249.00 to Fanbasis — gateway never sees the code
4. Stores `discount_code_id`, `discount_amount`, `user_id` on `lp_payments`
5. When the webhook confirms payment → increments `times_used` and writes `lp_discount_code_usages`

**If the code is invalid**, a `ValidationException` is thrown with a specific message before any gateway call is made:
- `"Invalid discount code."`
- `"Discount code has expired."`
- `"You have already used this discount code the maximum number of times."`
- etc.

Catch it in your controller or let Laravel's default validation handler return a 422.

### Validate the code in your Form Request (optional but recommended)

Catch invalid codes at the request validation layer before reaching your controller logic:

```php
use Subtain\LaravelPayments\Rules\ValidDiscountCode;

public function rules(): array
{
    return [
        'discount_code' => [
            'nullable',
            'string',
            new ValidDiscountCode(
                userId:  $this->user()?->id,
                amount:  $this->input('amount'),   // or resolve from product
                gateway: 'fanbasis',               // optional, for gateway-scoped codes
            ),
        ],
    ];
}
```

This returns a 422 with a human-readable error before the controller is even called.

---

## Using Discounts at Checkout — Manual Mode

If you want full control over when usage is recorded (e.g. only after your own fulfillment completes), disable auto-recording and handle it yourself.

```env
PAYMENTS_AUTO_RECORD_DISCOUNT=false
```

Then in your controller:

```php
use Subtain\LaravelPayments\DiscountService;
use Subtain\LaravelPayments\PaymentService;

// 1. Apply discount — validates + calculates
$discountResult = app(DiscountService::class)->apply(
    code:    $request->input('discount_code'),
    amount:  299.00,
    userId:  auth()->id(),
    gateway: 'fanbasis',
);

// 2. Initiate payment with the final amount
$result = app(PaymentService::class)->initiate(
    gateway: 'fanbasis',
    request: new CheckoutRequest(
        amount:    $discountResult->finalAmount,  // 249.00
        ...
    ),
    payable: $order,
);

// 3. In your PaymentSucceeded listener — record usage after fulfillment
app(DiscountService::class)->recordUsage(
    result:  $discountResult,
    userId:  $user->id,
    payable: $order,
);
```

---

## Using the Model Directly (No Service)

All discount logic lives on the `DiscountCode` model. The `DiscountService` is just a convenience wrapper — you don't have to use it.

```php
use Subtain\LaravelPayments\Models\DiscountCode;

// Find (case-insensitive)
$code = DiscountCode::findByCode('LAUNCH50');

// Validate — returns true or an error string
$result = $code->redeemable(userId: 42, amount: 299.00, gateway: 'fanbasis');
if ($result !== true) {
    return back()->withErrors(['discount_code' => $result]);
}

// Boolean shortcut
$code->isRedeemable(userId: 42, amount: 299.00);

// Calculate discount amount
$discount = $code->calculateDiscount(299.00); // 50.00
$final    = 299.00 - $discount;              // 249.00

// After payment succeeds — increment counter
$code->incrementUsage();
```

---

## Validation Checks (In Order)

When `validate()`, `apply()`, or `redeemable()` runs, these checks happen in sequence:

1. Code exists in database
2. Code is `active = true`
3. Not before `starts_at`
4. Not after `expires_at`
5. `times_used` < `max_total_uses` (if set)
6. Order amount >= `min_order_amount` (if set)
7. Gateway is in `gateways` list (if set)
8. User hasn't exceeded `max_uses_per_user` (if set and `userId` provided)

---

## Audit Trail

Every successful usage is recorded in `lp_discount_code_usages`:

```php
use Subtain\LaravelPayments\Models\DiscountCode;
use Subtain\LaravelPayments\Models\DiscountCodeUsage;

// All usages of a code
$code = DiscountCode::findByCode('LAUNCH50');
$code->usages;

// How many times a specific user used a code
$code->usages()->where('user_id', 42)->count();

// All discount usages for an order (polymorphic)
DiscountCodeUsage::where('payable_type', Order::class)
    ->where('payable_id', $order->id)
    ->get();
```

Each usage record stores:
- `discount_code_id` — which code was used
- `user_id` — who used it
- `original_amount` — amount before discount
- `discount_amount` — how much was saved
- `final_amount` — amount charged
- `payable_type` / `payable_id` — polymorphic link to the order/model

---

## Query Scopes

```php
// Only codes that are currently valid (active, not expired, not exhausted)
DiscountCode::valid()->get();

// Check a payment's discount
$payment = Payment::find(1);
$payment->hasDiscount();         // true/false
$payment->discount_amount;       // 50.00
$payment->discountCode;          // DiscountCode model
```

---

## Extending with App-Specific Logic

The package handles generic discount logic. Add product/challenge/user-group-specific scoping by extending the model:

```php
// app/Models/AppDiscountCode.php
class AppDiscountCode extends \Subtain\LaravelPayments\Models\DiscountCode
{
    // Restrict to specific challenges
    public function challenges()
    {
        return $this->belongsToMany(Challenge::class, 'discount_code_challenge');
    }

    // Add custom redeemability checks on top of the package's built-in ones
    public function redeemable(?int $userId = null, ?float $amount = null, ?string $gateway = null): true|string
    {
        $base = parent::redeemable($userId, $amount, $gateway);
        if ($base !== true) return $base;

        // Your custom check
        if ($this->challenges()->whereKey($this->currentChallengeId)->doesntExist()) {
            return 'This code is not valid for the selected challenge.';
        }

        return true;
    }
}
```

---

## Idempotency — Duplicate Webhook Protection

Gateways send duplicate webhooks. The auto-recording logic guards against this:

Before writing a `DiscountCodeUsage` row, the package checks whether a record already exists for the same `discount_code_id` + `payable` (or `user_id` if no payable). If found, it skips — the counter is never double-incremented.

If you use manual `recordUsage()`, you are responsible for your own idempotency. A simple guard:

```php
$alreadyRecorded = DiscountCodeUsage::where('discount_code_id', $code->id)
    ->where('payable_type', Order::class)
    ->where('payable_id', $order->id)
    ->exists();

if (! $alreadyRecorded) {
    app(DiscountService::class)->recordUsage($discountResult, $user->id, $order);
}
```
