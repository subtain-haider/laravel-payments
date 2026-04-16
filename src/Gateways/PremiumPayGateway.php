<?php

namespace Subtain\LaravelPayments\Gateways;

use Illuminate\Support\Facades\Http;
use Subtain\LaravelPayments\PaymentLogger;
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

        PaymentLogger::info('checkout.initiated', [
            'invoice_id' => $request->invoiceId,
            'amount'     => $request->amount,
            'currency'   => $request->currency,
        ], gateway: 'premiumpay', category: 'checkout');

        $response = Http::asJson()
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/makepayment", $payload);

        if ($response->failed()) {
            PaymentLogger::error('checkout.http_error', [
                'invoice_id'  => $request->invoiceId,
                'status_code' => $response->status(),
                'body'        => $response->body(),
            ], gateway: 'premiumpay', category: 'checkout');

            throw PaymentException::fromResponse(
                gateway: $this->name(),
                body: $response->body(),
                statusCode: $response->status(),
            );
        }

        $data = $response->json();

        if (isset($data['status']) && $data['status'] !== 'ok') {
            PaymentLogger::error('checkout.gateway_error', [
                'invoice_id' => $request->invoiceId,
                'message'    => $data['message'] ?? 'unknown',
                'status'     => $data['status'] ?? null,
            ], gateway: 'premiumpay', category: 'checkout');

            throw new PaymentException(
                message: 'PremiumPay returned an error: ' . ($data['message'] ?? 'unknown'),
                gateway: $this->name(),
                raw: $data,
            );
        }

        PaymentLogger::info('checkout.success', [
            'invoice_id'     => $request->invoiceId,
            'payment_id'     => $data['paymentId'] ?? null,
            'redirect_url'   => $data['paymentUrl'] ?? $data['redirectUrl'] ?? null,
        ], gateway: 'premiumpay', category: 'checkout');

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

        PaymentLogger::info('webhook.parsed', [
            'invoice_id'  => $payload['clientOrderId'] ?? null,
            'payment_id'  => $payload['paymentId'] ?? null,
            'status'      => $payload['status'] ?? null,
            'mapped_status' => $status->value,
        ], gateway: 'premiumpay', category: 'webhook');

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
