# Match2Pay Gateway

Full Match2Pay crypto payment gateway integration: deposits, withdrawals, signature generation, and callback verification.

> **API Reference:** [docs.match2pay.com](https://docs.match2pay.com)

---

## Setup

```env
MATCH2PAY_API_TOKEN=your-api-token
MATCH2PAY_API_SECRET=your-api-secret
MATCH2PAY_API_URL=https://wallet.match2pay.com/api/v2/   # optional, this is the default
```

### What You Need from Match2Pay

Contact Match2Pay support to obtain:

1. **API Token** — included in every request body as `apiToken`.
2. **API Secret** — used to generate request signatures and verify callback signatures. Never sent in requests.
3. **IP Whitelisting** — on the live environment, you must provide your server's IP addresses to Match2Pay support for whitelisting before requests will be accepted.

---

## Checkout (Deposit)

Creates a deposit transaction and returns a `checkoutUrl` to redirect the customer to the Match2Pay payment page.

### 2-Step (Cryptocurrency Selection Page)

Omit `payment_currency` and `payment_gateway_name` to show the customer a selection page first.

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\Facades\Payment;

$result = Payment::gateway('match2pay')->checkout(new CheckoutRequest(
    amount:        299.00,
    currency:      'USD',
    webhookUrl:    route('payments.webhook', 'match2pay'),
    successUrl:    'https://app.com/payment/success',
    customerName:  'John Doe',
    customerEmail: 'john@example.com',
    invoiceId:     'order_123',
));

return redirect($result->redirectUrl);   // Match2Pay payment page URL
// $result->transactionId → Match2Pay paymentId (UUID)
```

### Direct to Specific Cryptocurrency

Provide both `payment_currency` and `payment_gateway_name` to skip the selection step.

```php
$result = Payment::gateway('match2pay')->checkout(new CheckoutRequest(
    amount:     299.00,
    currency:   'USD',
    webhookUrl: route('payments.webhook', 'match2pay'),
    successUrl: 'https://app.com/payment/success',
    extra: [
        'payment_currency'     => 'USX',          // USDT TRC20
        'payment_gateway_name' => 'USDT TRC20',
    ],
));
```

### Full Customer Object

For production, pass a complete customer object to satisfy Match2Pay's KYC requirements:

```php
$result = Payment::gateway('match2pay')->checkout(new CheckoutRequest(
    amount:     299.00,
    currency:   'USD',
    webhookUrl: route('payments.webhook', 'match2pay'),
    successUrl: 'https://app.com/payment/success',
    extra: [
        'payment_currency'     => 'USB',           // USDT BEP20
        'payment_gateway_name' => 'USDT BEP20',
        'customer' => [
            'firstName' => 'John',
            'lastName'  => 'Doe',
            'address'   => [
                'address' => '123 Main St',
                'city'    => 'New York',
                'country' => 'US',
                'zipCode' => '10001',
                'state'   => 'NY',
            ],
            'contactInformation' => [
                'email'       => 'john@example.com',
                'phoneNumber' => '+1234567890',
            ],
            'locale'               => 'en_US',
            'dateOfBirth'          => '1990-01-15',
            'tradingAccountLogin'  => 'john@example.com',
            'tradingAccountUuid'   => 'order_123',
        ],
    ],
));
```

> **Important:** The customer object key order is critical for signature generation. Always use the structure shown above. Match2Pay validates this server-side.

---

## Supported Cryptocurrencies

Key pairs for the most commonly used options. See [full list →](https://docs.match2pay.com)

| Cryptocurrency | `payment_currency` | `payment_gateway_name` |
|---|---|---|
| USDT TRC20 (min 1 USDT) | `USX` | `USDT TRC20` |
| USDT BEP20 | `USB` | `USDT BEP20` |
| USDT ERC20 (min 5 USDT) | `UST` | `USDT ERC20` |
| USDC BEP20 | `UCB` | `USDC BEP20` |
| Bitcoin | `BTC` | `BTC` |
| Ethereum | `ETH` | `ETH` |
| BNB (BSC) | `BNB` | `BNB` |
| Binance Pay | `BNB` | `BNB_BINANCE` |

---

## Webhook Handling

Match2Pay sends **two callbacks** per transaction to your `callbackUrl`:

1. **PENDING** — transaction appears on the blockchain. Do NOT credit the user yet.
2. **DONE** — funds confirmed and booked. Safe to credit the user.

### Status Mapping

| Match2Pay `status` | Package Status |
|---|---|
| `DONE` | `PAID` — safe to credit |
| `DECLINED`, `FAIL`, `SUSPECTED`, `PARTIALLY PAID` | `FAILED` |
| `CANCELLED`, `CANCELED` | `CANCELLED` |
| `PENDING`, `NEW` | `PENDING` |

### Important — Use `finalAmount` for crediting

```php
public function handle(PaymentSucceeded $event): void
{
    $result = $event->result;

    // CORRECT: finalAmount is in your account currency (e.g. USD)
    $amountToCredit = $result->amount;        // finalAmount from webhook
    $currency       = $result->currency;      // finalCurrency

    // Available in metadata:
    $cryptoAmount  = $result->metadata['transaction_amount'];   // raw crypto amount
    $cryptoCurrency = $result->metadata['transaction_currency']; // e.g. BTC, USX
    $txid          = $result->metadata['crypto_transaction_info'][0]['txid'] ?? null;
    $depositAddress = $result->metadata['deposit_address'];

    // Do NOT use transactionAmount for crediting — it is the raw crypto value
}
```

### Signature Verification

Per Match2Pay docs: **only verify the signature for `status = DONE`** callbacks. The signature is sent in the HTTP header (not the body).

The package handles this automatically via `verifyWebhook()`. Pass `$request->headers->all()` as the second argument:

```php
// In your custom webhook controller (if not using the generic one):
$payload   = $request->all();
$headers   = $request->headers->all();

$gateway   = Payment::gateway('match2pay');
$isValid   = $gateway->verifyWebhook($payload, $headers);

if (! $isValid) {
    return response()->json(['error' => 'Invalid signature'], 401);
}
```

The generic `WebhookController` at `POST /payments/webhook/match2pay` handles this automatically.

---

## Full API Access

```php
$m2p = Payment::gateway('match2pay');
```

### Direct Deposit

```php
$response = $m2p->deposit()->create([
    'amount'             => 299.00,
    'currency'           => 'USD',
    'callbackUrl'        => route('payments.webhook', 'match2pay'),
    'successUrl'         => 'https://app.com/success',
    'failureUrl'         => 'https://app.com/failed',
    'paymentCurrency'    => 'USX',
    'paymentGatewayName' => 'USDT TRC20',
    'customer'           => [...],
], apiToken: config('payments.gateways.match2pay.api_token'),
   apiSecret: config('payments.gateways.match2pay.secret'));

// $response['checkoutUrl'] → redirect to this
// $response['paymentId']   → store this as your transaction reference
// $response['expiration']  → wallet address expires at this UTC time
```

### Withdrawal

```php
$response = $m2p->withdrawal()->create([
    'amount'             => 100.00,
    'currency'           => 'USD',
    'cryptoAddress'      => 'TRX_wallet_address_here',
    'callbackUrl'        => route('payments.webhook', 'match2pay'),
    'successUrl'         => 'https://app.com/success',
    'failureUrl'         => 'https://app.com/failed',
    'paymentCurrency'    => 'USX',
    'paymentGatewayName' => 'USDT TRC20',
    'customer'           => [...],
], apiToken: config('payments.gateways.match2pay.api_token'),
   apiSecret: config('payments.gateways.match2pay.secret'));
```

> **TON withdrawals with memo:** Use `"walletAddress;memo"` format for `cryptoAddress`.

### Manual Signature Generation

```php
use Subtain\LaravelPayments\Gateways\Match2Pay\SignatureService;

$signature = SignatureService::buildRequestSignature($payload, $apiSecret);
```

---

## Wallet Address Expiry

Match2Pay wallet addresses expire after:
- **30 minutes** for all cryptocurrencies
- **2 hours** for Bitcoin (BTC)

A **12-hour grace period** applies after expiry — deposits confirmed on-chain within this window are still accepted.

> **IMPORTANT:** Wallet addresses are single-use by default. Never reuse a previously generated address. If a client deposits to an expired and recycled address, funds may be lost.

---

## Using with PaymentService (DB Tracking)

```php
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\PaymentService;

$result = app(PaymentService::class)->initiate(
    gateway: 'match2pay',
    request: new CheckoutRequest(
        amount:     299.00,
        currency:   'USD',
        webhookUrl: route('payments.webhook', 'match2pay'),
        successUrl: 'https://app.com/payment/success',
        extra: [
            'payment_currency'     => 'USX',
            'payment_gateway_name' => 'USDT TRC20',
        ],
    ),
    payable: $order,
);
```

The `PaymentService` creates an `lp_payments` record, calls `checkout()`, and updates it to `processing`. When the DONE callback arrives, the `WebhookController` finds the record by `paymentId` and transitions it to `paid`, then dispatches `PaymentSucceeded`.
