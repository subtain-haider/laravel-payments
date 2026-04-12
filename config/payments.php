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
        'payments'     => 'lp_payments',
        'payment_logs' => 'lp_payment_logs',
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
            'driver'   => \Subtain\LaravelPayments\Gateways\FanbasisGateway::class,
            'base_url' => env('FANBASIS_BASE_URL', 'https://www.fanbasis.com/public-api'),
            'api_key'  => env('FANBASIS_API_KEY'),
        ],

        'premiumpay' => [
            'driver'   => \Subtain\LaravelPayments\Gateways\PremiumPayGateway::class,
            'base_url' => env('PREMIUMPAY_BASE_URL', 'https://pre.api.premiumpay.pro/api/v1'),
            'api_key'  => env('PREMIUMPAY_API_KEY'),
        ],

        'match2pay' => [
            'driver'    => \Subtain\LaravelPayments\Gateways\Match2PayGateway::class,
            'base_url'  => env('MATCH2PAY_API_URL'),
            'api_token' => env('MATCH2PAY_API_TOKEN'),
            'secret'    => env('MATCH2PAY_API_SECRET'),
            'endpoint'  => env('MATCH2PAY_ENDPOINT', 'deposit/crypto_agent'),
            'hash_algo' => 'sha384',
        ],

    ],

];
