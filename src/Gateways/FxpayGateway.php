<?php

namespace Subtain\LaravelPayments\Gateways;

use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Exceptions\PaymentException;
use Subtain\LaravelPayments\Gateways\Fxpay\FxpayClient;
use Subtain\LaravelPayments\Gateways\Fxpay\OrderService;
use Subtain\LaravelPayments\PaymentLogger;

/**
 * FxPay UPI payment gateway.
 *
 * Handles Indian UPI payments via the FxPay API.
 * Used as an alternative UPI provider alongside Rebornpay.
 *
 * ── Checkout flow ──────────────────────────────────────────────────
 * 1. checkout() calls POST /api/v1/orders with customer details,
 *    amount, a unique merchant_order_id (= invoiceId), and the
 *    callback_url (= webhookUrl) where FxPay will POST status updates.
 * 2. Returns a payment_url to redirect the customer to.
 * 3. FxPay processes the UPI payment and posts back to callback_url.
 *
 * ── Webhook flow ───────────────────────────────────────────────────
 * FxPay POSTs to your callback_url when a payment is approved or
 * rejected. The payload includes merchant_order_id for reconciliation
 * and a status field ("success" | "rejected").
 *
 * Signature: HMAC-SHA256 of the raw request body using your webhook
 * secret. Sent in the X-PG-Signature header (X-Webhook-Signature
 * is also accepted as a fallback).
 *
 * ── Config keys (config/lp_payments.php → gateways.fxpay) ────────
 *   base_url       — API base URL (default: https://fxpay.live)
 *   api_secret     — Bearer token for the Authorization header
 *   webhook_secret — HMAC-SHA256 signing key for webhook verification
 *   timeout        — HTTP timeout in seconds (default: 30)
 *   retries        — Retry count on 429/5xx (default: 2)
 *
 * @see https://fxpay.live — FxPay API docs
 */
class FxpayGateway implements PaymentGateway
{
    protected FxpayClient $client;
    protected string $webhookSecret;

    protected ?OrderService $orderService = null;

    public function __construct(array $config = [])
    {
        $this->client        = new FxpayClient($config);
        $this->webhookSecret = $config['webhook_secret'] ?? '';
    }

    public function name(): string
    {
        return 'fxpay';
    }

    // ── Checkout ─────────────────────────────────────────────────────

    /**
     * Create a FxPay order and return the payment URL.
     *
     * Required CheckoutRequest fields:
     *   - amount        — Payment amount
     *   - invoiceId     — Used as merchant_order_id for webhook reconciliation
     *   - webhookUrl    — FxPay will POST payment status updates here
     *
     * Optional fields:
     *   - customerName   — Included in the customer object
     *   - customerEmail  — Included in the customer object
     *   - productName    — Sent as the order note
     *
     * Optional extra fields:
     *   - customer_phone — Customer phone number (required by some FxPay configurations)
     *
     * @throws PaymentException
     */
    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        if ($this->client->getApiSecret() === '') {
            throw new PaymentException(
                message: 'FxPay api_secret is not configured.',
                gateway: $this->name(),
            );
        }

        $payload = [
            'customer'          => $this->buildCustomer($request),
            'amount'            => $request->amount,
            'merchant_order_id' => $request->invoiceId,
            'callback_url'      => $request->webhookUrl,
        ];

        if ($request->productName !== '') {
            $payload['note'] = $request->productName;
        }

        PaymentLogger::info('checkout.initiated', [
            'invoice_id'        => $request->invoiceId,
            'amount'            => $request->amount,
            'merchant_order_id' => $request->invoiceId,
            'customer_email'    => $request->customerEmail,
        ], gateway: 'fxpay', category: 'checkout');

        $data = $this->orders()->create($payload);

        $paymentUrl = (string) ($data['payment_url'] ?? '');

        if ($paymentUrl === '') {
            PaymentLogger::error('checkout.empty_url', [
                'invoice_id' => $request->invoiceId,
                'response'   => $data,
            ], gateway: 'fxpay', category: 'checkout');

            throw new PaymentException(
                message: 'FxPay response missing payment_url.',
                gateway: $this->name(),
                raw: $data,
            );
        }

        PaymentLogger::info('checkout.success', [
            'invoice_id' => $request->invoiceId,
            'order_id'   => $data['order_id'] ?? '',
        ], gateway: 'fxpay', category: 'checkout');

        return new CheckoutResult(
            redirectUrl:   $paymentUrl,
            transactionId: (string) ($data['order_id'] ?? ''),
            gateway:       $this->name(),
            raw:           $data,
        );
    }

    // ── Webhooks ──────────────────────────────────────────────────────

    /**
     * Parse a FxPay webhook payload into a standardized WebhookResult.
     *
     * FxPay webhook structure (approved):
     * {
     *   "success": true,
     *   "data": {
     *     "order_id": "ORD123456789",
     *     "merchant_order_id": "INV-10001",
     *     "amount": 1500.50,
     *     "note": "...",
     *     "status": "success"
     *   }
     * }
     *
     * FxPay webhook structure (rejected):
     * {
     *   "success": false,
     *   "data": {
     *     "order_id": "ORD123456789",
     *     "merchant_order_id": "INV-10001",
     *     "amount": 1500.50,
     *     "note": "...",
     *     "status": "rejected"
     *   }
     * }
     *
     * Status mapping:
     *   data.status = "success"  → PaymentStatus::PAID
     *   data.status = "rejected" → PaymentStatus::FAILED
     *   anything else            → PaymentStatus::PENDING
     */
    public function parseWebhook(array $payload): WebhookResult
    {
        $data             = $payload['data'] ?? [];
        $fxpayStatus      = (string) ($data['status'] ?? '');
        $merchantOrderId  = (string) ($data['merchant_order_id'] ?? '');
        $orderId          = (string) ($data['order_id'] ?? '');
        $amount           = (float) ($data['amount'] ?? 0);

        $status = match ($fxpayStatus) {
            'success'  => PaymentStatus::PAID,
            'rejected' => PaymentStatus::FAILED,
            default    => PaymentStatus::PENDING,
        };

        PaymentLogger::info('webhook.parsed', [
            'order_id'          => $orderId,
            'merchant_order_id' => $merchantOrderId,
            'fxpay_status'      => $fxpayStatus,
            'amount'            => $amount,
            'status'            => $status->value,
        ], gateway: 'fxpay', category: 'webhook');

        return new WebhookResult(
            status:        $status,
            invoiceId:     $merchantOrderId,
            transactionId: $orderId,
            gateway:       $this->name(),
            amount:        $amount,
            currency:      'INR',
            metadata:      [
                'order_id'          => $orderId,
                'merchant_order_id' => $merchantOrderId,
                'fxpay_status'      => $fxpayStatus,
                'note'              => $data['note'] ?? null,
            ],
            raw: $payload,
        );
    }

    /**
     * Verify the FxPay webhook signature using the raw request body.
     *
     * FxPay signs webhooks with HMAC-SHA256 of the raw JSON body using
     * the webhook_secret. The signature is sent in the X-PG-Signature
     * header (X-Webhook-Signature is accepted as a fallback).
     *
     * NOTE: The WebhookController calls this method (over verifyWebhook)
     * because verifyWebhookSignature() is detected via method_exists().
     * Always use the raw body — never re-serialise the parsed payload.
     *
     * @param  string  $rawBody  Raw JSON body from $request->getContent()
     */
    public function verifyWebhookSignature(string $rawBody, array $headers = []): bool
    {
        if (empty($this->webhookSecret)) {
            PaymentLogger::warning('webhook.verification_skipped', [
                'reason' => 'webhook_secret not configured',
            ], gateway: 'fxpay', category: 'webhook');

            return true;
        }

        $receivedSignature = $headers['x-pg-signature'][0]
            ?? $headers['x-webhook-signature'][0]
            ?? null;

        if ($receivedSignature === null || $receivedSignature === '') {
            PaymentLogger::warning('webhook.signature_missing', [
                'available_headers' => array_keys($headers),
            ], gateway: 'fxpay', category: 'webhook');

            return false;
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        $valid             = hash_equals($expectedSignature, $receivedSignature);

        if (! $valid) {
            PaymentLogger::warning('webhook.signature_failed', [
                'expected_prefix' => substr($expectedSignature, 0, 8) . '...',
            ], gateway: 'fxpay', category: 'webhook');
        }

        return $valid;
    }

    /**
     * Fallback webhook verification using parsed payload.
     *
     * This method satisfies the PaymentGateway contract. The WebhookController
     * prefers verifyWebhookSignature() (raw body) when it exists — which it does
     * on this class — so this fallback is only called when used directly
     * outside the standard webhook flow.
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        // Without the raw body we cannot reliably compute the HMAC.
        // Indicate unconfigured state gracefully rather than always passing.
        if (empty($this->webhookSecret)) {
            PaymentLogger::warning('webhook.verification_skipped', [
                'reason' => 'webhook_secret not configured',
            ], gateway: 'fxpay', category: 'webhook');

            return true;
        }

        PaymentLogger::warning('webhook.raw_body_preferred', [
            'reason' => 'verifyWebhook() called without raw body — use verifyWebhookSignature() for accurate HMAC verification',
        ], gateway: 'fxpay', category: 'webhook');

        return false;
    }

    // ── Service Accessors ─────────────────────────────────────────────

    /**
     * Access the Order service for direct API calls.
     */
    public function orders(): OrderService
    {
        return $this->orderService ??= new OrderService($this->client);
    }

    /**
     * Access the underlying HTTP client.
     */
    public function client(): FxpayClient
    {
        return $this->client;
    }

    // ── Internal Helpers ──────────────────────────────────────────────

    /**
     * Build the FxPay customer object from the checkout request.
     *
     * FxPay requires customer.name, customer.email, and customer.phone.
     * Phone is optional from the CheckoutRequest perspective — pass it
     * via extra['customer_phone'] if available.
     *
     * @return array{name: string, email: string, phone?: string}
     */
    protected function buildCustomer(CheckoutRequest $request): array
    {
        $customer = [
            'name'  => $request->customerName  !== '' ? $request->customerName  : 'Customer',
            'email' => $request->customerEmail !== '' ? $request->customerEmail : '',
        ];

        $phone = $request->extra['customer_phone'] ?? '';
        if ($phone !== '') {
            $customer['phone'] = (string) $phone;
        }

        return $customer;
    }
}
