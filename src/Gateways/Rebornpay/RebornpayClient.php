<?php

namespace Subtain\LaravelPayments\Gateways\Rebornpay;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Subtain\LaravelPayments\PaymentLogger;
use Subtain\LaravelPayments\Exceptions\PaymentException;

/**
 * Low-level HTTP client for the Rebornpay API.
 *
 * All Rebornpay service classes delegate HTTP calls to this client.
 * Handles authentication (X-API-Key header), error parsing, and
 * structured logging for every request/response cycle.
 *
 * Base URL: https://prod.api.rbpcloud.pro
 *
 * @see https://prod.api.rbpcloud.pro — Rebornpay API docs
 */
class RebornpayClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $retries;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://prod.api.rbpcloud.pro', '/');
        $this->apiKey  = $config['api_key'] ?? '';
        $this->timeout = (int) ($config['timeout'] ?? 30);
        $this->retries = (int) ($config['retries'] ?? 2);
    }

    /**
     * POST request to the Rebornpay API.
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
        ], gateway: 'rebornpay', category: 'api');

        $response = $this->request()->post($url, $data);

        return $this->parseResponse($response, $endpoint);
    }

    /**
     * GET request to the Rebornpay API.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function get(string $endpoint, array $query = []): array
    {
        $url = $this->url($endpoint);

        PaymentLogger::debug('api.request', [
            'method'   => 'GET',
            'endpoint' => $endpoint,
            'url'      => $url,
            'query'    => $query,
        ], gateway: 'rebornpay', category: 'api');

        $response = $this->request()->get($url, $query);

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
     * Rebornpay uses X-API-Key header for authentication.
     * Retries are limited to 429 (rate limit) and 5xx responses.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key'    => $this->apiKey,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
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
     * Rebornpay error responses use a "detail" key, which may be
     * a plain string or an array of validation error objects.
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
            ], gateway: 'rebornpay', category: 'api');

            throw PaymentException::fromResponse(
                gateway: 'rebornpay',
                body: $rawBody,
                statusCode: $response->status(),
            );
        }

        PaymentLogger::debug('api.response', [
            'endpoint' => $endpoint,
            'body'     => $data,
        ], gateway: 'rebornpay', category: 'api');

        return is_array($data) ? $data : [];
    }

    /**
     * Extract a human-readable error message from the Rebornpay error response.
     *
     * Rebornpay uses "detail" which can be:
     *  - A plain string: "Invalid API key"
     *  - An array of validation objects: [{"loc": [...], "msg": "...", "type": "..."}]
     */
    protected function extractErrorMessage(mixed $data): string
    {
        if (! is_array($data)) {
            return 'Rebornpay request failed.';
        }

        $detail = $data['detail'] ?? null;

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        if (is_array($detail) && ! empty($detail)) {
            $first = $detail[0] ?? null;
            if (is_array($first) && isset($first['msg']) && is_string($first['msg'])) {
                return $first['msg'];
            }

            $encoded = json_encode($detail);

            return is_string($encoded) ? $encoded : 'Rebornpay validation error.';
        }

        $message = $data['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'Rebornpay request failed.';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}
