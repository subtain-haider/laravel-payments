<?php

namespace Subtain\LaravelPayments\Gateways\Match2Pay;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Subtain\LaravelPayments\Exceptions\PaymentException;

/**
 * Match2Pay HTTP client.
 *
 * Handles all authenticated HTTP communication with the Match2Pay API.
 * Adds Content-Type: application/json on every request and retries on
 * transient failures (429 / 5xx).
 *
 * The client is intentionally thin — it makes the HTTP call and returns
 * the parsed response body. Higher-level services (DepositService,
 * WithdrawalService) are responsible for building payloads and interpreting
 * responses.
 *
 * Config keys (config/payments.php → gateways.match2pay):
 *   base_url   — API base URL (e.g. https://wallet.match2pay.com/api/v2/)
 *   api_token  — Your API token (included in every request body)
 *   secret     — Your API secret (used for signature generation only, never sent directly)
 *   timeout    — HTTP timeout in seconds (default: 30)
 *   retries    — Retry count on 429/5xx (default: 2)
 *
 * @see https://docs.match2pay.com
 */
class Match2PayClient
{
    protected string $baseUrl;
    protected string $apiToken;
    protected string $apiSecret;
    protected int $timeout;
    protected int $retries;

    public function __construct(array $config = [])
    {
        $this->baseUrl   = rtrim($config['base_url'] ?? 'https://wallet.match2pay.com/api/v2/', '/') . '/';
        $this->apiToken  = $config['api_token'] ?? '';
        $this->apiSecret = $config['secret'] ?? '';
        $this->timeout   = (int) ($config['timeout'] ?? 30);
        $this->retries   = (int) ($config['retries'] ?? 2);
    }

    /**
     * POST to a Match2Pay endpoint and return the parsed response body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PaymentException  on HTTP failure or non-2xx response
     */
    public function post(string $path, array $payload): array
    {
        Log::debug('Match2Pay API request', [
            'method'   => 'POST',
            'endpoint' => $path,
            'payload'  => $this->redactSensitive($payload),
        ]);

        try {
            $response = $this->buildHttpClient()->post($path, $payload);

            $body = $response->json() ?? [];

            Log::debug('Match2Pay API response', [
                'endpoint'    => $path,
                'status_code' => $response->status(),
                'body'        => $body,
            ]);

            if ($response->failed()) {
                $message = $this->extractErrorMessage($body);

                Log::error('Match2Pay API error', [
                    'endpoint'    => $path,
                    'status_code' => $response->status(),
                    'message'     => $message,
                    'body'        => $body,
                ]);

                throw new PaymentException(
                    message: $message,
                    gateway: 'match2pay',
                    raw: $body,
                );
            }

            return $body;
        } catch (RequestException $e) {
            Log::error('Match2Pay HTTP request exception', [
                'endpoint'  => $path,
                'exception' => $e->getMessage(),
            ]);

            throw new PaymentException(
                message: 'Match2Pay request failed: ' . $e->getMessage(),
                gateway: 'match2pay',
            );
        }
    }

    /**
     * Return the API token for inclusion in request payloads.
     */
    public function getApiToken(): string
    {
        return $this->apiToken;
    }

    /**
     * Return the API secret for use by SignatureService.
     */
    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    /**
     * Build the authenticated HTTP client with retry logic.
     */
    protected function buildHttpClient(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->retry($this->retries, 500, fn (\Throwable $e, \Illuminate\Http\Client\Response $response) => $response->status() === 429 || $response->serverError(), throw: false)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->acceptJson();
    }

    /**
     * Extract a human-readable error message from a Match2Pay error response.
     *
     * Match2Pay error responses may use:
     *   - errorMessage (most common)
     *   - message
     *   - errorList array
     */
    protected function extractErrorMessage(array $body): string
    {
        if (isset($body['errorMessage']) && is_string($body['errorMessage']) && $body['errorMessage'] !== '') {
            return $body['errorMessage'];
        }

        if (isset($body['message']) && is_string($body['message']) && $body['message'] !== '') {
            return $body['message'];
        }

        if (! empty($body['errorList']) && is_array($body['errorList'])) {
            return implode(', ', array_map('strval', $body['errorList']));
        }

        return 'Match2Pay request failed.';
    }

    /**
     * Redact the API secret from logged payloads.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function redactSensitive(array $payload): array
    {
        $redacted = $payload;
        if (isset($redacted['signature'])) {
            $redacted['signature'] = '[redacted]';
        }

        return $redacted;
    }
}
