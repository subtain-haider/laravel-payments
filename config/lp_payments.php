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
