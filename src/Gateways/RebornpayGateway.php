<?php

namespace Subtain\LaravelPayments\Gateways;

use Illuminate\Support\Facades\Log;
use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Exceptions\PaymentException;
use Subtain\LaravelPayments\Gateways\Rebornpay\PayinService;
use Subtain\LaravelPayments\Gateways\Rebornpay\RebornpayClient;
use Subtain\LaravelPayments\Gateways\Rebornpay\SignatureService;
use Subtain\LaravelPayments\Gateways\Rebornpay\TransactionService;

/**
 * Rebornpay UPI payment gateway.
 *
 * Handles Indian UPI (and IMPS) payments via the Rebornpay API.
 * Used in user-facing contexts as "UPI" even though the internal
 * gateway name is "rebornpay".
 *
 * ── Checkout flow ──────────────────────────────────────────────────
 * 1. checkout() calls the Rebornpay pay-in API and gets back a
 *    payment_page_url to redirect the customer to.
 * 2. Optionally appends "redirect_success_url" from extra['success_url']
 *    so the customer is returned to your site after payment.
 * 3. extra['payment_option'] controls the method — "UPI" (default) or "IMPS".
 *
 * ── Webhook (postback) flow ────────────────────────────────────────
 * Rebornpay posts to your configured postback_url when a transaction
 * changes state. The payload always contains a "transactions" array
 * (exactly one element) and a "sign" field for signature verification.
 *
 * Signature algorithm: MD5 of Python-style URL-encoded key=value pairs.
 * See SignatureService for full details and float-precision handling.
 *
 * ── Config keys (config/payments.php → gateways.rebornpay) ────────
 *   base_url        — API base URL (default: https://prod.api.rbpcloud.pro)
 *   api_key         — X-API-Key header value
 *   client_id       — Your Rebornpay client UUID
 *   postback_key    — client_postback_key for webhook signature verification
 *   timeout         — HTTP timeout in seconds (default: 30)
 *   retries         — Retry count on 429/5xx (default: 2)
 *
 * @see https://prod.api.rbpcloud.pro — Rebornpay API docs
 */
class RebornpayGateway implements PaymentGateway
{
    protected RebornpayClient $client;
    protected string $clientId;
    protected string $postbackKey;

    protected ?PayinService $payinService = null;
    protected ?TransactionService $transactionService = null;

    public function __construct(array $config = [])
    {
        $this->client      = new RebornpayClient($config);
        $this->clientId    = $config['client_id'] ?? '';
        $this->postbackKey = $config['postback_key'] ?? '';
    }

    public function name(): string
    {
        return 'rebornpay';
    }

    // ── Checkout ─────────────────────────────────────────────────────

    /**
     * Create a pay-in transaction and return the payment page URL.
     *
     * Required CheckoutRequest fields:
     *   - amount          — Transaction amount (will be used as-is; currency conversion
     *                       is the caller's responsibility via extra['amount_override'])
     *   - invoiceId       — Used as client_transaction_id for webhook reconciliation
     *   - customerName    — Used as client_user (falls back to customerEmail, then invoiceId)
     *
     * Optional extra fields:
     *   - payment_option  — "UPI" (default) or "IMPS"
     *   - amount_override — Use this exact amount instead of request->amount
     *                       (useful for INR-converted amounts)
     *
     * @throws PaymentException
     */
    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        if ($this->clientId === '') {
            throw new PaymentException(
                message: 'Rebornpay client_id is not configured.',
                gateway: $this->name(),
            );
        }

        $amount = (float) ($request->extra['amount_override'] ?? $request->amount);

        $payload = [
            'amount'                => $amount,
            'currency'              => $request->currency ?: 'INR',
            'client_user'           => $this->resolveClientUser($request),
            'client_id'             => $this->clientId,
            'client_transaction_id' => $request->invoiceId,
            'payment_option_name'   => $request->extra['payment_option'] ?? 'UPI',
        ];

        Log::info('Rebornpay checkout initiated', [
            'invoice_id'            => $request->invoiceId,
            'amount'                => $payload['amount'],
            'currency'              => $payload['currency'],
            'client_transaction_id' => $payload['client_transaction_id'],
            'payment_option'        => $payload['payment_option_name'],
        ]);

        $data = $this->payin()->create($payload);

        $paymentPageUrl = (string) ($data['payment_page_url'] ?? '');

        if ($paymentPageUrl === '') {
            Log::error('Rebornpay checkout returned empty payment_page_url', [
                'invoice_id' => $request->invoiceId,
                'response'   => $data,
            ]);

            throw new PaymentException(
                message: 'Rebornpay response missing payment_page_url.',
                gateway: $this->name(),
                raw: $data,
            );
        }

        // Append redirect_success_url so the customer returns to your site after payment
        $redirectSuccessUrl = $request->successUrl;
        if ($redirectSuccessUrl !== '') {
            $paymentPageUrl = $this->appendQueryParam(
                $paymentPageUrl,
                'redirect_success_url',
                $redirectSuccessUrl
            );
        }

        Log::info('Rebornpay checkout successful', [
            'invoice_id'     => $request->invoiceId,
            'transaction_id' => $data['transaction_id'] ?? '',
            'payin_id'       => $data['payin_id'] ?? '',
            'expiry_time'    => $data['expiry_time'] ?? null,
        ]);

        return new CheckoutResult(
            redirectUrl:   $paymentPageUrl,
            transactionId: (string) ($data['transaction_id'] ?? ''),
            gateway:       $this->name(),
            raw:           $data,
        );
    }

    // ── Webhooks ──────────────────────────────────────────────────────

    /**
     * Parse a Rebornpay postback payload into a standardized WebhookResult.
     *
     * Rebornpay postback structure:
     * {
     *   "postback_id": 27,
     *   "postback_type": 1,
     *   "postback_is_fake": false,        // true = fraudulent — never credit
     *   "creation_type": "auto",
     *   "client_user": "user123",
     *   "transactions": [{ ... }],        // always exactly ONE element
     *   "sign": "..."
     * }
     *
     * Status mapping:
     *   postback_is_fake = false → PaymentStatus::PAID
     *   postback_is_fake = true  → PaymentStatus::FAILED
     */
    public function parseWebhook(array $payload): WebhookResult
    {
        $isFake       = $this->toBoolean($payload['postback_is_fake'] ?? false);
        $transactions = $payload['transactions'] ?? [];
        $transaction  = is_array($transactions) && ! empty($transactions) ? $transactions[0] : [];

        $transactionId       = (string) ($transaction['transaction_id'] ?? '');
        $clientTransactionId = (string) ($transaction['client_transaction_id'] ?? '');
        $amount              = (float) ($transaction['transaction_amount'] ?? 0);
        $currency            = (string) ($transaction['transaction_currency_code'] ?? 'INR');

        $status = $isFake ? PaymentStatus::FAILED : PaymentStatus::PAID;

        Log::info('Rebornpay webhook parsed', [
            'postback_id'           => $payload['postback_id'] ?? null,
            'postback_is_fake'      => $isFake,
            'creation_type'         => $payload['creation_type'] ?? null,
            'transaction_id'        => $transactionId,
            'client_transaction_id' => $clientTransactionId,
            'amount'                => $amount,
            'currency'              => $currency,
            'status'                => $status->value,
        ]);

        return new WebhookResult(
            status:        $status,
            invoiceId:     $clientTransactionId,
            transactionId: $transactionId,
            gateway:       $this->name(),
            amount:        $amount,
            currency:      $currency,
            metadata:      [
                'postback_id'      => $payload['postback_id'] ?? null,
                'postback_type'    => $payload['postback_type'] ?? null,
                'postback_is_fake' => $isFake,
                'creation_type'    => $payload['creation_type'] ?? null,
                'client_user'      => $payload['client_user'] ?? null,
                'payment_details'  => $transaction['payment_details'] ?? null,
                'payment_method'   => $transaction['payment_method_name'] ?? null,
            ],
            raw: $payload,
        );
    }

    /**
     * Verify the Rebornpay webhook signature.
     *
     * Uses MD5 + Python-style serialization over the sorted key=value pairs.
     * See SignatureService for the full algorithm description.
     *
     * NOTE: This method receives the already-parsed payload. If the payload
     * contains whole-number floats (e.g. transaction_amount: 2000), PHP may
     * have dropped the ".0" suffix during JSON parsing. Use verifyWebhookSignature()
     * with the raw body for the most accurate verification.
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        if (empty($this->postbackKey)) {
            Log::warning('Rebornpay webhook verification skipped: postback_key not configured');

            return true;
        }

        $valid = SignatureService::verify($payload, $this->postbackKey);

        if (! $valid) {
            Log::warning('Rebornpay webhook signature verification failed', [
                'postback_id' => $payload['postback_id'] ?? null,
            ]);
        }

        return $valid;
    }

    /**
     * Verify the Rebornpay webhook signature using the raw request body (recommended).
     *
     * Preserves float precision (e.g. "2000.0") that is lost when PHP parses JSON.
     * Use this in your controller:
     *   $gateway->verifyWebhookSignature($request->getContent(), $request->headers->all())
     *
     * @param  string  $rawBody  Raw JSON body from $request->getContent()
     */
    public function verifyWebhookSignature(string $rawBody, array $headers = []): bool
    {
        if (empty($this->postbackKey)) {
            Log::warning('Rebornpay webhook verification skipped: postback_key not configured');

            return true;
        }

        $payload = json_decode($rawBody, true);
        $valid   = SignatureService::verifyFromRawBody($rawBody, $this->postbackKey);

        if (! $valid) {
            Log::warning('Rebornpay webhook signature verification failed (raw body check)', [
                'postback_id' => is_array($payload) ? ($payload['postback_id'] ?? null) : null,
            ]);
        }

        return $valid;
    }

    // ── Service Accessors ─────────────────────────────────────────────

    /**
     * Access the Pay-In service for direct API calls.
     */
    public function payin(): PayinService
    {
        return $this->payinService ??= new PayinService($this->client);
    }

    /**
     * Access the Transaction service for status checks and UTR storage.
     */
    public function transactions(): TransactionService
    {
        return $this->transactionService ??= new TransactionService($this->client);
    }

    /**
     * Access the underlying HTTP client.
     */
    public function client(): RebornpayClient
    {
        return $this->client;
    }

    // ── Internal Helpers ──────────────────────────────────────────────

    /**
     * Resolve the client_user identifier from the checkout request.
     *
     * Priority: customerName → customerEmail → invoiceId
     */
    protected function resolveClientUser(CheckoutRequest $request): string
    {
        $name = trim($request->customerName);
        if ($name !== '') {
            return $name;
        }

        $email = trim($request->customerEmail);
        if ($email !== '') {
            return $email;
        }

        return $request->invoiceId;
    }

    /**
     * Append a query parameter to a URL, preserving any existing query string.
     */
    protected function appendQueryParam(string $url, string $key, string $value): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query[$key] = $value;

        $rebuilt = '';
        if (isset($parts['scheme'])) {
            $rebuilt .= $parts['scheme'] . '://';
        }
        if (isset($parts['user'])) {
            $rebuilt .= $parts['user'];
            if (isset($parts['pass'])) {
                $rebuilt .= ':' . $parts['pass'];
            }
            $rebuilt .= '@';
        }
        if (isset($parts['host'])) {
            $rebuilt .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';

        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $rebuilt .= '?' . $queryString;
        }
        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    /**
     * Normalize a mixed value to boolean.
     *
     * Handles the variety of ways "false" can arrive in JSON:
     * actual boolean false, string "false", string "0", integer 0.
     */
    protected function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes'], true);
    }
}
