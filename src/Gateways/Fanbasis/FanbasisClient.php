<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Subtain\LaravelPayments\Exceptions\PaymentException;

/**
 * Low-level HTTP client for the Fanbasis public API.
 *
 * Every Fanbasis service class delegates HTTP calls to this client.
 * Handles authentication, error parsing, and retry logic.
 *
 * @see https://apidocs.fan
 */
class FanbasisClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $retries;

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['base_url'] ?? 'https://www.fanbasis.com/public-api', '/');
        $this->apiKey = $config['api_key'] ?? '';
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
        $response = $this->request()->get($this->url($endpoint), $query);

        return $this->parseResponse($response);
    }

    /**
     * POST request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $data = []): array
    {
        $response = $this->request()->post($this->url($endpoint), $data);

        return $this->parseResponse($response);
    }

    /**
     * PUT request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function put(string $endpoint, array $data = []): array
    {
        $response = $this->request()->put($this->url($endpoint), $data);

        return $this->parseResponse($response);
    }

    /**
     * DELETE request.
     *
     * @return array<string, mixed>
     */
    public function delete(string $endpoint): array
    {
        $response = $this->request()->delete($this->url($endpoint));

        return $this->parseResponse($response);
    }

    /**
     * Build the authenticated HTTP client.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key'    => $this->apiKey,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ])
        ->timeout($this->timeout)
        ->retry($this->retries, 1000, function (\Throwable $e, PendingRequest $request) {
            // Only retry on 429 (rate limit) or 5xx
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
     * Parse the response and throw on errors.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    protected function parseResponse(Response $response): array
    {
        if ($response->failed()) {
            throw PaymentException::fromResponse(
                gateway: 'fanbasis',
                body: $response->body(),
                statusCode: $response->status(),
            );
        }

        $data = $response->json();

        if (($data['status'] ?? '') === 'error') {
            throw new PaymentException(
                message: 'Fanbasis API error: ' . ($data['message'] ?? 'Unknown error'),
                gateway: 'fanbasis',
                raw: $data,
            );
        }

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
