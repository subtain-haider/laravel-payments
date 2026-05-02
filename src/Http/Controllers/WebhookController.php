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
use Subtain\LaravelPayments\Models\DiscountCodeUsage;
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

        // Signature verification — use raw body when the gateway supports it (Fanbasis HMAC-SHA256).
        // Per Fanbasis docs: "Never re-serialize the parsed JSON to generate or compare signatures"
        $signatureValid = method_exists($driver, 'verifyWebhookSignature')
            ? $driver->verifyWebhookSignature($request->getContent(), $headers)
            : $driver->verifyWebhook($payload, $headers);

        if (! $signatureValid) {
            PaymentLog::logWebhook(
                paymentId: null,
                gateway: $gateway,
                event: 'webhook_signature_failed',
                payload: $payload,
                headers: $headers,
                status: 'rejected',
            );

            return response()->json(['error' => 'Invalid webhook signature'], 401);
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

        // Update payment status (with guard rails) and record received_amount
        if ($payment) {
            $this->updatePaymentStatus($payment, $result->status, $result->transactionId);

            // Always persist the gateway-confirmed amount on the payment record for auditability.
            // For crypto gateways this is finalAmount in the account currency (e.g. USD after conversion).
            // For fixed-amount gateways it equals the original charged amount.
            if ($result->isSuccessful() && $result->amount > 0) {
                $payment->received_amount = $result->amount;
                $payment->save();
            }
        }

        // Always dispatch the generic event (includes payment model if found)
        WebhookReceived::dispatch($result, $payment);

        // Dispatch status-specific events
        if ($result->isSuccessful()) {
            // Underpayment guard — only for gateways that declare an underpayment_tolerance.
            // Crypto gateways (Match2Pay) send whatever the blockchain confirmed, which may be
            // slightly below the order amount due to FX conversion. A configured tolerance
            // allows small shortfalls while blocking genuine underpayments.
            if ($payment && ! $this->passesUnderpaymentCheck($gateway, $payment, $result->amount)) {
                PaymentLog::logWebhook(
                    paymentId: $payment->id,
                    gateway:   $gateway,
                    event:     'underpayment_detected',
                    payload:   $payload,
                    headers:   $headers,
                    status:    'rejected',
                );

                return response()->json(['status' => 'ok']);
            }

            PaymentSucceeded::dispatch($result, $payment);

            // Auto-record discount usage when enabled in config.
            // This runs only when: payment has a discount_code_id, the feature is
            // enabled via config('lp_payments.auto_record_discount_usage'), and no
            // usage record has been written yet for this payment (idempotency guard).
            if ($payment && $payment->discount_code_id && config('lp_payments.auto_record_discount_usage', false)) {
                $this->autoRecordDiscountUsage($payment);
            }
        } elseif ($result->isFailed()) {
            PaymentFailed::dispatch($result, $payment);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Auto-record discount code usage after a confirmed payment.
     *
     * Idempotency: checks whether a DiscountCodeUsage row already exists for this
     * payment's payable before writing — safe against duplicate webhook calls.
     *
     * Only runs when config('lp_payments.auto_record_discount_usage') is true AND
     * the payment has a discount_code_id stored (set by PaymentService::initiate()).
     */
    protected function autoRecordDiscountUsage(PaymentModel $payment): void
    {
        // Idempotency guard — if a usage record already exists for this payable + discount, skip.
        $alreadyRecorded = DiscountCodeUsage::where('discount_code_id', $payment->discount_code_id)
            ->when($payment->payable_type && $payment->payable_id, function ($q) use ($payment) {
                $q->where('payable_type', $payment->payable_type)
                  ->where('payable_id', $payment->payable_id);
            })
            ->when(! $payment->payable_type, function ($q) use ($payment) {
                $q->where('user_id', $payment->user_id);
            })
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $discountCode = $payment->discountCode;

        if (! $discountCode) {
            return;
        }

        $discountCode->incrementUsage();

        $usage = new DiscountCodeUsage([
            'discount_code_id' => $discountCode->id,
            'user_id'          => $payment->user_id,
            'original_amount'  => $payment->amount + $payment->discount_amount,
            'discount_amount'  => $payment->discount_amount,
            'final_amount'     => $payment->amount,
        ]);

        if ($payment->payable_type && $payment->payable_id) {
            $usage->payable_type = $payment->payable_type;
            $usage->payable_id   = $payment->payable_id;
        }

        $usage->save();
    }

    /**
     * Check whether the amount received from the gateway meets the expected order amount.
     *
     * Only applies when the gateway config declares an 'underpayment_tolerance'.
     * A tolerance of 0.02 means we accept up to 2% below the order amount — this
     * covers crypto-to-fiat FX rounding on gateways like Match2Pay.
     *
     * Gateways without 'underpayment_tolerance' in config always pass this check.
     *
     * Returns true  → safe to fire PaymentSucceeded
     * Returns false → underpayment detected, block PaymentSucceeded
     */
    protected function passesUnderpaymentCheck(string $gateway, PaymentModel $payment, float $receivedAmount): bool
    {
        $tolerance = config("lp_payments.gateways.{$gateway}.underpayment_tolerance");

        if ($tolerance === null) {
            return true;
        }

        $requiredAmount = (float) $payment->amount * (1 - (float) $tolerance);

        return $receivedAmount >= $requiredAmount;
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
