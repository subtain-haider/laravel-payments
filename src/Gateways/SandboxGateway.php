<?php

namespace Subtain\LaravelPayments\Gateways;

use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\PaymentLogger;

/**
 * Sandbox gateway — simulates a payment without calling any real PSP API.
 *
 * Used automatically by PaymentService when:
 *   - sandbox.enabled = true and the target gateway is in sandbox.gateways, OR
 *   - the paying user's ID is in sandbox.bypass_user_ids, OR
 *   - the paying user's role is in sandbox.bypass_roles.
 *
 * The checkout() method returns a CheckoutResult immediately with:
 *   - a configurable redirect URL (sandbox.redirect_url)
 *   - a deterministic fake transaction_id: sandbox_{invoiceId}_{timestamp}
 *   - raw data flagged as simulated, carrying the original gateway name
 *
 * The parseWebhook() method is used by SandboxController to simulate a
 * confirmed payment when QA calls the sandbox confirm endpoint.
 *
 * This class is intentionally not registered in PaymentManager because it is
 * never selected by name — PaymentService injects it transparently.
 */
class SandboxGateway implements PaymentGateway
{
    /**
     * The original gateway name this sandbox is standing in for.
     * Stored so logs and DB records carry the real gateway name.
     */
    private string $originalGateway;

    public function __construct(string $originalGateway = 'sandbox')
    {
        $this->originalGateway = $originalGateway;
    }

    /**
     * Return the unique name of this gateway.
     */
    public function name(): string
    {
        return 'sandbox';
    }

    /**
     * Simulate a checkout session without calling any real PSP.
     *
     * Returns a CheckoutResult with a fake transaction ID and the configured
     * sandbox redirect URL. The raw payload is flagged with sandbox=true so
     * it is clearly identifiable in logs and DB records.
     */
    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        $transactionId = 'sandbox_' . $request->invoiceId . '_' . time();
        $redirectUrl   = config('lp_payments.sandbox.redirect_url', '/sandbox/payment-pending');

        PaymentLogger::info('checkout.initiated', [
            'invoice_id'       => $request->invoiceId,
            'amount'           => $request->amount,
            'currency'         => $request->currency,
            'sandbox'          => true,
            'original_gateway' => $this->originalGateway,
        ], gateway: $this->originalGateway, category: 'checkout');

        PaymentLogger::info('checkout.success', [
            'invoice_id'       => $request->invoiceId,
            'transaction_id'   => $transactionId,
            'redirect_url'     => $redirectUrl,
            'sandbox'          => true,
            'original_gateway' => $this->originalGateway,
        ], gateway: $this->originalGateway, category: 'checkout');

        return new CheckoutResult(
            redirectUrl:   $redirectUrl,
            transactionId: $transactionId,
            gateway:       $this->originalGateway,
            raw: [
                'sandbox'          => true,
                'simulated'        => true,
                'original_gateway' => $this->originalGateway,
                'invoice_id'       => $request->invoiceId,
                'amount'           => $request->amount,
                'currency'         => $request->currency,
                'transaction_id'   => $transactionId,
                'message'          => 'Payment simulated — no real charge was made.',
            ],
        );
    }

    /**
     * Parse a simulated webhook payload from the sandbox confirm endpoint.
     *
     * The SandboxController sends a synthetic payload; this method converts
     * it into a WebhookResult so it flows through the same event pipeline as
     * a real webhook confirmation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): WebhookResult
    {
        PaymentLogger::info('webhook.parsed', [
            'invoice_id'       => $payload['invoice_id'] ?? null,
            'transaction_id'   => $payload['transaction_id'] ?? null,
            'sandbox'          => true,
            'original_gateway' => $this->originalGateway,
        ], gateway: $this->originalGateway, category: 'webhook');

        return new WebhookResult(
            status:        PaymentStatus::PAID,
            transactionId: $payload['transaction_id'] ?? '',
            invoiceId:     $payload['invoice_id'] ?? '',
            gateway:       $this->originalGateway,
            raw:           array_merge($payload, ['sandbox' => true, 'simulated' => true]),
        );
    }

    /**
     * Sandbox webhooks do not require signature verification.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        return true;
    }
}
