<?php

namespace Subtain\LaravelPayments\Gateways\Fxpay;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Subtain\LaravelPayments\Exceptions\PaymentException;
use Subtain\LaravelPayments\PaymentLogger;

/**
 * Low-level HTTP client for the FxPay API.
 *
 * All FxPay service classes delegate HTTP calls to this client.
 * Handles authentication (Bearer token via Authorization header),
 * error parsing, retry logic, and structured logging for every
 * request/response cycle.
 *
 * Base URL: https://fxpay.live
 *
 * @see https://fxpay.live — FxPay API docs
 */
class FxpayClient
{
    protected string $baseUrl;
    protected string $apiSecret;
    protected int $timeout;
    protected int $retries;

    public function __construct(array $config = [])
    {
        $this->baseUrl   = rtrim($config['base_url'] ?? 'https://fxpay.live', '/');
        $this->apiSecret = $config['api_secret'] ?? '';
        $this->timeout   = (int) ($config['timeout'] ?? 30);
        $this->retries   = (int) ($config['retries'] ?? 2);
    }

    /**
     * POST request to the FxPay API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function post(string $endpoint, array $data = []): array
    {
        $url = $this->url($endpoint);

        PaymentLogger::debug('api.request', [
            'method'   => 'POST',
            'endpoint' => $endpoint,
            'url'      => $url,
            'payload'  => $data,
        ], gateway: 'fxpay', category: 'api');

        $response = $this->request()->post($url, $data);

        return $this->parseResponse($response, $endpoint);
    }

    /**
     * Build the full URL for a given endpoint path.
     */
    protected function url(string $endpoint): string
    {
        return $this->baseUrl . '/' . ltrim($endpoint, '/');
    }

    /**
     * Build the authenticated HTTP client with retry logic.
     *
     * FxPay uses a Bearer token (API Secret) in the Authorization header.
     * Retries are limited to 429 (rate limit) and 5xx error responses.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiSecret,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ])
        ->timeout($this->timeout)
        ->retry($this->retries, 1000, function (\Throwable $e, PendingRequest $request) {
            if ($e instanceof \Illuminate\Http\Client\RequestException) {
                return $e->response->status() === 429 || $e->response->status() >= 500;
            }

            return false;
        });
    }

    /**
     * Parse the API response and throw a PaymentException on failure.
     *
     * FxPay error responses typically include a descriptive message body.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    protected function parseResponse(Response $response, string $endpoint): array
    {
        $data    = $response->json() ?? [];
        $rawBody = $response->body();

        if ($response->failed()) {
            $errorMessage = $this->extractErrorMessage($data);

            PaymentLogger::error('api.error', [
                'endpoint'    => $endpoint,
                'status_code' => $response->status(),
                'message'     => $errorMessage,
                'body'        => $data,
            ], gateway: 'fxpay', category: 'api');

            throw PaymentException::fromResponse(
                gateway: 'fxpay',
                body: $rawBody,
                statusCode: $response->status(),
            );
        }

        PaymentLogger::debug('api.response', [
            'endpoint' => $endpoint,
            'body'     => $data,
        ], gateway: 'fxpay', category: 'api');

        return is_array($data) ? $data : [];
    }

    /**
     * Extract a human-readable error message from the FxPay error response.
     */
    protected function extractErrorMessage(mixed $data): string
    {
        if (! is_array($data)) {
            return 'FxPay request failed.';
        }

        foreach (['message', 'error', 'detail'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'FxPay request failed.';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }
}
