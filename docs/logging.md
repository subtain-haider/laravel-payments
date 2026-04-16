# Logging

All log output from every gateway, HTTP client, webhook handler, and service in this package flows through a single central class: `PaymentLogger`. This gives you complete control over where payment logs go, at what verbosity, and what sensitive data is masked — all from one config block.

---

## Zero Configuration

Out of the box, the package logs to the same channel as the rest of your Laravel application. No setup required. Every log entry is prefixed for easy filtering:

```
[payments:match2pay:checkout] checkout.initiated
[payments:fanbasis:api] api.request
[payments:rebornpay:webhook] webhook.parsed
```

---

## Log Events Reference

Every event the package emits, organized by category:

### Checkout (`category: checkout`)

| Event | Level | Trigger |
|---|---|---|
| `checkout.initiated` | info | `PaymentService::initiate()` or gateway checkout called |
| `checkout.success` | info | Gateway returned a valid redirect URL |
| `checkout.empty_url` | error | Gateway response missing checkout/redirect URL |
| `checkout.http_error` | error | HTTP request to gateway failed (non-2xx) |
| `checkout.gateway_error` | error | Gateway returned a business-logic error |
| `checkout.failed` | error | Exception thrown during checkout (via `PaymentService`) |

### Webhooks (`category: webhook`)

| Event | Level | Trigger |
|---|---|---|
| `webhook.parsed` | info | Incoming webhook parsed into `WebhookResult` |
| `webhook.verification_skipped` | warning | Signature check bypassed (secret not configured) |
| `webhook.missing_signature` | warning | DONE callback arrived without expected signature header |
| `webhook.signature_failed` | warning | Signature verification returned false |

### API Client (`category: api`)

| Event | Level | Trigger |
|---|---|---|
| `api.request` | debug | HTTP request sent to gateway API |
| `api.response` | debug | Successful HTTP response received |
| `api.error` | error | Non-2xx HTTP response or gateway error body |
| `api.exception` | error | Connection timeout, DNS failure, or other exception |

### PaymentService (`no category`)

| Event | Level | Trigger |
|---|---|---|
| `checkout_initiated` | — | Logged via `PaymentLog` DB record, not `PaymentLogger` |
| `checkout_failed` | — | Logged via `PaymentLog` DB record, not `PaymentLogger` |

> **Note:** `PaymentLog::logWebhook()` writes structured records to the `lp_payment_logs` database table — this is your **webhook audit trail**, separate from observability logs. Both systems run independently.

---

## All Config Options

In your published `config/payments.php`, under the `logging` key:

```php
'logging' => [

    // Master on/off switch. Set to false to silence all package logs.
    'enabled' => env('PAYMENTS_LOGGING_ENABLED', true),

    // Global minimum level. Calls below this are dropped silently.
    // PSR-3 levels: debug, info, notice, warning, error, critical, alert, emergency
    'level' => env('PAYMENTS_LOG_LEVEL', 'debug'),

    // Per-gateway channel routing.
    // Key = gateway name. Value = a channel defined in config/logging.php.
    // 'default' is the fallback for gateways not explicitly listed.
    // If 'default' is null, Laravel's own default channel is used.
    'channels' => [
        'default' => env('PAYMENTS_LOG_CHANNEL', null),
    ],

    // Per-gateway minimum level overrides.
    // Overrides the global 'level' for the named gateway only.
    'levels' => [],

    // Keys to redact from log context (case-insensitive, recursive).
    // Values matching these keys are replaced with '[redacted]'.
    'redact' => [
        'api_key', 'api_token', 'secret', 'signature',
        'postback_key', 'webhook_secret', 'authorization', 'password', 'token',
    ],

],
```

---

## Common Recipes

### 1. Send all payment logs to a dedicated file

```php
// config/logging.php
'channels' => [
    'payments' => [
        'driver' => 'single',
        'path'   => storage_path('logs/payments.log'),
        'level'  => 'debug',
    ],
],

// config/payments.php
'logging' => [
    'channels' => ['default' => 'payments'],
],
```

### 2. Route different gateways to different channels

Match2Pay errors to Slack, Rebornpay to a dedicated file, everything else to the default stack:

```php
// config/payments.php
'logging' => [
    'channels' => [
        'match2pay'  => 'slack',
        'rebornpay'  => 'rebornpay_log',
        'default'    => null,  // use app's default
    ],
],

// config/logging.php
'channels' => [
    'slack' => [
        'driver'   => 'slack',
        'url'      => env('LOG_SLACK_WEBHOOK_URL'),
        'username' => 'Payments',
        'emoji'    => ':money_with_wings:',
        'level'    => 'error',  // only errors hit Slack
    ],
    'rebornpay_log' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/rebornpay.log'),
        'days'   => 14,
    ],
],
```

### 3. Telegram notifications for payment failures

Install a community Telegram logger, register it as a channel, and point the gateway to it:

```php
// config/logging.php
'channels' => [
    'telegram' => [
        'driver'  => 'custom',
        'via'     => \YourApp\Logging\TelegramLogger::class,
        'level'   => 'error',
        'token'   => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],
],

// config/payments.php
'logging' => [
    'channels' => [
        'default' => 'telegram',  // all gateway errors → Telegram
    ],
    'level' => 'error',           // only errors (not info/debug) sent
],
```

### 4. Multiple destinations (stack)

Send all payment logs to both a file AND Slack (errors only on Slack):

```php
// config/logging.php
'channels' => [
    'payments_stack' => [
        'driver'   => 'stack',
        'channels' => ['payments_file', 'slack'],
    ],
    'payments_file' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/payments.log'),
        'level'  => 'debug',
        'days'   => 30,
    ],
    'slack' => [
        'driver' => 'slack',
        'url'    => env('LOG_SLACK_WEBHOOK_URL'),
        'level'  => 'error',
    ],
],

// config/payments.php
'logging' => [
    'channels' => ['default' => 'payments_stack'],
],
```

### 5. Log to a custom database (MySQL / Postgres)

Create a custom Monolog handler that inserts into your DB, register it as a channel:

```php
// app/Logging/DatabaseLogger.php
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class DatabaseLogger extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        \DB::table('payment_logs_custom')->insert([
            'level'      => $record->level->name,
            'message'    => $record->message,
            'context'    => json_encode($record->context),
            'created_at' => now(),
        ]);
    }
}

// config/logging.php
'channels' => [
    'db_payments' => [
        'driver' => 'custom',
        'via'    => \App\Logging\CreateDatabaseLogger::class,
    ],
],

// config/payments.php
'logging' => [
    'channels' => ['default' => 'db_payments'],
],
```

### 6. Log to ClickHouse

Same approach — write a custom Monolog handler that sends to ClickHouse via HTTP:

```php
// app/Logging/ClickHouseHandler.php
protected function write(LogRecord $record): void
{
    Http::post('http://clickhouse:8123/', sprintf(
        "INSERT INTO payment_logs FORMAT JSONEachRow %s",
        json_encode([
            'timestamp' => now()->toIso8601String(),
            'level'     => $record->level->name,
            'message'   => $record->message,
            'context'   => json_encode($record->context),
        ])
    ));
}
```

### 7. Production-recommended setup

Debug logs are too noisy for production. Use `info` globally and keep only one gateway at `debug` during an integration:

```php
// .env
PAYMENTS_LOG_LEVEL=info
PAYMENTS_LOG_CHANNEL=payments

// config/payments.php
'logging' => [
    'level'  => env('PAYMENTS_LOG_LEVEL', 'info'),
    'channels' => [
        'default' => env('PAYMENTS_LOG_CHANNEL', null),
    ],
    'levels' => [
        // Uncomment during gateway integration/debugging:
        // 'match2pay' => 'debug',
    ],
],
```

### 8. Disable all logs (test environments)

```php
// .env.testing
PAYMENTS_LOGGING_ENABLED=false
```

Or in `phpunit.xml`:

```xml
<env name="PAYMENTS_LOGGING_ENABLED" value="false"/>
```

---

## Custom Redaction

The default redact list covers standard credential field names. Add your own:

```php
'logging' => [
    'redact' => [
        'api_key', 'api_token', 'secret', 'signature',
        'postback_key', 'webhook_secret', 'authorization', 'password', 'token',
        // Add your gateway's sensitive fields:
        'my_gateway_private_key',
        'customer_ssn',
    ],
],
```

Redaction is **recursive** — nested arrays are also scanned. Keys are matched **case-insensitively**.

---

## Using PaymentLogger in Your Own Gateway

When you implement a custom gateway (see [Add a Custom Gateway](../README.md#add-a-custom-gateway)), use `PaymentLogger` directly — do not use `Log::` directly. This ensures your gateway respects the developer's channel/level configuration.

```php
use Subtain\LaravelPayments\PaymentLogger;

class StripeGateway implements PaymentGateway
{
    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        PaymentLogger::info('checkout.initiated', [
            'invoice_id' => $request->invoiceId,
            'amount'     => $request->amount,
        ], gateway: 'stripe', category: 'checkout');

        try {
            $response = $this->callStripeApi($request);

            PaymentLogger::info('checkout.success', [
                'invoice_id'    => $request->invoiceId,
                'payment_intent' => $response['id'],
            ], gateway: 'stripe', category: 'checkout');

            return new CheckoutResult(...);

        } catch (\Throwable $e) {
            PaymentLogger::error('checkout.failed', [
                'invoice_id' => $request->invoiceId,
                'error'      => $e->getMessage(),
            ], gateway: 'stripe', category: 'checkout');

            throw $e;
        }
    }
}
```

Then in `config/payments.php`, the developer can route `stripe` logs just like any built-in gateway:

```php
'logging' => [
    'channels' => [
        'stripe' => 'slack',
    ],
],
```

---

## How Channel Resolution Works

For every log call, the channel is resolved in this order:

```
1. config('payments.logging.channels.{gateway}')   ← per-gateway override
       ↓ null?
2. config('payments.logging.channels.default')      ← package default
       ↓ null?
3. Laravel's own default channel                    ← app's config('logging.default')
```

And the minimum level:

```
1. config('payments.logging.levels.{gateway}')     ← per-gateway level override
       ↓ not set?
2. config('payments.logging.level')                ← global package level
       ↓ not set?
3. 'debug'                                         ← logs everything (hardcoded fallback)
```

---

## Log Message Format

Every log entry follows this pattern:

```
[payments:{gateway}:{category}] {event}
```

Examples:
- `[payments:match2pay:checkout] checkout.initiated`
- `[payments:fanbasis:api] api.error`
- `[payments:rebornpay:webhook] webhook.signature_failed`

When no gateway is provided (rare internal calls):
- `[payments] some.event`

This prefix makes it trivial to grep or filter:

```bash
# All payment errors
grep '\[payments' laravel.log | grep '"level":"error"'

# Only Match2Pay webhooks
grep '\[payments:match2pay:webhook\]' laravel.log

# All signature failures across all gateways
grep 'signature_failed' laravel.log
```
