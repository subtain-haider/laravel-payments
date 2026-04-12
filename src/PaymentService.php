<?php

namespace Subtain\LaravelPayments;

use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Facades\Payment;
use Subtain\LaravelPayments\Models\Payment as PaymentModel;
use Subtain\LaravelPayments\Models\PaymentLog;
use Illuminate\Database\Eloquent\Model;

/**
 * High-level payment orchestration.
 *
 * Wraps gateway calls with DB persistence so the consuming app
 * doesn't have to manage payment records manually.
 *
 * Usage:
 *   $service = app(PaymentService::class);
 *   $result  = $service->initiate('premiumpay', $checkoutRequest, $order);
 */
class PaymentService
{
    /**
     * Initiate a payment: create DB record → call gateway → update record → return result.
     *
     * @param  string           $gateway   Gateway name (fanbasis, premiumpay, match2pay, etc.)
     * @param  CheckoutRequest  $request   Gateway-agnostic checkout data
     * @param  Model|null       $payable   Optional Eloquent model this payment belongs to
     */
    public function initiate(string $gateway, CheckoutRequest $request, ?Model $payable = null): CheckoutResult
    {
        // 1. Create payment record
        $payment = new PaymentModel([
            'gateway'        => $gateway,
            'invoice_id'     => $request->invoiceId ?: $this->generateInvoiceId(),
            'amount'         => $request->amount,
            'currency'       => $request->currency,
            'status'         => PaymentStatus::PENDING,
            'customer_email' => $request->customerEmail,
            'customer_name'  => $request->customerName,
            'customer_ip'    => $request->customerIp,
            'success_url'    => $request->successUrl,
            'cancel_url'     => $request->cancelUrl,
            'webhook_url'    => $request->webhookUrl,
            'metadata'       => $request->metadata,
        ]);

        // Associate with payable model if provided
        if ($payable) {
            $payment->payable_type = get_class($payable);
            $payment->payable_id = $payable->getKey();
        }

        $payment->save();

        // If the checkout request didn't have an invoice ID, update it with the payment's one
        if (! $request->invoiceId) {
            $request = new CheckoutRequest(
                amount: $request->amount,
                currency: $request->currency,
                invoiceId: $payment->invoice_id,
                customerEmail: $request->customerEmail,
                customerName: $request->customerName,
                customerIp: $request->customerIp,
                productName: $request->productName,
                productDescription: $request->productDescription,
                successUrl: $request->successUrl,
                cancelUrl: $request->cancelUrl,
                webhookUrl: $request->webhookUrl,
                metadata: $request->metadata,
                extra: $request->extra,
            );
        }

        // 2. Log checkout initiation
        PaymentLog::logWebhook(
            paymentId: $payment->id,
            gateway: $gateway,
            event: 'checkout_initiated',
            payload: $request->toArray(),
        );

        // 3. Call gateway
        try {
            $result = Payment::gateway($gateway)->checkout($request);
        } catch (\Throwable $e) {
            $payment->update(['status' => PaymentStatus::FAILED]);

            PaymentLog::logWebhook(
                paymentId: $payment->id,
                gateway: $gateway,
                event: 'checkout_failed',
                payload: ['error' => $e->getMessage()],
                status: 'failed',
            );

            throw $e;
        }

        // 4. Update payment with gateway response
        $payment->update([
            'transaction_id'   => $result->transactionId,
            'redirect_url'     => $result->redirectUrl,
            'gateway_response' => $result->raw,
            'status'           => PaymentStatus::PROCESSING,
        ]);

        return $result;
    }

    /**
     * Find a payment by invoice ID and return the model.
     */
    public function findByInvoice(string $invoiceId): ?PaymentModel
    {
        return PaymentModel::findByInvoiceId($invoiceId);
    }

    /**
     * Find a payment by gateway transaction ID and return the model.
     */
    public function findByTransaction(string $transactionId): ?PaymentModel
    {
        return PaymentModel::findByTransactionId($transactionId);
    }

    /**
     * Generate a unique invoice ID.
     */
    protected function generateInvoiceId(): string
    {
        return 'pay_' . bin2hex(random_bytes(12));
    }
}
