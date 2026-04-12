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
 * Config (config/payments.php → gateways.fanbasis):
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

    public function parseWebhook(array $payload): WebhookResult
    {
        $metadata = $payload['metadata'] ?? [];
        $eventType = $payload['type'] ?? $payload['event'] ?? '';

        return new WebhookResult(
            status: $this->resolveStatus($eventType, $payload),
            invoiceId: $metadata['invoice_id'] ?? '',
            transactionId: $payload['checkout_session_id'] ?? $payload['transaction_id'] ?? '',
            gateway: $this->name(),
            amount: isset($payload['amount_cents'])
                ? $payload['amount_cents'] / 100
                : (float) ($payload['amount'] ?? 0),
            currency: $payload['currency'] ?? 'USD',
            metadata: $metadata,
            raw: $payload,
        );
    }

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

    // ── Internal ────────────────────────────────────────────

    protected function resolveStatus(string $eventType, array $payload): PaymentStatus
    {
        if ($eventType !== '') {
            return match ($eventType) {
                'payment.succeeded', 'product.purchased'      => PaymentStatus::PAID,
                'payment.failed'                              => PaymentStatus::FAILED,
                'payment.expired', 'payment.canceled'         => PaymentStatus::CANCELLED,
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
            default                          => PaymentStatus::PENDING,
        };
    }

    /**
     * Extract a header value from Laravel's header array (handles both flat and nested).
     */
    protected function extractHeader(array $headers, string $key): ?string
    {
        $value = $headers[$key] ?? null;

        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return $value;
    }
}
