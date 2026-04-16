<?php

namespace Subtain\LaravelPayments\Gateways;

use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Gateways\Fanbasis\CheckoutSessionsService;
use Subtain\LaravelPayments\Gateways\Fanbasis\CustomersService;
use Subtain\LaravelPayments\Gateways\Fanbasis\DiscountCodesService;
use Subtain\LaravelPayments\Gateways\Fanbasis\FanbasisClient;
use Subtain\LaravelPayments\Gateways\Fanbasis\ProductsService;
use Subtain\LaravelPayments\Gateways\Fanbasis\RefundsService;
use Subtain\LaravelPayments\Gateways\Fanbasis\SubscribersService;
use Subtain\LaravelPayments\Gateways\Fanbasis\TransactionsService;
use Subtain\LaravelPayments\Gateways\Fanbasis\WebhooksService;

/**
 * Fanbasis payment gateway — full API integration.
 *
 * Checkout modes:
 *   1. Dynamic   — creates a checkout session via API (one-time or subscription)
 *   2. Embedded  — creates an embedded checkout for iframes (extra['embedded'] = true)
 *   3. Static    — redirects to a pre-built payment link (extra['payment_link'])
 *
 * Config (config/lp_payments.php → gateways.fanbasis):
 *   base_url, api_key, webhook_secret, creator_handle, timeout, retries
 *
 * @see https://apidocs.fan
 */
class FanbasisGateway implements PaymentGateway
{
    protected FanbasisClient $client;
    protected string $webhookSecret;
    protected string $creatorHandle;

    protected ?CheckoutSessionsService $checkoutSessionsService = null;
    protected ?CustomersService $customersService = null;
    protected ?SubscribersService $subscribersService = null;
    protected ?DiscountCodesService $discountCodesService = null;
    protected ?ProductsService $productsService = null;
    protected ?TransactionsService $transactionsService = null;
    protected ?RefundsService $refundsService = null;
    protected ?WebhooksService $webhooksService = null;

    public function __construct(array $config = [])
    {
        $this->client = new FanbasisClient($config);
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        $this->creatorHandle = $config['creator_handle'] ?? '';
    }

    public function name(): string
    {
        return 'fanbasis';
    }

    // ── Checkout ────────────────────────────────────────────

    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        if (! empty($request->extra['payment_link'])) {
            return $this->staticCheckout($request, $request->extra['payment_link']);
        }

        if (! empty($request->extra['embedded'])) {
            return $this->embeddedCheckout($request);
        }

        return $this->dynamicCheckout($request);
    }

    // ── Webhooks ────────────────────────────────────────────

    /**
     * Parse a Fanbasis webhook payload into a standardized WebhookResult.
     *
     * Fanbasis has two payload formats:
     * 1. Payment/subscription events — flat payload with payment_id, amount, status, api_metadata
     * 2. Dispute/refund events — envelope format with top-level { id, type, data: {...}, created_at }
     *
     * The event type comes from:
     * - Envelope payloads: $payload['type'] (e.g. "dispute.created", "refund.created")
     * - Flat payloads: $payload['event_type'] if present, otherwise inferred from $payload['status']
     */
    public function parseWebhook(array $payload): WebhookResult
    {
        // Envelope format (dispute.*, refund.*) — unwrap the data
        if (isset($payload['type']) && isset($payload['data']) && is_array($payload['data'])) {
            return $this->parseEnvelopeWebhook($payload);
        }

        // Flat format (payment.*, product.*, subscription.*)
        return $this->parseFlatWebhook($payload);
    }

    /**
     * Verify webhook signature using HMAC-SHA256.
     *
     * IMPORTANT: For accurate verification, pass the raw request body to verifyWebhookSignature()
     * instead. This method receives parsed JSON (re-serialized), which may differ from the
     * original body due to key reordering. It works as a fallback but the raw-body method
     * is more reliable.
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        if (empty($this->webhookSecret)) {
            return true;
        }

        $signature = $this->extractHeader($headers, 'x-webhook-signature')
            ?? $this->extractHeader($headers, 'x-fanbasis-signature')
            ?? '';

        if ($signature === '') {
            return false;
        }

        return WebhooksService::verifySignature(
            rawBody: json_encode($payload),
            signature: $signature,
            secret: $this->webhookSecret,
        );
    }

    /**
     * Verify webhook using the raw request body (recommended).
     *
     * Use this in your controller: $gateway->verifyWebhookSignature($request->getContent(), $request->headers->all())
     */
    public function verifyWebhookSignature(string $rawBody, array $headers = []): bool
    {
        if (empty($this->webhookSecret)) {
            return true;
        }

        $signature = $this->extractHeader($headers, 'x-webhook-signature')
            ?? $this->extractHeader($headers, 'x-fanbasis-signature')
            ?? '';

        if ($signature === '') {
            return false;
        }

        return WebhooksService::verifySignature($rawBody, $signature, $this->webhookSecret);
    }

    // ── Checkout Modes ──────────────────────────────────────

    protected function dynamicCheckout(CheckoutRequest $request): CheckoutResult
    {
        $payload = [
            'product' => array_filter([
                'title'       => $request->productName,
                'description' => $request->productDescription,
            ]),
            'amount_cents' => (int) round($request->amount * 100),
            'type'         => $request->extra['type'] ?? 'onetime_non_reusable',
            'metadata'     => $request->metadata,
            'success_url'  => $request->successUrl,
            'webhook_url'  => $request->webhookUrl,
        ];

        if (isset($request->extra['application_fee'])) {
            $payload['application_fee'] = (int) $request->extra['application_fee'];
        }

        if (! empty($request->extra['expiration_date'])) {
            $payload['expiration_date'] = $request->extra['expiration_date'];
        }

        if (! empty($request->extra['subscription'])) {
            $payload['type'] = 'subscription';
            $payload['subscription'] = $request->extra['subscription'];
        }

        if (! empty($request->extra['discount_code'])) {
            $payload['discount_code'] = $request->extra['discount_code'];
        }

        if (isset($request->extra['allow_discount_codes'])) {
            $payload['allow_discount_codes'] = (bool) $request->extra['allow_discount_codes'];
        }

        $data = $this->client->post('checkout-sessions', $payload);

        return new CheckoutResult(
            redirectUrl: $data['data']['payment_link'] ?? '',
            transactionId: (string) ($data['data']['checkout_session_id'] ?? ''),
            gateway: $this->name(),
            raw: $data,
        );
    }

    protected function embeddedCheckout(CheckoutRequest $request): CheckoutResult
    {
        $productId = $request->extra['product_id']
            ?? throw new \InvalidArgumentException('Embedded checkout requires extra[product_id].');

        $data = $this->client->post('checkout-sessions/embedded', [
            'product_id' => $productId,
            'metadata'   => $request->metadata,
        ]);

        $secret = $data['data']['checkout_session_secret'] ?? '';

        return new CheckoutResult(
            redirectUrl: $this->creatorHandle
                ? "https://embedded.fanbasis.io/session/{$this->creatorHandle}/{$productId}/{$secret}"
                : '',
            transactionId: $secret,
            gateway: $this->name(),
            raw: $data,
        );
    }

    protected function staticCheckout(CheckoutRequest $request, string $paymentLink): CheckoutResult
    {
        $params = array_filter($request->extra['query_params'] ?? $request->metadata);

        $url = $paymentLink;
        if (! empty($params)) {
            $url .= (str_contains($paymentLink, '?') ? '&' : '?') . http_build_query($params);
        }

        return new CheckoutResult(
            redirectUrl: $url,
            transactionId: '',
            gateway: $this->name(),
            raw: ['payment_link' => $url],
        );
    }

    // ── Service Accessors ───────────────────────────────────

    public function checkoutSessions(): CheckoutSessionsService
    {
        return $this->checkoutSessionsService ??= new CheckoutSessionsService($this->client);
    }

    public function customers(): CustomersService
    {
        return $this->customersService ??= new CustomersService($this->client);
    }

    public function subscribers(): SubscribersService
    {
        return $this->subscribersService ??= new SubscribersService($this->client);
    }

    public function discountCodes(): DiscountCodesService
    {
        return $this->discountCodesService ??= new DiscountCodesService($this->client);
    }

    public function products(): ProductsService
    {
        return $this->productsService ??= new ProductsService($this->client);
    }

    public function transactions(): TransactionsService
    {
        return $this->transactionsService ??= new TransactionsService($this->client);
    }

    public function refunds(): RefundsService
    {
        return $this->refundsService ??= new RefundsService($this->client);
    }

    public function webhooks(): WebhooksService
    {
        return $this->webhooksService ??= new WebhooksService($this->client);
    }

    public function client(): FanbasisClient
    {
        return $this->client;
    }

    // ── Webhook Parsers ────────────────────────────────────

    /**
     * Parse flat webhook payload (payment.*, product.*, subscription.*).
     *
     * Flat payloads have: payment_id, amount (dollars), status, api_metadata, checkout_session_id, etc.
     */
    protected function parseFlatWebhook(array $payload): WebhookResult
    {
        $apiMetadata = $this->extractApiMetadata($payload);
        $eventType = $payload['event_type'] ?? '';

        return new WebhookResult(
            status: $this->resolveStatusFromEvent($eventType, $payload),
            invoiceId: $apiMetadata['invoice_id'] ?? '',
            transactionId: $payload['payment_id'] ?? (string) ($payload['checkout_session_id'] ?? ''),
            gateway: $this->name(),
            amount: (float) ($payload['amount'] ?? $payload['product_price'] ?? 0),
            currency: $payload['currency'] ?? 'USD',
            metadata: $apiMetadata,
            raw: $payload,
        );
    }

    /**
     * Parse envelope webhook payload (dispute.*, refund.*).
     *
     * Envelope payloads have: { id, type, data: { ... }, created_at }
     */
    protected function parseEnvelopeWebhook(array $payload): WebhookResult
    {
        $type = $payload['type'];
        $data = $payload['data'];

        $status = match ($type) {
            'refund.created'  => PaymentStatus::REFUNDED,
            'dispute.created' => PaymentStatus::FAILED,
            'dispute.updated' => $this->mapDisputeStatus($data['status'] ?? ''),
            default           => PaymentStatus::PENDING,
        };

        return new WebhookResult(
            status: $status,
            invoiceId: '',
            transactionId: $data['payment_intent_id'] ?? $data['dispute_id'] ?? (string) ($data['refund_id'] ?? ''),
            gateway: $this->name(),
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'USD',
            metadata: $data,
            raw: $payload,
        );
    }

    /**
     * Extract api_metadata from the payload.
     *
     * Fanbasis sends metadata in `api_metadata` (can be a JSON string or array).
     * Falls back to `metadata` for forward compatibility.
     */
    protected function extractApiMetadata(array $payload): array
    {
        $raw = $payload['api_metadata'] ?? $payload['metadata'] ?? [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    // ── Status Resolution ───────────────────────────────────

    protected function resolveStatusFromEvent(string $eventType, array $payload): PaymentStatus
    {
        if ($eventType !== '') {
            return match ($eventType) {
                'payment.succeeded', 'product.purchased'      => PaymentStatus::PAID,
                'payment.failed'                              => PaymentStatus::FAILED,
                'payment.expired', 'payment.canceled'         => PaymentStatus::CANCELLED,
                'subscription.created', 'subscription.renewed' => PaymentStatus::PAID,
                'subscription.completed', 'subscription.canceled' => PaymentStatus::CANCELLED,
                'refund.created'                              => PaymentStatus::REFUNDED,
                default                                       => $this->mapStatus($payload['status'] ?? ''),
            };
        }

        return $this->mapStatus($payload['status'] ?? '');
    }

    protected function mapStatus(string $status): PaymentStatus
    {
        return match (strtolower($status)) {
            'paid', 'completed', 'succeeded' => PaymentStatus::PAID,
            'failed', 'error'                => PaymentStatus::FAILED,
            'cancelled', 'canceled'          => PaymentStatus::CANCELLED,
            'refunded'                       => PaymentStatus::REFUNDED,
            'expired'                        => PaymentStatus::CANCELLED,
            default                          => PaymentStatus::PENDING,
        };
    }

    protected function mapDisputeStatus(string $status): PaymentStatus
    {
        return match (strtolower($status)) {
            'won'              => PaymentStatus::PAID,
            'lost'             => PaymentStatus::REFUNDED,
            'needs_response', 'under_review' => PaymentStatus::PROCESSING,
            default            => PaymentStatus::PROCESSING,
        };
    }

    // ── Internal Helpers ────────────────────────────────────

    protected function extractHeader(array $headers, string $key): ?string
    {
        $value = $headers[$key] ?? null;

        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return $value;
    }
}
