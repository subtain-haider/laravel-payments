<?php

namespace Subtain\LaravelPayments;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Facades\Payment;
use Subtain\LaravelPayments\Gateways\SandboxGateway;
use Subtain\LaravelPayments\KeyFingerprint;
use Subtain\LaravelPayments\Models\Payment as PaymentModel;
use Subtain\LaravelPayments\Models\PaymentLog;
use Subtain\LaravelPayments\PaymentLogger;

/**
 * High-level payment orchestration.
 *
 * Wraps gateway calls with DB persistence so the consuming app
 * doesn't have to manage payment records manually.
 *
 * Sandbox support is built in. When the SandboxResolver determines a payment
 * should be sandboxed (via environment config or per-user/role bypass), the
 * real gateway is replaced with SandboxGateway transparently. All DB records,
 * logs, and events fire identically — only the real API call is skipped.
 * Sandboxed records are flagged with is_sandbox = true.
 *
 * Usage:
 *   $service = app(PaymentService::class);
 *   $result  = $service->initiate('premiumpay', $checkoutRequest, $order);
 *
 *   // With authenticated user for per-user/role sandbox bypass:
 *   $result = $service->initiate('premiumpay', $checkoutRequest, $order, $user);
 */
class PaymentService
{
    public function __construct(private readonly SandboxResolver $sandboxResolver) {}

    /**
     * Initiate a payment: create DB record → call gateway → update record → return result.
     *
     * @param  string                   $gateway   Gateway name (fanbasis, premiumpay, match2pay, etc.)
     * @param  CheckoutRequest          $request   Gateway-agnostic checkout data
     * @param  Model|null               $payable   Optional Eloquent model this payment belongs to
     * @param  Authenticatable|null     $user      Authenticated user, used for per-user/role sandbox bypass
     */
    public function initiate(
        string $gateway,
        CheckoutRequest $request,
        ?Model $payable = null,
        ?Authenticatable $user = null,
    ): CheckoutResult {
        $isSandbox = $this->sandboxResolver->shouldSandbox($gateway, $user);

        // Compute the primary key fingerprint at the moment of payment initiation.
        // This creates a permanent, auditable record of which API key version was
        // active — essential for diagnosing issues after a key rotation.
        $keyFingerprint = KeyFingerprint::primaryForGateway($gateway);

        if ($keyFingerprint !== null) {
            PaymentLogger::debug('key.fingerprinted', [
                'invoice_id'      => $request->invoiceId ?: '(pending)',
                'key_fingerprint' => $keyFingerprint,
            ], gateway: $gateway, category: 'checkout');
        } else {
            PaymentLogger::warning('key.not_configured', [
                'invoice_id' => $request->invoiceId ?: '(pending)',
                'reason'     => 'No API key found in gateway config — key fingerprint will be null on this payment record',
            ], gateway: $gateway, category: 'checkout');
        }

        // 1. Create payment record (flagged if sandboxed)
        $payment = new PaymentModel([
            'gateway'         => $gateway,
            'invoice_id'      => $request->invoiceId ?: $this->generateInvoiceId(),
            'amount'          => $request->amount,
            'currency'        => $request->currency,
            'status'          => PaymentStatus::PENDING,
            'customer_email'  => $request->customerEmail,
            'customer_name'   => $request->customerName,
            'customer_ip'     => $request->customerIp,
            'success_url'     => $request->successUrl,
            'cancel_url'      => $request->cancelUrl,
            'webhook_url'     => $request->webhookUrl,
            'metadata'        => $request->metadata,
            'is_sandbox'      => $isSandbox,
            'key_fingerprint' => $keyFingerprint,
        ]);

        // Associate with payable model if provided
        if ($payable) {
            $payment->payable_type = get_class($payable);
            $payment->payable_id   = $payable->getKey();
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

        // 2. Log checkout initiation (flagged if sandboxed)
        PaymentLog::logWebhook(
            paymentId: $payment->id,
            gateway: $gateway,
            event: 'checkout_initiated',
            payload: $request->toArray(),
            isSandbox: $isSandbox,
        );

        // 3. Call gateway (real or sandbox)
        if ($isSandbox) {
            PaymentLogger::info('sandbox.intercepted', [
                'invoice_id' => $payment->invoice_id,
                'gateway'    => $gateway,
                'sandbox'    => true,
                'reason'     => 'Payment intercepted by sandbox mode — real gateway will not be called',
            ], gateway: $gateway, category: 'checkout');
        }

        $gatewayDriver = $isSandbox
            ? new SandboxGateway(originalGateway: $gateway)
            : Payment::gateway($gateway);

        try {
            $result = $gatewayDriver->checkout($request);
        } catch (\Throwable $e) {
            $payment->update(['status' => PaymentStatus::FAILED]);

            PaymentLog::logWebhook(
                paymentId: $payment->id,
                gateway: $gateway,
                event: 'checkout_failed',
                payload: ['error' => $e->getMessage()],
                status: 'failed',
                isSandbox: $isSandbox,
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
