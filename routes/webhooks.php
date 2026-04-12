<?php

use Illuminate\Support\Facades\Route;
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
| The route prefix and middleware are configurable in config/payments.php.
|
*/

Route::post(
    config('payments.webhook_path', 'payments/webhook') . '/{gateway}',
    [WebhookController::class, 'handle']
)->name('payments.webhook');
