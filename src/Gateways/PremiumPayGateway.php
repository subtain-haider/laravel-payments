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
 * PremiumPay payment gateway.
 *
 * Config keys (in config/payments.php → gateways.premiumpay):
 *   - base_url: PremiumPay API URL
 *   - api_key:  PremiumPay API bearer token
 */
class PremiumPayGateway implements PaymentGateway
{
    protected string $baseUrl;
    protected string $apiKey;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->baseUrl = $config['base_url'] ?? 'https://pre.api.premiumpay.pro/api/v1';
        $this->apiKey = $config['api_key'] ?? '';
    }

    public function name(): string
    {
        return 'premiumpay';
    }

    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        $payload = [
            'amount'             => $request->amount,
            'currency'           => $request->currency,
            'productDescription' => $request->productDescription,
            'productName'        => $request->productName,
            'clientOrderId'      => $request->invoiceId,
            'clientIP'           => $request->customerIp,
            'clientEmail'        => $request->customerEmail,
            'okurl'              => $request->successUrl,
            'kourl'              => $request->extra['fail_url'] ?? $request->cancelUrl,
            'cancelurl'          => $request->cancelUrl,
            'callbackurl'        => $request->webhookUrl,
        ];

        $response = Http::asJson()
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/makepayment", $payload);

        if ($response->failed()) {
            throw PaymentException::fromResponse(
                gateway: $this->name(),
                body: $response->body(),
                statusCode: $response->status(),
            );
        }

        $data = $response->json();

        if (isset($data['status']) && $data['status'] !== 'ok') {
            throw new PaymentException(
                message: 'PremiumPay returned an error: ' . ($data['message'] ?? 'unknown'),
                gateway: $this->name(),
                raw: $data,
            );
        }

        return new CheckoutResult(
            redirectUrl: $data['paymentUrl'] ?? $data['redirectUrl'] ?? '',
            transactionId: $data['paymentId'] ?? '',
            gateway: $this->name(),
            raw: $data,
        );
    }

    public function parseWebhook(array $payload): WebhookResult
    {
        $status = $this->mapStatus($payload['status'] ?? '');

        return new WebhookResult(
            status: $status,
            invoiceId: $payload['clientOrderId'] ?? '',
            transactionId: $payload['paymentId'] ?? '',
            gateway: $this->name(),
            amount: (float) ($payload['amount'] ?? 0),
            currency: $payload['currency'] ?? 'USD',
            metadata: [],
            raw: $payload,
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        // PremiumPay relies on callback URL secrecy + IP whitelisting.
        // Override this if PremiumPay adds signature support.
        return true;
    }

    protected function mapStatus(string $status): PaymentStatus
    {
        return match (strtolower($status)) {
            'ok', 'paid', 'completed', 'approved' => PaymentStatus::PAID,
            'failed', 'error', 'declined'          => PaymentStatus::FAILED,
            'cancelled', 'canceled'                => PaymentStatus::CANCELLED,
            default                                => PaymentStatus::PENDING,
        };
    }
}
