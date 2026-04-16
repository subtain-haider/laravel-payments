<?php

use Illuminate\Support\Facades\Route;
use Subtain\LaravelPayments\Http\Controllers\SandboxController;
use Subtain\LaravelPayments\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Payment Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle incoming webhooks from payment gateways.
| The {gateway} parameter is used to resolve the correct driver.
|
| Example: POST /payments/webhook/fanbasis
|          POST /payments/webhook/premiumpay
|          POST /payments/webhook/match2pay
|
| The route prefix and middleware are configurable in config/lp_payments.php.
|
*/

Route::post(
    config('lp_payments.webhook_path', 'payments/webhook') . '/{gateway}',
    [WebhookController::class, 'handle']
)->name('payments.webhook');

/*
|--------------------------------------------------------------------------
| Sandbox Payment Confirm Route
|--------------------------------------------------------------------------
|
| Simulates a successful webhook confirmation for sandbox payments.
| Allows QA testers to trigger the full downstream flow (events, listeners,
| account creation, etc.) without making a real payment.
|
| Route: GET /payments/webhook/sandbox/confirm/{invoice_id}
|
| Only active when sandbox.enabled = true OR app environment is local/testing.
| This must NEVER be accessible on production with sandbox mode disabled.
|
*/

$sandboxEnabled = config('lp_payments.sandbox.enabled', false);
$isSafeEnv      = in_array(app()->environment(), ['local', 'testing'], true);

if ($sandboxEnabled || $isSafeEnv) {
    Route::get(
        config('lp_payments.webhook_path', 'payments/webhook') . '/sandbox/confirm/{invoiceId}',
        [SandboxController::class, 'confirm']
    )->name('payments.sandbox.confirm');
}
