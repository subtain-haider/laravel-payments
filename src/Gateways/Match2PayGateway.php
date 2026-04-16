<?php

namespace Subtain\LaravelPayments\Gateways;

use Subtain\LaravelPayments\PaymentLogger;
use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Exceptions\PaymentException;
use Subtain\LaravelPayments\Gateways\Match2Pay\DepositService;
use Subtain\LaravelPayments\Gateways\Match2Pay\Match2PayClient;
use Subtain\LaravelPayments\Gateways\Match2Pay\SignatureService;
use Subtain\LaravelPayments\Gateways\Match2Pay\WithdrawalService;

/**
 * Match2Pay crypto payment gateway.
 *
 * Handles crypto deposits (pay-ins) and withdrawals (pay-outs) via the
 * Match2Pay API v2.
 *
 * ── Checkout flow ──────────────────────────────────────────────────────────
 * 1. checkout() builds a signed deposit request and sends it to
 *    POST /api/v2/payment/deposit.
 * 2. Returns the checkoutUrl to redirect the customer to the Match2Pay
 *    payment page (or embed in an iframe).
 * 3. If paymentCurrency/paymentGatewayName are provided in extra[], the
 *    customer lands directly on the payment details step. Otherwise a
 *    2-step crypto selection page is shown.
 *
 * ── Customer object ────────────────────────────────────────────────────────
 * The customer object can be passed via extra['customer']. If omitted,
 * a minimal customer object is built from the CheckoutRequest fields.
 * The key order in the customer object is critical for signature generation
 * and is enforced by SignatureService::formatCustomer().
 *
 * ── Webhook (callback) flow ────────────────────────────────────────────────
 * Match2Pay sends two callbacks per transaction:
 *   1. PENDING — transaction appears in blockchain
 *   2. DONE    — funds confirmed and booked
 *
 * Per docs, signature verification should ONLY be performed for DONE status.
 * The signature arrives in the HTTP header (header name varies — check your
 * Match2Pay dashboard), not the body.
 *
 * ── Config keys (config/payments.php → gateways.match2pay) ───────────────
 *   base_url   — API base URL (default: https://wallet.match2pay.com/api/v2/)
 *   api_token  — Your API token (sent in every request body)
 *   secret     — Your API secret (used for signature, never sent directly)
 *   timeout    — HTTP timeout in seconds (default: 30)
 *   retries    — Retry count on 429/5xx (default: 2)
 *
 * @see https://docs.match2pay.com
 */
class Match2PayGateway implements PaymentGateway
{
    protected Match2PayClient $client;
    protected string $apiToken;
    protected string $apiSecret;

    protected ?DepositService $depositService = null;
    protected ?WithdrawalService $withdrawalService = null;

    public function __construct(array $config = [])
    {
        $this->client    = new Match2PayClient($config);
        $this->apiToken  = $config['api_token'] ?? '';
        $this->apiSecret = $config['secret'] ?? '';
    }

    public function name(): string
    {
        return 'match2pay';
    }

    // ── Checkout ───────────────────────────────────────────────────────────

    /**
     * Create a deposit transaction and return the checkout URL.
     *
     * Required CheckoutRequest fields:
     *   - amount      — Deposit amount in the account currency
     *   - currency    — Account currency (e.g. "USD")
     *   - webhookUrl  — Your callback URL for Match2Pay to post status updates
     *
     * Optional extra fields:
     *   - payment_currency      — e.g. "USX" (USDT TRC20). Omit for 2-step selection.
     *   - payment_gateway_name  — e.g. "USDT TRC20". Omit for 2-step selection.
     *   - customer              — Full customer array (see docs). Built from request fields if omitted.
     *   - trading_account_login — Passed as tradingAccountLogin (defaults to customerEmail)
     *   - trading_account_uuid  — Passed as tradingAccountUuid (defaults to invoiceId)
     *
     * @throws PaymentException
     */
    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        $customer = $request->extra['customer'] ?? $this->buildCustomer($request);

        $data = array_filter([
            'amount'             => $request->amount,
            'currency'           => $request->currency ?: 'USD',
            'callbackUrl'        => $request->webhookUrl,
            'successUrl'         => $request->successUrl,
            'failureUrl'         => $request->extra['failure_url'] ?? $request->successUrl,
            'customer'           => $customer,
            'paymentCurrency'    => $request->extra['payment_currency'] ?? null,
            'paymentGatewayName' => $request->extra['payment_gateway_name'] ?? null,
            'paymentMethod'      => $request->extra['payment_method'] ?? 'CRYPTO_AGENT',
        ], fn ($value) => $value !== null && $value !== '');

        PaymentLogger::info('checkout.initiated', [
            'invoice_id'          => $request->invoiceId,
            'amount'              => $data['amount'],
            'currency'            => $data['currency'],
            'payment_currency'    => $data['paymentCurrency'] ?? null,
            'payment_gateway'     => $data['paymentGatewayName'] ?? null,
        ], gateway: 'match2pay', category: 'checkout');

        $response = $this->deposit()->create($data, $this->apiToken, $this->apiSecret);

        $checkoutUrl = (string) ($response['checkoutUrl'] ?? '');
        $paymentId   = (string) ($response['paymentId'] ?? '');

        if ($checkoutUrl === '') {
            PaymentLogger::error('checkout.empty_url', [
                'invoice_id' => $request->invoiceId,
                'response'   => $response,
            ], gateway: 'match2pay', category: 'checkout');

            throw new PaymentException(
                message: 'Match2Pay response missing checkoutUrl.',
                gateway: $this->name(),
                raw: $response,
            );
        }

        PaymentLogger::info('checkout.success', [
            'invoice_id'  => $request->invoiceId,
            'payment_id'  => $paymentId,
            'status'      => $response['status'] ?? null,
            'expiration'  => $response['expiration'] ?? null,
        ], gateway: 'match2pay', category: 'checkout');

        return new CheckoutResult(
            redirectUrl:   $checkoutUrl,
            transactionId: $paymentId,
            gateway:       $this->name(),
            raw:           $response,
        );
    }

    // ── Webhooks ───────────────────────────────────────────────────────────

    /**
     * Parse a Match2Pay callback payload into a standardized WebhookResult.
     *
     * Match2Pay sends two callbacks per transaction:
     *   1. PENDING — transaction found on blockchain (do NOT credit yet)
     *   2. DONE    — funds confirmed and booked (safe to credit)
     *
     * Use finalAmount + finalCurrency for crediting (these are in your account currency).
     * Do NOT use transactionAmount for crediting — it is the raw crypto amount.
     *
     * Status mapping:
     *   DONE                        → PaymentStatus::PAID
     *   DECLINED, FAIL, SUSPECTED   → PaymentStatus::FAILED
     *   PARTIALLY_PAID              → PaymentStatus::FAILED (requires additional payment)
     *   everything else             → PaymentStatus::PENDING
     */
    public function parseWebhook(array $payload): WebhookResult
    {
        $status        = $this->mapStatus($payload['status'] ?? '');
        $paymentId     = (string) ($payload['paymentId'] ?? '');
        $finalAmount   = (float) ($payload['finalAmount'] ?? 0);
        $finalCurrency = (string) ($payload['finalCurrency'] ?? 'USD');

        PaymentLogger::info('webhook.parsed', [
            'payment_id'           => $paymentId,
            'status'               => $payload['status'] ?? null,
            'mapped_status'        => $status->value,
            'transaction_amount'   => $payload['transactionAmount'] ?? null,
            'transaction_currency' => $payload['transactionCurrency'] ?? null,
            'final_amount'         => $finalAmount,
            'final_currency'       => $finalCurrency,
        ], gateway: 'match2pay', category: 'webhook');

        return new WebhookResult(
            status:        $status,
            invoiceId:     $paymentId,
            transactionId: (string) ($payload['cryptoTransactionInfo'][0]['txid'] ?? $paymentId),
            gateway:       $this->name(),
            amount:        $finalAmount,
            currency:      $finalCurrency,
            metadata:      [
                'deposit_address'        => $payload['depositAddress'] ?? null,
                'transaction_amount'     => $payload['transactionAmount'] ?? null,
                'transaction_currency'   => $payload['transactionCurrency'] ?? null,
                'net_amount'             => $payload['netAmount'] ?? null,
                'processing_fee'         => $payload['processingFee'] ?? null,
                'conversion_rate'        => $payload['conversionRate'] ?? null,
                'crypto_transaction_info' => $payload['cryptoTransactionInfo'] ?? null,
            ],
            raw: $payload,
        );
    }

    /**
     * Verify the Match2Pay callback signature.
     *
     * Per docs: ONLY verify for status = "DONE". For all other statuses
     * the signature is not present and verification should be skipped.
     *
     * The signature is delivered in HTTP headers, not the body.
     * Pass all request headers as $headers — this method looks for the
     * signature under common header names (case-insensitive).
     *
     * @param  array<string, mixed>   $payload  Parsed callback body
     * @param  array<string, string>  $headers  All request headers
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        if (empty($this->apiSecret)) {
            PaymentLogger::warning('webhook.verification_skipped', [
                'reason' => 'secret not configured',
            ], gateway: 'match2pay', category: 'webhook');

            return true;
        }

        $status = strtoupper((string) ($payload['status'] ?? ''));

        // Per docs: only verify signature for DONE status
        if ($status !== 'DONE') {
            return true;
        }

        $signature = $this->extractSignatureFromHeaders($headers);

        if ($signature === '') {
            PaymentLogger::warning('webhook.missing_signature', [
                'payment_id' => $payload['paymentId'] ?? null,
            ], gateway: 'match2pay', category: 'webhook');

            return false;
        }

        $valid = SignatureService::verifyCallbackSignature(
            transactionAmount:  (string) ($payload['transactionAmount'] ?? '0'),
            transactionCurrency: (string) ($payload['transactionCurrency'] ?? ''),
            status:             $status,
            apiToken:           $this->apiToken,
            apiSecret:          $this->apiSecret,
            receivedSignature:  $signature,
        );

        if (! $valid) {
            PaymentLogger::warning('webhook.signature_failed', [
                'payment_id' => $payload['paymentId'] ?? null,
            ], gateway: 'match2pay', category: 'webhook');
        }

        return $valid;
    }

    // ── Service Accessors ──────────────────────────────────────────────────

    /**
     * Access the Deposit service for direct API calls.
     */
    public function deposit(): DepositService
    {
        return $this->depositService ??= new DepositService($this->client);
    }

    /**
     * Access the Withdrawal service for direct API calls.
     */
    public function withdrawal(): WithdrawalService
    {
        return $this->withdrawalService ??= new WithdrawalService($this->client);
    }

    /**
     * Access the underlying HTTP client.
     */
    public function client(): Match2PayClient
    {
        return $this->client;
    }

    // ── Internal Helpers ───────────────────────────────────────────────────

    /**
     * Build a minimal customer object from a CheckoutRequest.
     *
     * Used when extra['customer'] is not provided. For production use,
     * always pass a full customer object via extra['customer'] to include
     * real customer data (name, address, phone) as required by Match2Pay.
     *
     * @return array<string, mixed>
     */
    protected function buildCustomer(CheckoutRequest $request): array
    {
        $nameParts = explode(' ', trim($request->customerName), 2);

        return [
            'firstName' => $nameParts[0] ?? 'Customer',
            'lastName'  => $nameParts[1] ?? '',
            'address'   => [
                'address' => '',
                'city'    => '',
                'country' => '',
                'zipCode' => '',
                'state'   => '',
            ],
            'contactInformation' => [
                'email'       => $request->customerEmail,
                'phoneNumber' => '',
            ],
            'locale'               => 'en_US',
            'dateOfBirth'          => '',
            'tradingAccountLogin'  => $request->extra['trading_account_login'] ?? $request->customerEmail,
            'tradingAccountUuid'   => $request->extra['trading_account_uuid'] ?? $request->invoiceId,
        ];
    }

    /**
     * Extract the callback signature from request headers.
     *
     * Match2Pay may use different header names depending on configuration.
     * We check common variants case-insensitively.
     *
     * @param  array<string, string|array>  $headers
     */
    protected function extractSignatureFromHeaders(array $headers): string
    {
        $candidates = ['x-signature', 'signature', 'x-api-signature', 'x-callback-signature'];

        // Normalize header keys to lowercase for lookup
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = is_array($value) ? ($value[0] ?? '') : (string) $value;
        }

        foreach ($candidates as $candidate) {
            if (isset($normalized[$candidate]) && $normalized[$candidate] !== '') {
                return $normalized[$candidate];
            }
        }

        return '';
    }

    protected function mapStatus(string $status): PaymentStatus
    {
        return match (strtoupper($status)) {
            'DONE'                      => PaymentStatus::PAID,
            'DECLINED', 'FAIL',
            'SUSPECTED', 'PARTIALLY PAID' => PaymentStatus::FAILED,
            'CANCELLED', 'CANCELED'     => PaymentStatus::CANCELLED,
            default                     => PaymentStatus::PENDING,
        };
    }
}
