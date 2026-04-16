<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | The gateway to use when none is specified. Must match a key in the
    | 'gateways' array below.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'fanbasis'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Path
    |--------------------------------------------------------------------------
    |
    | The URL path where the package registers its webhook route.
    | Full URL will be: {APP_URL}/{webhook_path}/{gateway}
    |
    | Example: POST https://yourapp.com/payments/webhook/fanbasis
    |
    */

    'webhook_path' => 'payments/webhook',

    /*
    |--------------------------------------------------------------------------
    | Webhook Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the webhook route. By default, no auth
    | middleware is applied since webhooks come from payment providers.
    |
    */

    'webhook_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Customize the table names used by the package. Useful if your app
    | already has a 'payments' table or you prefer a different naming
    | convention.
    |
    */

    'tables' => [
        'payments'             => 'lp_payments',
        'payment_logs'         => 'lp_payment_logs',
        'discount_codes'       => 'lp_discount_codes',
        'discount_code_usages' => 'lp_discount_code_usages',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Each gateway has its own configuration. Add or remove gateways
    | as needed. The key must match what you pass to Payment::gateway().
    |
    | To add a custom gateway:
    | 1. Create a class implementing Subtain\LaravelPayments\Contracts\PaymentGateway
    | 2. Add a 'driver' key pointing to your class
    | 3. Add any config your gateway needs
    |
    */

    'gateways' => [

        'fanbasis' => [
            'driver'         => \Subtain\LaravelPayments\Gateways\FanbasisGateway::class,
            'base_url'       => env('FANBASIS_BASE_URL', 'https://www.fanbasis.com/public-api'),
            'api_key'        => env('FANBASIS_API_KEY'),
            'webhook_secret' => env('FANBASIS_WEBHOOK_SECRET'),
            'creator_handle' => env('FANBASIS_CREATOR_HANDLE'),
            'timeout'        => (int) env('FANBASIS_TIMEOUT', 30),
            'retries'        => (int) env('FANBASIS_RETRIES', 2),
        ],

        'premiumpay' => [
            'driver'   => \Subtain\LaravelPayments\Gateways\PremiumPayGateway::class,
            'base_url' => env('PREMIUMPAY_BASE_URL', 'https://pre.api.premiumpay.pro/api/v1'),
            'api_key'  => env('PREMIUMPAY_API_KEY'),
        ],

        'match2pay' => [
            'driver'    => \Subtain\LaravelPayments\Gateways\Match2PayGateway::class,
            'base_url'  => env('MATCH2PAY_API_URL', 'https://wallet.match2pay.com/api/v2/'),
            'api_token' => env('MATCH2PAY_API_TOKEN'),
            'secret'    => env('MATCH2PAY_API_SECRET'),
            'timeout'   => (int) env('MATCH2PAY_TIMEOUT', 30),
            'retries'   => (int) env('MATCH2PAY_RETRIES', 2),
        ],

        'rebornpay' => [
            'driver'       => \Subtain\LaravelPayments\Gateways\RebornpayGateway::class,
            'base_url'     => env('REBORNPAY_BASE_URL', 'https://prod.api.rbpcloud.pro'),
            'api_key'      => env('REBORNPAY_API_KEY'),
            'client_id'    => env('REBORNPAY_CLIENT_ID'),
            'postback_key' => env('REBORNPAY_CLIENT_POSTBACK_KEY'),
            'timeout'      => (int) env('REBORNPAY_TIMEOUT', 30),
            'retries'      => (int) env('REBORNPAY_RETRIES', 2),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Controls how and where the package writes its logs.
    |
    | ALL log output from every gateway, HTTP client, webhook handler, and
    | service in this package flows through PaymentLogger, which reads this
    | config to decide:
    |   - Whether to write the log at all (enabled, level)
    |   - Which Laravel log channel to write to (channels)
    |   - Which context fields to mask before writing (redact)
    |
    | Channel resolution order per log call:
    |   1. Per-gateway channel:  channels['match2pay'] (if configured)
    |   2. Default channel:      channels['default']   (if configured)
    |   3. App default:          config('logging.default') — Laravel's own default
    |
    | This means zero configuration is required. Out of the box the package
    | logs to the same place as the rest of your application. You only need
    | to set channels when you want payment logs routed differently.
    |
    | Available log levels (PSR-3, lowest to highest):
    |   debug → info → notice → warning → error → critical → alert → emergency
    |
    | See docs/logging.md for full examples (Slack, Telegram, DB, ClickHouse).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Sandbox Mode
    |--------------------------------------------------------------------------
    |
    | Sandbox mode lets you run the full payment flow without charging real
    | money. When a gateway (or a specific user) is sandboxed:
    |
    |   - The real gateway API is NOT called.
    |   - A SandboxGateway returns a simulated CheckoutResult instantly.
    |   - The lp_payments record is created with is_sandbox = true.
    |   - Every lp_payment_logs entry is created with is_sandbox = true.
    |   - All events (PaymentSucceeded, etc.) fire exactly as they would in prod.
    |   - A sandbox-confirm endpoint lets QA trigger the success webhook manually.
    |
    | This lets local and staging environments run the complete purchase flow —
    | DB records, logs, events, downstream listeners — without any real payment.
    |
    | ── Point 2: Environment-level sandbox ─────────────────────────────────
    |
    | Set PAYMENTS_SANDBOX=true and the entire payment layer goes into sandbox
    | mode. Optionally restrict it to specific gateways via PAYMENTS_SANDBOX_GATEWAYS.
    |
    |   PAYMENTS_SANDBOX=true
    |   PAYMENTS_SANDBOX_GATEWAYS=*                  — all gateways
    |   PAYMENTS_SANDBOX_GATEWAYS=fanbasis,match2pay  — only those two
    |
    | ── Point 3: Per-user / per-role bypass ────────────────────────────────
    |
    | Specific user IDs or roles always bypass real payment regardless of
    | whether sandbox mode is enabled globally. Useful for internal QA accounts
    | that must work on production without paying.
    |
    |   'bypass_user_ids' => [1, 42],
    |   'bypass_roles'    => ['admin', 'qa_tester'],
    |
    | To resolve roles the package calls $user->getRoleNames() (spatie/laravel-permission)
    | if available, then falls back to $user->roles (relationship), then $user->role
    | (plain string column). Override entirely via the 'role_resolver' callable.
    |
    | ── Sandbox confirm endpoint ────────────────────────────────────────────
    |
    | Since sandboxed payments never receive a real webhook, QA needs a way to
    | simulate "payment confirmed". The package registers:
    |
    |   GET {webhook_path}/sandbox/confirm/{invoice_id}
    |
    | This endpoint is only active when sandbox.enabled = true OR the app
    | environment is local/testing. It fires PaymentSucceeded exactly like a
    | real webhook would. The endpoint hard-rejects any invoice whose DB record
    | has is_sandbox = false — so real payments can never be confirmed here,
    | even if sandbox mode is toggled on after the fact.
    |
    */

    'sandbox' => [

        /*
        | Master switch. Set PAYMENTS_SANDBOX=true on local/staging .env files.
        | On production this should always be false (or simply not set).
        */
        'enabled' => env('PAYMENTS_SANDBOX', false),

        /*
        | Which gateways run in sandbox mode.
        |
        | '*'   — all configured gateways are sandboxed (default when enabled).
        | CSV   — only the listed gateways: 'fanbasis,match2pay'
        |
        | Gateways not listed here will still make real API calls even when
        | sandbox.enabled = true. This lets you sandbox one gateway at a time.
        */
        'gateways' => env('PAYMENTS_SANDBOX_GATEWAYS', '*'),

        /*
        | Specific user IDs that always bypass real payment, on any environment.
        | These users go through the sandbox path even on production.
        |
        | Example: [1, 42, 100]
        */
        'bypass_user_ids' => [],

        /*
        | Roles/guards that always bypass real payment, on any environment.
        | Any user whose role matches one of these strings is sandboxed.
        |
        | Example: ['admin', 'qa_tester', 'internal']
        */
        'bypass_roles' => [],

        /*
        | Optional callable to resolve a user's roles for bypass_roles checks.
        |
        | Signature: fn(\Illuminate\Contracts\Auth\Authenticatable $user): array
        | Must return an array of role name strings.
        |
        | When null the package uses its built-in resolver which tries:
        |   1. $user->getRoles()                 (spatie/laravel-permission style)
        |   2. $user->roles->pluck('name')        (hasMany relationship)
        |   3. [$user->role]                      (plain string column)
        */
        'role_resolver' => null,

        /*
        | The URL the SandboxGateway returns as the "checkout redirect".
        | The user is sent here instead of a real payment page.
        |
        | Use a route in your app that explains this is a sandboxed payment,
        | or just point to your standard success page for seamless QA.
        */
        'redirect_url' => env('PAYMENTS_SANDBOX_REDIRECT_URL', '/sandbox/payment-pending'),

    ],

    'logging' => [

        /*
        | Set to false to completely silence all package logs.
        | Useful in test environments where log noise is unwanted.
        */
        'enabled' => env('PAYMENTS_LOGGING_ENABLED', true),

        /*
        | Minimum log level. Calls below this level are silently dropped.
        |
        | Default: 'debug' — logs everything.
        | Production recommendation: 'info' — skips debug-level API dumps.
        |
        | This is the global default. Override per-gateway in 'levels' below.
        */
        'level' => env('PAYMENTS_LOG_LEVEL', 'debug'),

        /*
        | Per-gateway (and global default) log channel routing.
        |
        | Keys must match gateway names as defined in config('lp_payments.gateways').
        | Values must match a channel name defined in config('logging.channels').
        |
        | The special key 'default' is the fallback for any gateway not
        | explicitly listed. If 'default' is null, Laravel's own default
        | logging channel is used.
        |
        | To send to multiple channels at once, define a 'stack' channel in
        | your config/logging.php and point to it here.
        |
        | Examples:
        |   'match2pay'  => 'slack'           — Match2Pay to Slack
        |   'rebornpay'  => 'telegram'        — Rebornpay to Telegram
        |   'fanbasis'   => 'payments_db'     — Fanbasis to a DB-backed channel
        |   'default'    => 'stack'           — everything else to a stack
        */
        'channels' => [
            'default' => env('PAYMENTS_LOG_CHANNEL', null),
            // 'match2pay'  => 'slack',
            // 'rebornpay'  => 'telegram',
            // 'fanbasis'   => 'payments',
            // 'premiumpay' => 'payments',
        ],

        /*
        | Per-gateway minimum log level overrides.
        |
        | Useful when you want verbose debug logs for one gateway while keeping
        | others at 'info'. Or when a new gateway is in development and you
        | want 'debug' for it alone.
        |
        | Example: ['match2pay' => 'debug', 'fanbasis' => 'warning']
        */
        'levels' => [
            // 'match2pay' => 'debug',
        ],

        /*
        | Fields to redact from log context before writing.
        |
        | Any context key whose name (case-insensitive) matches an entry here
        | will have its value replaced with '[redacted]'. This applies
        | recursively to nested arrays.
        |
        | The defaults cover common credential field names used across all
        | built-in gateways. Add your custom gateway's sensitive fields here.
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

];
