<?php

namespace Subtain\LaravelPayments\Gateways\Whop;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Subtain\LaravelPayments\Exceptions\PaymentException;
use Subtain\LaravelPayments\PaymentLogger;

/**
 * Low-level HTTP client for the Whop API (v5).
 *
 * All requests are authenticated with a Bearer API key.
 * Handles retries on 429 / 5xx responses automatically.
 *
 * Base URL: https://api.whop.com/v5
 *
 * @see https://docs.whop.com/api-reference
 */
class WhopClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int    $timeout;
    protected int    $retries;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://api.whop.com/v5', '/');
        $this->apiKey  = $config['api_key'] ?? '';
        $this->timeout = (int) ($config['timeout'] ?? 30);
        $this->retries = (int) ($config['retries'] ?? 2);
    }

    /**
     * GET request.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        PaymentLogger::debug('api.request', [
            'method'   => 'GET',
            'endpoint' => $endpoint,
            'query'    => $query,
        ], gateway: 'whop', category: 'api');

        $response = $this->request()->get($this->url($endpoint), $query);

        return $this->parseResponse($response, $endpoint);
    }

    /**
     * POST request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $data = []): array
    {
        PaymentLogger::debug('api.request', [
            'method'   => 'POST',
            'endpoint' => $endpoint,
        ], gateway: 'whop', category: 'api');

        $response = $this->request()->post($this->url($endpoint), $data);

        return $this->parseResponse($response, $endpoint);
    }

    /**
     * PATCH request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function patch(string $endpoint, array $data = []): array
    {
        PaymentLogger::debug('api.request', [
            'method'   => 'PATCH',
            'endpoint' => $endpoint,
        ], gateway: 'whop', category: 'api');

        $response = $this->request()->patch($this->url($endpoint), $data);

        return $this->parseResponse($response, $endpoint);
    }

    /**
     * DELETE request.
     *
     * @return array<string, mixed>
     */
    public function delete(string $endpoint): array
    {
        PaymentLogger::debug('api.request', [
            'method'   => 'DELETE',
            'endpoint' => $endpoint,
        ], gateway: 'whop', category: 'api');

        $response = $this->request()->delete($this->url($endpoint));

        return $this->parseResponse($response, $endpoint);
    }

    /**
     * Build the authenticated HTTP client.
     *
     * Whop uses a Bearer token for all API calls.
     * Retries are limited to 429 (rate limit) and 5xx (server error) responses.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
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
     * Build the full URL for an endpoint.
     */
    protected function url(string $endpoint): string
    {
        return $this->baseUrl . '/' . ltrim($endpoint, '/');
    }

    /**
     * Parse the HTTP response and throw a PaymentException on any error.
     *
     * Whop returns errors as { "error": { "message": "...", "code": "..." } }
     * or a plain HTTP 4xx/5xx without a JSON body.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    protected function parseResponse(Response $response, string $endpoint = ''): array
    {
        if ($response->failed()) {
            $body = $response->body();

            PaymentLogger::error('api.error', [
                'endpoint'    => $endpoint,
                'status_code' => $response->status(),
                'body'        => $body,
            ], gateway: 'whop', category: 'api');

            // Whop wraps error details in { "error": { "message": "..." } }
            $decoded = $response->json();
            $message = $decoded['error']['message']
                ?? $decoded['message']
                ?? $body;

            throw new PaymentException(
                message: 'Whop API error: ' . $message,
                gateway: 'whop',
                raw: $decoded ?? ['body' => $body],
                code: $response->status(),
            );
        }

        $data = $response->json() ?? [];

        PaymentLogger::debug('api.response', [
            'endpoint'    => $endpoint,
            'status_code' => $response->status(),
        ], gateway: 'whop', category: 'api');

        return $data;
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
