<?php

namespace Subtain\LaravelPayments\Gateways;

use Illuminate\Support\Facades\Http;
use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Exceptions\PaymentException;

/**
 * Fanbasis payment gateway.
 *
 * Supports two checkout modes:
 * - Dynamic: creates a checkout session via Fanbasis API
 * - Static: uses a pre-configured payment link (set via extra['payment_link'])
 *
 * Config keys (in config/payments.php → gateways.fanbasis):
 *   - base_url: Fanbasis API URL
 *   - api_key:  Fanbasis API key
 */
class FanbasisGateway implements PaymentGateway
{
    protected string $baseUrl;
    protected string $apiKey;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url'] ?? 'https://www.fanbasis.com/public-api';
        $this->apiKey = $config['api_key'] ?? '';
    }

    public function name(): string
    {
        return 'fanbasis';
    }

    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        // Static payment link mode — if a pre-built link is provided
        $staticLink = $request->extra['payment_link'] ?? null;
        if ($staticLink) {
            return $this->staticCheckout($request, $staticLink);
        }

        return $this->dynamicCheckout($request);
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        // Fanbasis webhooks come with metadata containing the original IDs
        $metadata = $payload['metadata'] ?? [];
        $status = $this->mapStatus($payload['status'] ?? '');

        return new WebhookResult(
            status: $status,
            invoiceId: $metadata['ChallengeId'] ?? '',
            transactionId: $payload['checkout_session_id'] ?? '',
            gateway: $this->name(),
            amount: ($payload['amount_cents'] ?? 0) / 100,
            currency: $payload['currency'] ?? 'USD',
            metadata: $metadata,
            raw: $payload,
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        // Fanbasis does not provide webhook signatures — always return true.
        // Override this if Fanbasis adds signature support in the future.
        return true;
    }

    /**
     * Create a dynamic checkout session via the Fanbasis API.
     */
    protected function dynamicCheckout(CheckoutRequest $request): CheckoutResult
    {
        $payload = [
            'product' => [
                'title'       => $request->productName,
                'description' => $request->productDescription,
            ],
            'amount_cents'    => (int) ($request->amount * 100),
            'application_fee' => $request->extra['application_fee'] ?? 10,
            'type'            => $request->extra['type'] ?? 'onetime_non_reusable',
            'metadata'        => $request->metadata,
            'success_url'     => $request->successUrl,
            'webhook_url'     => $request->webhookUrl,
        ];

        $response = Http::withHeaders([
            'x-api-key'    => $this->apiKey,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/checkout-sessions", $payload);

        if ($response->failed()) {
            throw PaymentException::fromResponse(
                gateway: $this->name(),
                body: $response->body(),
                statusCode: $response->status(),
            );
        }

        $data = $response->json();

        return new CheckoutResult(
            redirectUrl: $data['data']['payment_link'] ?? $data['data']['checkout_url'] ?? '',
            transactionId: $data['data']['checkout_session_id'] ?? '',
            gateway: $this->name(),
            raw: $data,
        );
    }

    /**
     * Build a static payment link (pre-configured on the gateway dashboard).
     */
    protected function staticCheckout(CheckoutRequest $request, string $paymentLink): CheckoutResult
    {
        $url = $paymentLink . '/?'
            . http_build_query(array_filter([
                'challengeId' => $request->invoiceId,
                'userId'      => $request->metadata['user_id'] ?? '',
            ]));

        return new CheckoutResult(
            redirectUrl: $url,
            transactionId: '',
            gateway: $this->name(),
            raw: ['payment_link' => $url],
        );
    }

    /**
     * Map Fanbasis status strings to our standardized enum.
     */
    protected function mapStatus(string $status): PaymentStatus
    {
        return match (strtolower($status)) {
            'paid', 'completed', 'succeeded' => PaymentStatus::PAID,
            'failed', 'error'                => PaymentStatus::FAILED,
            'cancelled', 'canceled'          => PaymentStatus::CANCELLED,
            default                          => PaymentStatus::PENDING,
        };
    }
}
