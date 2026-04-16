# Logging

All log output from every gateway, HTTP client, webhook handler, and service in this package flows through a single central class: `PaymentLogger`. This gives you complete control over where payment logs go, at what verbosity, and what sensitive data is masked — all from one config block in `config/lp_payments.php`.

---

## It Works With Zero Setup

Out of the box, this package logs to the same place as the rest of your Laravel app. You don't need to touch anything. Every log entry is prefixed so you can easily identify payment-related logs:

```
[payments:match2pay:checkout] checkout.initiated
[payments:fanbasis:api] api.request
[payments:rebornpay:webhook] webhook.parsed
```

You only need to read further if you want to:
- Send payment logs to a **separate file** or **Slack/Telegram**
- **Silence** noisy debug logs in production
- **Route** different gateways to different destinations

---

## Background: Two Config Files

The recipes in this guide reference two different config files. Here is what each one is:

### `config/lp_payments.php` — This package's config

This is the config file published by this package. It controls which Laravel log channel payment logs are sent to. You tell it: *"send Rebornpay logs to Slack, send everything else to a file"*.

Publish it if you haven't already:

```bash
php artisan vendor:publish --tag=payments-config
```

### `config/logging.php` — Laravel's built-in logging config

This file **already exists in every Laravel app** — you did not create it. It is where you define **log channels**: a "channel" is just a named destination (a file, Slack, a database, etc.) with a driver and some options.

Open `config/logging.php` in your project and you'll see channels like `single`, `daily`, `stack`, `slack` already there. You just need to **add your own channel** when you want a custom destination, then tell this package to use it.

**The relationship in one sentence:** You define a channel in `config/logging.php`, then you point this package at that channel in `config/lp_payments.php`.

---

## Log Levels — What They Mean

Every log call has a level that indicates how important it is. Levels are ordered from lowest to highest severity:

| Level | When it's used |
|---|---|
| `debug` | Raw HTTP request/response payloads sent to a gateway. Very noisy, useful only when debugging a specific gateway. |
| `info` | Normal operational events: checkout started, payment webhook received and parsed. |
| `warning` | Something unexpected happened but the system kept going: signature check was skipped because no secret is configured. |
| `error` | Something failed: gateway returned an error, HTTP request failed, signature verification failed. |
| `critical` | Severe failures that need immediate attention. Not currently used by this package but available for custom gateways. |

**The key rule:** When you set a minimum level, all levels *below* it are silently dropped. For example, setting `'level' => 'info'` means `debug` calls are ignored. Setting `'level' => 'error'` means only errors and above are logged — no info, no warnings.

**Recommended by environment:**
- **Local development:** `debug` — see everything, including raw API payloads
- **Staging:** `info` — see operational events, skip the noise
- **Production:** `info` or `warning` — you want to know about normal activity but not be flooded

---

## All Config Options

Open `config/lp_payments.php` in your project. Under the `logging` key you'll find:

```php
'logging' => [

    /*
     * Master on/off switch.
     * Set to false (or PAYMENTS_LOGGING_ENABLED=false in .env) to completely
     * silence all logs from this package. Useful in test environments.
     */
    'enabled' => env('PAYMENTS_LOGGING_ENABLED', true),

    /*
     * Global minimum log level.
     *
     * Any log call below this level is silently dropped before it even reaches
     * a channel. Levels from lowest to highest:
     *   debug → info → warning → error → critical
     *
     * 'debug'   = log everything (default, good for local development)
     * 'info'    = skip debug-level API dumps (good for staging)
     * 'warning' = only warnings and errors (good for production)
     * 'error'   = only errors (minimal production logging)
     *
     * Override per-gateway in 'levels' below.
     */
    'level' => env('PAYMENTS_LOG_LEVEL', 'debug'),

    /*
     * Log channel routing — where logs are sent.
     *
     * A "channel" is a named log destination defined in config/logging.php
     * (Laravel's built-in logging config file, already in your project).
     *
     * You can route by gateway name: 'match2pay' => 'slack' sends all
     * Match2Pay logs to Slack. The special key 'default' is the fallback
     * for any gateway not explicitly listed. If 'default' is null, Laravel's
     * own default channel (usually the 'stack' channel) is used.
     */
    'channels' => [
        'default' => env('PAYMENTS_LOG_CHANNEL', null),
        // 'match2pay'  => 'slack',
        // 'rebornpay'  => 'telegram',
        // 'fanbasis'   => 'payments_file',
    ],

    /*
     * Per-gateway minimum level overrides.
     *
     * Overrides the global 'level' for a specific gateway only. Useful when
     * you are integrating a new gateway and want verbose debug logs for it
     * while keeping everything else at 'info'.
     *
     * Example: ['match2pay' => 'debug', 'fanbasis' => 'warning']
     */
    'levels' => [
        // 'match2pay' => 'debug',
    ],

    /*
     * Sensitive fields to redact from log context before writing.
     *
     * Any context key whose name (case-insensitive) matches an entry here
     * will have its value replaced with '[redacted]'. Works recursively
     * on nested arrays. The defaults cover all credential field names
     * used across the built-in gateways.
     */
    'redact' => [
        'api_key',
        'api_token',
        'secret',
        'signature',
        'postback_key',
        'webhook_secret',
        'authorization',
        'password',
        'token',
    ],

],
```

---

## Log Message Format

Every log entry follows this pattern:

```
[payments:{gateway}:{category}] {event}
```

Real examples from the package:
```
[payments:match2pay:checkout] checkout.initiated
[payments:fanbasis:api] api.error
[payments:rebornpay:webhook] webhook.signature_failed
```

This prefix makes it easy to filter logs on the command line:

```bash
# All payment-related logs
grep '\[payments' storage/logs/laravel.log

# Only errors from any gateway
grep '\[payments' storage/logs/laravel.log | grep 'error'

# Only Match2Pay webhook events
grep '\[payments:match2pay:webhook\]' storage/logs/laravel.log

# All signature failures
grep 'signature_failed' storage/logs/laravel.log
```

---

## Log Events Reference

Every event this package emits, with its level and what triggers it:

### Checkout events

| Event | Level | What happened |
|---|---|---|
| `checkout.initiated` | info | A checkout was started — amount, currency, and invoice ID logged |
| `checkout.success` | info | Gateway returned a valid redirect URL |
| `checkout.empty_url` | error | Gateway responded but the redirect URL was missing |
| `checkout.http_error` | error | The HTTP request to the gateway returned a non-2xx status |
| `checkout.gateway_error` | error | The gateway returned a business-logic error (e.g. invalid amount) |
| `checkout.failed` | error | An exception was thrown during checkout |

### Webhook events

| Event | Level | What happened |
|---|---|---|
| `webhook.parsed` | info | An incoming webhook was received and successfully parsed |
| `webhook.verification_skipped` | warning | Signature check was skipped because no secret key is configured |
| `webhook.missing_signature` | warning | A webhook arrived without the expected signature header |
| `webhook.signature_failed` | warning | Signature verification ran but the signature did not match |

### API client events

| Event | Level | What happened |
|---|---|---|
| `api.request` | debug | An HTTP request was sent to a gateway API (includes endpoint and method) |
| `api.response` | debug | A successful HTTP response was received |
| `api.error` | error | An HTTP request failed or the gateway returned an error body |
| `api.exception` | error | A connection error occurred (timeout, DNS failure, etc.) |

> **Note on the DB audit trail:** There is a separate system that writes structured records to the `lp_payment_logs` database table (`PaymentLog`). This is your permanent webhook audit trail, independent from these log events. Both run side by side — the file/channel logs are for real-time observability, the DB records are for historical lookup and reconciliation.

---

## Recipes

Each recipe below has two parts:
1. **`config/logging.php`** — define a new channel (where logs go)
2. **`config/lp_payments.php`** — tell this package to use that channel

If the channel you want already exists in `config/logging.php` (like `slack` or `stack`), you only need step 2.

---

### Recipe 1 — Send all payment logs to a dedicated file

Keeps payment logs in their own file instead of mixed into `laravel.log`.

**Step 1:** Add a channel to `config/logging.php`:

```php
'channels' => [
    // ... your existing channels ...

    'payments' => [
        'driver' => 'daily',              // creates a new file each day
        'path'   => storage_path('logs/payments.log'),
        'level'  => 'debug',             // capture everything
        'days'   => 14,                  // keep 14 days of files
    ],
],
```

**Step 2:** Point this package at that channel in `config/lp_payments.php`:

```php
'logging' => [
    'channels' => ['default' => 'payments'],
],
```

---

### Recipe 2 — Route different gateways to different destinations

Match2Pay logs to Slack (errors only), Rebornpay to its own file, everything else to the app default.

**Step 1:** Add channels to `config/logging.php`:

```php
'channels' => [
    'slack' => [
        'driver'   => 'slack',
        'url'      => env('LOG_SLACK_WEBHOOK_URL'),
        'username' => 'Payments Bot',
        'emoji'    => ':money_with_wings:',
        'level'    => 'error',           // Slack only gets errors
    ],
    'rebornpay_log' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/rebornpay.log'),
        'days'   => 14,
    ],
],
```

**Step 2:** Route gateways in `config/lp_payments.php`:

```php
'logging' => [
    'channels' => [
        'match2pay' => 'slack',
        'rebornpay' => 'rebornpay_log',
        'default'   => null,             // everything else uses app default
    ],
],
```

---

### Recipe 3 — Telegram notifications for payment failures

**Step 1:** Install a community Telegram Monolog driver (e.g. `laravel-notification-channels/telegram`) or write a small custom handler. Then register it as a channel in `config/logging.php`:

```php
'channels' => [
    'telegram' => [
        'driver'  => 'custom',
        'via'     => \App\Logging\TelegramLogger::class,
        'level'   => 'error',
        'token'   => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],
],
```

**Step 2:** In `config/lp_payments.php`, send only errors so Telegram is not spammed by debug/info:

```php
'logging' => [
    'level'    => 'error',               // drop debug and info globally
    'channels' => ['default' => 'telegram'],
],
```

---

### Recipe 4 — Log to both a file and Slack at the same time (stack)

Laravel's `stack` channel lets you write to multiple destinations at once.

**Step 1:** Add a stack and its members to `config/logging.php`:

```php
'channels' => [
    'payments_stack' => [
        'driver'   => 'stack',
        'channels' => ['payments_file', 'slack'],  // writes to both
    ],
    'payments_file' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/payments.log'),
        'level'  => 'debug',             // file gets everything
        'days'   => 30,
    ],
    'slack' => [
        'driver' => 'slack',
        'url'    => env('LOG_SLACK_WEBHOOK_URL'),
        'level'  => 'error',             // Slack only gets errors
    ],
],
```

**Step 2:** In `config/lp_payments.php`:

```php
'logging' => [
    'channels' => ['default' => 'payments_stack'],
],
```

---

### Recipe 5 — Production-recommended setup

Debug logs are too noisy for production. Use `info` globally, and only enable `debug` temporarily for a specific gateway when debugging an integration.

**`.env` (production):**

```
PAYMENTS_LOG_LEVEL=info
PAYMENTS_LOG_CHANNEL=payments
```

**`config/lp_payments.php`:**

```php
'logging' => [
    'level'    => env('PAYMENTS_LOG_LEVEL', 'info'),
    'channels' => [
        'default' => env('PAYMENTS_LOG_CHANNEL', null),
    ],
    'levels' => [
        // Temporarily uncomment this when debugging a specific gateway:
        // 'match2pay' => 'debug',
    ],
],
```

---

### Recipe 6 — Silence all logs in tests

**Option A — `.env.testing`:**

```
PAYMENTS_LOGGING_ENABLED=false
```

**Option B — `phpunit.xml`:**

```xml
<env name="PAYMENTS_LOGGING_ENABLED" value="false"/>
```

---

### Recipe 7 — Log to a custom database table

Useful if you want payment logs stored in MySQL/Postgres alongside your app data.

Create a Monolog handler class in your app:

```php
// app/Logging/PaymentDatabaseHandler.php
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

class PaymentDatabaseHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        \DB::table('payment_observability_logs')->insert([
            'level'      => $record->level->name,
            'message'    => $record->message,
            'context'    => json_encode($record->context),
            'created_at' => now(),
        ]);
    }
}
```

Register a factory (Laravel requires a factory class, not the handler directly):

```php
// app/Logging/CreatePaymentDatabaseLogger.php
use Monolog\Logger;

class CreatePaymentDatabaseLogger
{
    public function __invoke(array $config): Logger
    {
        return (new Logger('payments'))
            ->pushHandler(new \App\Logging\PaymentDatabaseHandler());
    }
}
```

Add the channel to `config/logging.php`:

```php
'channels' => [
    'payments_db' => [
        'driver' => 'custom',
        'via'    => \App\Logging\CreatePaymentDatabaseLogger::class,
    ],
],
```

Point the package at it in `config/lp_payments.php`:

```php
'logging' => [
    'channels' => ['default' => 'payments_db'],
],
```

---

## Redacting Sensitive Data

Before any log is written, the package scans the context array and replaces sensitive field values with `[redacted]`. This prevents API keys, tokens, and signatures from ever appearing in log files.

The default redact list covers all credential fields used by the built-in gateways. You can extend it with fields from your own custom gateway:

```php
'logging' => [
    'redact' => [
        'api_key', 'api_token', 'secret', 'signature',
        'postback_key', 'webhook_secret', 'authorization', 'password', 'token',
        // Add your custom gateway's sensitive fields:
        'private_key',
        'customer_ssn',
        'card_number',
    ],
],
```

Redaction is **recursive** (nested arrays are also scanned) and **case-insensitive** (`API_KEY`, `api_key`, and `Api-Key` all match).

---

## Using PaymentLogger in a Custom Gateway

If you build a custom gateway, use `PaymentLogger` for all logging inside it. This ensures your gateway automatically respects the developer's channel routing and level configuration — just like the built-in gateways.

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
                'invoice_id'     => $request->invoiceId,
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

Developers using your gateway can then route it in `config/lp_payments.php` exactly the same way as any built-in gateway:

```php
'logging' => [
    'channels' => [
        'stripe' => 'slack',
    ],
],
```

---

## How Channel Resolution Works

For every log call, the package looks up the destination in this order and uses the first match:

```
1. config('lp_payments.logging.channels.{gateway}')
         ↓ not set?
2. config('lp_payments.logging.channels.default')
         ↓ null?
3. Laravel's own default channel (config('logging.default'))
```

And the minimum level is resolved in this order:

```
1. config('lp_payments.logging.levels.{gateway}')   ← per-gateway override
         ↓ not set?
2. config('lp_payments.logging.level')              ← global package level
         ↓ not set?
3. 'debug'                                          ← hardcoded fallback (logs everything)
```
