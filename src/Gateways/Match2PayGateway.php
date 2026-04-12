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
 * Match2Pay crypto payment gateway.
 *
 * Config keys (in config/payments.php → gateways.match2pay):
 *   - base_url:  Match2Pay API URL
 *   - api_token: Match2Pay API token
 *   - secret:    Shared secret for signature generation
 */
class Match2PayGateway implements PaymentGateway
{
    protected string $baseUrl;
    protected string $apiToken;
    protected string $secret;
    protected string $hashAlgo;
    protected string $endpoint;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->apiToken = $config['api_token'] ?? '';
        $this->secret = $config['secret'] ?? '';
        $this->hashAlgo = $config['hash_algo'] ?? 'sha384';
        $this->endpoint = $config['endpoint'] ?? 'deposit/crypto_agent';
    }

    public function name(): string
    {
        return 'match2pay';
    }

    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        $payload = [
            'apiToken'           => $this->apiToken,
            'callbackUrl'        => $request->webhookUrl,
            'currency'           => $request->currency,
            'amount'             => $request->amount,
            'paymentCurrency'    => $request->extra['payment_currency'] ?? 'USX',
            'paymentGatewayName' => $request->extra['payment_gateway_name'] ?? 'USDT TRC20',
            'timestamp'          => (string) time(),
        ];

        $payload['signature'] = $this->buildSignature($payload);

        $response = Http::asJson()->post("{$this->baseUrl}/{$this->endpoint}", $payload);

        if ($response->failed()) {
            throw PaymentException::fromResponse(
                gateway: $this->name(),
                body: $response->body(),
                statusCode: $response->status(),
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') === 'error') {
            throw new PaymentException(
                message: 'Match2Pay returned an error: ' . ($data['message'] ?? 'unknown'),
                gateway: $this->name(),
                raw: $data,
            );
        }

        return new CheckoutResult(
            redirectUrl: $data['data']['checkoutUrl'] ?? '',
            transactionId: $data['data']['paymentId'] ?? '',
            gateway: $this->name(),
            raw: $data,
        );
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        $status = $this->mapStatus($payload['status'] ?? '');

        return new WebhookResult(
            status: $status,
            invoiceId: $payload['orderId'] ?? '',
            transactionId: $payload['paymentId'] ?? '',
            gateway: $this->name(),
            amount: (float) ($payload['transactionAmount'] ?? 0),
            currency: $payload['currency'] ?? 'USD',
            metadata: [],
            raw: $payload,
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        if (empty($this->secret)) {
            return true;
        }

        $signature = $payload['signature'] ?? '';
        unset($payload['signature']);

        return hash_equals($this->buildSignature($payload), $signature);
    }

    /**
     * Build a signature for the given payload.
     *
     * Uses the hash algorithm configured for this gateway (default: sha384).
     * The signature is: hash(concatenated sorted values + secret).
     *
     * @param  array<string, mixed>  $data
     */
    public function buildSignature(array $data): string
    {
        unset($data['signature']);
        ksort($data);

        return hash($this->hashAlgo, implode('', array_values($data)) . $this->secret);
    }

    protected function mapStatus(string $status): PaymentStatus
    {
        return match (strtoupper($status)) {
            'DONE', 'COMPLETED', 'APPROVED' => PaymentStatus::PAID,
            'FAILED', 'ERROR', 'DECLINED'   => PaymentStatus::FAILED,
            'CANCELLED', 'CANCELED'         => PaymentStatus::CANCELLED,
            default                         => PaymentStatus::PENDING,
        };
    }
}
