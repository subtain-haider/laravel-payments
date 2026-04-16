<?php

namespace Subtain\LaravelPayments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\PaymentLogger;
use Subtain\LaravelPayments\Events\PaymentSucceeded;
use Subtain\LaravelPayments\Events\WebhookReceived;
use Subtain\LaravelPayments\Gateways\SandboxGateway;
use Subtain\LaravelPayments\Models\Payment as PaymentModel;
use Subtain\LaravelPayments\Models\PaymentLog;

/**
 * Simulates a payment confirmation for sandbox (simulated) payments.
 *
 * Route: GET /payments/webhook/sandbox/confirm/{invoice_id}
 *
 * Since sandboxed payments never receive a real webhook from a PSP, QA testers
 * need a way to simulate "payment confirmed" so they can verify the full
 * downstream flow (account creation, emails, affiliate tracking, etc.).
 *
 * This controller:
 * 1. Looks up the payment by invoice ID
 * 2. Hard-rejects if the payment record does not have is_sandbox = true
 *    (prevents confirming real payments even if sandbox mode is currently enabled)
 * 3. Builds a synthetic WebhookResult via SandboxGateway::parseWebhook()
 * 4. Updates the payment status to 'paid' (via the state machine)
 * 5. Logs the simulated confirmation exactly like a real webhook log
 * 6. Dispatches PaymentSucceeded and WebhookReceived events
 *
 * This endpoint is only active when:
 *   - config('lp_payments.sandbox.enabled') = true, OR
 *   - the app environment is 'local' or 'testing'
 *
 * It must NEVER be active on production with sandbox disabled.
 *
 * Usage (browser or curl):
 *   GET /payments/webhook/sandbox/confirm/pay_abc123
 */
class SandboxController extends Controller
{
    /**
     * Simulate a successful payment confirmation for a sandboxed payment.
     *
     * Only works on payments where is_sandbox = true on the DB record itself.
     * This is checked against the record — not against current config — so a
     * real payment created before sandbox was enabled can never be confirmed here.
     *
     * @param  string  $invoiceId  The invoice_id of the sandboxed lp_payments record.
     */
    public function confirm(string $invoiceId): JsonResponse
    {
        $payment = PaymentModel::findByInvoiceId($invoiceId);

        if (! $payment) {
            PaymentLogger::warning('sandbox.confirm_not_found', [
                'invoice_id' => $invoiceId,
                'sandbox'    => true,
            ], gateway: 'sandbox', category: 'webhook');

            return response()->json(['error' => 'Payment not found.'], 404);
        }

        // Critical guard: reject even if sandbox mode is currently enabled.
        // The is_sandbox flag on the DB record is the only source of truth.
        // A real payment (is_sandbox = false) created before sandbox was enabled
        // can NEVER be confirmed through this endpoint.
        if (! $payment->is_sandbox) {
            PaymentLogger::error('sandbox.confirm_rejected_real_payment', [
                'invoice_id' => $invoiceId,
                'gateway'    => $payment->gateway,
                'sandbox'    => false,
                'reason'     => 'Payment was created as a real payment (is_sandbox = false)',
            ], gateway: $payment->gateway, category: 'webhook');

            return response()->json([
                'error'      => 'Forbidden. This payment was created as a real payment (is_sandbox = false).',
                'invoice_id' => $invoiceId,
                'hint'       => 'Only payments originally initiated in sandbox mode can be confirmed here.',
            ], 403);
        }

        // Build a synthetic webhook payload that SandboxGateway can parse
        $syntheticPayload = [
            'invoice_id'     => $payment->invoice_id,
            'transaction_id' => $payment->transaction_id ?? 'sandbox_confirm_' . time(),
            'gateway'        => $payment->gateway,
            'amount'         => $payment->amount,
            'currency'       => $payment->currency,
            'confirmed_by'   => 'sandbox_confirm_endpoint',
        ];

        $gateway = new SandboxGateway(originalGateway: $payment->gateway);
        $result  = $gateway->parseWebhook($syntheticPayload);

        // Log the simulated confirmation — flagged as sandbox
        PaymentLog::logWebhook(
            paymentId: $payment->id,
            gateway: $payment->gateway,
            event: 'sandbox_confirm',
            payload: $syntheticPayload,
            headers: [],
            status: PaymentStatus::PAID->value,
            isSandbox: true,
        );

        // Transition payment to paid (respects state machine, handles idempotency)
        if ($payment->status !== PaymentStatus::PAID) {
            try {
                $payment->markAsPaid($result->transactionId);
            } catch (\LogicException $e) {
                PaymentLogger::error('sandbox.confirm_invalid_transition', [
                    'invoice_id' => $invoiceId,
                    'from'       => $payment->status->value,
                    'to'         => PaymentStatus::PAID->value,
                    'error'      => $e->getMessage(),
                    'sandbox'    => true,
                ], gateway: $payment->gateway, category: 'webhook');

                PaymentLog::logWebhook(
                    paymentId: $payment->id,
                    gateway: $payment->gateway,
                    event: 'invalid_status_transition',
                    payload: [
                        'from'  => $payment->status->value,
                        'to'    => PaymentStatus::PAID->value,
                        'error' => $e->getMessage(),
                    ],
                    isSandbox: true,
                );

                return response()->json(['error' => 'Cannot transition payment to paid: ' . $e->getMessage()], 422);
            }
        }

        PaymentLogger::info('sandbox.confirmed', [
            'invoice_id'     => $invoiceId,
            'transaction_id' => $result->transactionId,
            'gateway'        => $payment->gateway,
            'sandbox'        => true,
            'events_fired'   => ['WebhookReceived', 'PaymentSucceeded'],
        ], gateway: $payment->gateway, category: 'webhook');

        // Fire events — identical to a real webhook confirmation
        WebhookReceived::dispatch($result, $payment);
        PaymentSucceeded::dispatch($result, $payment);

        return response()->json([
            'status'     => 'ok',
            'sandbox'    => true,
            'invoice_id' => $invoiceId,
            'message'    => 'Sandbox payment confirmed. PaymentSucceeded event dispatched.',
        ]);
    }
}
