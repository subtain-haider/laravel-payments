<?php

namespace Subtain\LaravelPayments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Events\PaymentFailed;
use Subtain\LaravelPayments\Events\PaymentSucceeded;
use Subtain\LaravelPayments\Events\WebhookReceived;
use Subtain\LaravelPayments\Facades\Payment;

/**
 * Generic webhook receiver for all payment gateways.
 *
 * Route: POST /payments/webhook/{gateway}
 *
 * This controller:
 * 1. Resolves the gateway by name
 * 2. Verifies the webhook signature
 * 3. Parses the payload into a WebhookResult
 * 4. Dispatches the appropriate event
 *
 * Your application listens to these events to trigger business logic.
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway): JsonResponse
    {
        $driver = Payment::gateway($gateway);

        $payload = $request->all();
        $headers = $request->headers->all();

        // Signature verification
        if (! $driver->verifyWebhook($payload, $headers)) {
            return response()->json(['error' => 'Invalid webhook signature'], 403);
        }

        // Parse into standardized result
        $result = $driver->parseWebhook($payload);

        // Always dispatch the generic event
        WebhookReceived::dispatch($result);

        // Dispatch status-specific events
        if ($result->isSuccessful()) {
            PaymentSucceeded::dispatch($result);
        } elseif ($result->isFailed()) {
            PaymentFailed::dispatch($result);
        }

        return response()->json(['status' => 'ok']);
    }
}
