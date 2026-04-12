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
use Subtain\LaravelPayments\Models\Payment as PaymentModel;
use Subtain\LaravelPayments\Models\PaymentLog;

/**
 * Generic webhook receiver for all payment gateways.
 *
 * Route: POST /payments/webhook/{gateway}
 *
 * This controller:
 * 1. Resolves the gateway by name
 * 2. Verifies the webhook signature
 * 3. Parses the payload into a WebhookResult
 * 4. Finds and updates the Payment record (if exists)
 * 5. Logs the webhook payload
 * 6. Dispatches the appropriate event
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
            PaymentLog::logWebhook(
                paymentId: null,
                gateway: $gateway,
                event: 'webhook_signature_failed',
                payload: $payload,
                headers: $headers,
                status: 'rejected',
            );

            return response()->json(['error' => 'Invalid webhook signature'], 403);
        }

        // Parse into standardized result
        $result = $driver->parseWebhook($payload);

        // Find the payment record (by invoice ID or transaction ID)
        $payment = null;
        if ($result->invoiceId) {
            $payment = PaymentModel::findByInvoiceId($result->invoiceId);
        }
        if (! $payment && $result->transactionId) {
            $payment = PaymentModel::findByTransactionId($result->transactionId);
        }

        // Log the webhook
        PaymentLog::logWebhook(
            paymentId: $payment?->id,
            gateway: $gateway,
            event: 'webhook_received',
            payload: $payload,
            headers: $headers,
            status: $result->status->value,
        );

        // Update payment status (with guard rails)
        if ($payment) {
            $this->updatePaymentStatus($payment, $result->status, $result->transactionId);
        }

        // Always dispatch the generic event (includes payment model if found)
        WebhookReceived::dispatch($result, $payment);

        // Dispatch status-specific events
        if ($result->isSuccessful()) {
            PaymentSucceeded::dispatch($result, $payment);
        } elseif ($result->isFailed()) {
            PaymentFailed::dispatch($result, $payment);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Update the payment record status, respecting the state machine.
     */
    protected function updatePaymentStatus(PaymentModel $payment, PaymentStatus $newStatus, ?string $transactionId): void
    {
        // Idempotency: if already in this status, skip
        if ($payment->status === $newStatus) {
            return;
        }

        try {
            if ($newStatus === PaymentStatus::PAID) {
                $payment->markAsPaid($transactionId);
            } elseif ($newStatus === PaymentStatus::FAILED) {
                $payment->markAsFailed();
            } elseif ($newStatus === PaymentStatus::REFUNDED) {
                $payment->markAsRefunded();
            } else {
                $payment->transitionTo($newStatus);
            }
        } catch (\LogicException $e) {
            // Invalid transition — log it but don't break the webhook response
            PaymentLog::logWebhook(
                paymentId: $payment->id,
                gateway: $payment->gateway,
                event: 'invalid_status_transition',
                payload: [
                    'from'  => $payment->status->value,
                    'to'    => $newStatus->value,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }
}
