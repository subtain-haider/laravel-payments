<?php

namespace Subtain\LaravelPayments\Gateways;

use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Exceptions\PaymentException;
use Subtain\LaravelPayments\Gateways\Whop\WhopClient;
use Subtain\LaravelPayments\PaymentLogger;

/**
 * Whop payment gateway — one-time and subscription payments.
 *
 * Checkout flow:
 *   1. Calls POST /v5/checkout-configurations with an inline plan definition.
 *   2. Returns a purchase_url that the customer is redirected to.
 *   3. Whop handles the payment page, 3DS, and subscription billing.
 *
 * Plan types (set via extra['plan_type']):
 *   'one_time' — single charge (default)
 *   'renewal'  — recurring subscription; requires extra['billing_period'] in days
 *
 * Webhook signature scheme — Standard Webhooks (https://www.standardwebhooks.com):
 *   Sign string: "{webhook-id}.{webhook-timestamp}.{raw_body}"
 *   Algorithm:   HMAC-SHA256, keyed with the base64-decoded webhook secret
 *   Header:      webhook-signature: v1,<base64_digest>
 *
 * Config (config/lp_payments.php -> gateways.whop):
 *   api_key, webhook_secret, company_id, base_url, timeout, retries
 *
 * @see https://docs.whop.com/developer/guides/accept-payments
 * @see https://docs.whop.com/developer/guides/webhooks
 */
class WhopGateway implements PaymentGateway
{
    protected WhopClient $client;
    protected string $webhookSecret;
    protected string $companyId;

    public function __construct(array $config = [])
    {
        $this->client        = new WhopClient($config);
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        $this->companyId     = $config['company_id'] ?? '';
    }

    // ---- Contract -------------------------------------------------------

    /**
     * Return the unique gateway name used throughout the package.
     */
    public function name(): string
    {
        return 'whop';
    }

    /**
     * Create a Whop checkout configuration and return the purchase URL.
     *
     * Supports one-time payments and recurring subscriptions.
     * Pass extra['plan_id'] to reference an existing Whop plan instead of
     * creating one inline.
     *
     * @throws PaymentException
     */
    public function checkout(CheckoutRequest $request): CheckoutResult
    {
        if (! empty($request->extra['plan_id'])) {
            return $this->checkoutWithExistingPlan($request);
        }

        return $this->checkoutWithInlinePlan($request);
    }

    /**
     * Parse an incoming Whop webhook into a standardised WebhookResult.
     *
     * Whop uses the Standard Webhooks envelope format:
     *   { "id": "msg_xxx", "type": "payment.succeeded", "data": { ... } }
     *
     * Subscription lifecycle events (membership.activated / membership.deactivated)
     * are also handled so your app can react to subscription state changes via
     * the same PaymentSucceeded / PaymentFailed event pipeline.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(array $payload): WebhookResult
    {
        $type = $payload['type'] ?? '';
        $data = $payload['data'] ?? $payload;

        $status        = $this->resolveStatus($type, $data);
        $invoiceId     = $this->extractInvoiceId($data);
        $transactionId = $data['id'] ?? $payload['id'] ?? '';
        $amount        = (float) ($data['total'] ?? $data['usd_total'] ?? 0);
        $currency      = strtolower($data['currency'] ?? 'usd');

        PaymentLogger::debug('webhook.parsed', [
            'type'           => $type,
            'invoice_id'     => $invoiceId,
            'transaction_id' => $transactionId,
            'status'         => $status->value,
        ], gateway: 'whop', category: 'webhook');

        return new WebhookResult(
            status:        $status,
            invoiceId:     $invoiceId,
            transactionId: $transactionId,
            gateway:       $this->name(),
            amount:        $amount,
            currency:      $currency,
            metadata:      $data['metadata'] ?? [],
            raw:           $payload,
        );
    }

    /**
     * Verify an incoming Whop webhook signature (Standard Webhooks spec).
     *
     * IMPORTANT: For reliable verification always use verifyWebhookSignature()
     * and pass the raw request body string. This method re-serialises the parsed
     * array which may produce a different byte sequence than the original body.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        if (empty($this->webhookSecret)) {
            return true;
        }

        $rawBody = json_encode($payload);

        if ($rawBody === false) {
            return false;
        }

        return $this->verifyStandardWebhookSignature($rawBody, $headers);
    }

    /**
     * Verify webhook using the raw request body (recommended).
     *
     * Use this in your controller:
     *   $gateway->verifyWebhookSignature($request->getContent(), $request->headers->all())
     *
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhookSignature(string $rawBody, array $headers = []): bool
    {
        if (empty($this->webhookSecret)) {
            return true;
        }

        return $this->verifyStandardWebhookSignature($rawBody, $headers);
    }

    // ---- Checkout modes -------------------------------------------------

    /**
     * Create a checkout configuration with an inline plan (most common path).
     *
     * Whop creates both the plan and the checkout session in one API call.
     * Returns the purchase_url (Whop-hosted checkout page).
     */
    protected function checkoutWithInlinePlan(CheckoutRequest $request): CheckoutResult
    {
        $planType = $request->extra['plan_type'] ?? 'one_time';

        $plan = array_filter([
            'company_id'        => $this->companyId,
            'plan_type'         => $planType,
            'initial_price'     => $request->amount,
            'currency'          => strtolower($request->currency),
            'billing_period'    => $request->extra['billing_period'] ?? null,
            'renewal_price'     => $request->extra['renewal_price'] ?? null,
            'trial_period_days' => $request->extra['trial_period_days'] ?? null,
            'access_pass_id'    => $request->extra['access_pass_id'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $body = array_filter([
            'plan'              => $plan,
            'metadata'          => array_merge($request->metadata, ['invoice_id' => $request->invoiceId]),
            'redirect_url'      => $request->successUrl ?: null,
            'allow_promo_codes' => $request->extra['allow_promo_codes'] ?? null,
            'affiliate_code'    => $request->extra['affiliate_code'] ?? null,
        ], static fn ($v) => $v !== null);

        PaymentLogger::debug('checkout.initiated', [
            'invoice_id' => $request->invoiceId,
            'amount'     => $request->amount,
            'currency'   => $request->currency,
            'plan_type'  => $planType,
        ], gateway: 'whop', category: 'checkout');

        $data = $this->client->post('checkout-configurations', $body);

        $purchaseUrl   = $data['purchase_url'] ?? '';
        $transactionId = $data['id'] ?? '';

        PaymentLogger::info('checkout.success', [
            'invoice_id'     => $request->invoiceId,
            'transaction_id' => $transactionId,
            'redirect_url'   => $purchaseUrl,
            'plan_type'      => $planType,
        ], gateway: 'whop', category: 'checkout');

        return new CheckoutResult(
            redirectUrl:   $purchaseUrl,
            transactionId: $transactionId,
            gateway:       $this->name(),
            raw:           $data,
        );
    }

    /**
     * Create a checkout configuration referencing an existing Whop plan.
     *
     * Use this when you manage plans in the Whop dashboard and only need
     * a fresh checkout session pointing at a specific plan.
     */
    protected function checkoutWithExistingPlan(CheckoutRequest $request): CheckoutResult
    {
        $planId = $request->extra['plan_id'];

        $body = array_filter([
            'plan_id'           => $planId,
            'metadata'          => array_merge($request->metadata, ['invoice_id' => $request->invoiceId]),
            'redirect_url'      => $request->successUrl ?: null,
            'allow_promo_codes' => $request->extra['allow_promo_codes'] ?? null,
            'affiliate_code'    => $request->extra['affiliate_code'] ?? null,
        ], static fn ($v) => $v !== null);

        PaymentLogger::debug('checkout.initiated', [
            'invoice_id' => $request->invoiceId,
            'plan_id'    => $planId,
        ], gateway: 'whop', category: 'checkout');

        $data = $this->client->post('checkout-configurations', $body);

        $purchaseUrl   = $data['purchase_url'] ?? '';
        $transactionId = $data['id'] ?? '';

        PaymentLogger::info('checkout.success', [
            'invoice_id'     => $request->invoiceId,
            'transaction_id' => $transactionId,
            'redirect_url'   => $purchaseUrl,
            'plan_id'        => $planId,
        ], gateway: 'whop', category: 'checkout');

        return new CheckoutResult(
            redirectUrl:   $purchaseUrl,
            transactionId: $transactionId,
            gateway:       $this->name(),
            raw:           $data,
        );
    }

    // ---- Standard Webhooks signature verification -----------------------

    /**
     * Verify a webhook signature per the Standard Webhooks specification.
     *
     * Steps:
     *   1. Decode the webhook secret from base64 (Whop stores it base64-encoded).
     *   2. Build signing string: "{webhook-id}.{webhook-timestamp}.{raw_body}"
     *   3. HMAC-SHA256 the signing string with the decoded secret key.
     *   4. Base64-encode the digest and compare against each "v1,<b64>" entry
     *      in the space-separated `webhook-signature` header.
     *
     * Replays are prevented by rejecting timestamps older than 5 minutes.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function verifyStandardWebhookSignature(string $rawBody, array $headers): bool
    {
        $msgId        = $this->extractHeader($headers, 'webhook-id') ?? '';
        $msgTimestamp = $this->extractHeader($headers, 'webhook-timestamp') ?? '';
        $msgSignature = $this->extractHeader($headers, 'webhook-signature') ?? '';

        if ($msgId === '' || $msgTimestamp === '' || $msgSignature === '') {
            PaymentLogger::warning('webhook.signature_missing_headers', [
                'has_id'        => $msgId !== '',
                'has_timestamp' => $msgTimestamp !== '',
                'has_signature' => $msgSignature !== '',
            ], gateway: 'whop', category: 'webhook');

            return false;
        }

        if (! $this->isTimestampFresh($msgTimestamp)) {
            PaymentLogger::warning('webhook.stale_timestamp', [
                'timestamp' => $msgTimestamp,
            ], gateway: 'whop', category: 'webhook');

            return false;
        }

        // Decode the base64-encoded secret; fall back to raw bytes if not valid base64.
        $secretBytes = base64_decode($this->webhookSecret, strict: true);

        if ($secretBytes === false) {
            $secretBytes = $this->webhookSecret;
        }

        $toSign = $msgId . '.' . $msgTimestamp . '.' . $rawBody;
        $digest = base64_encode(hash_hmac('sha256', $toSign, $secretBytes, binary: true));

        // Header may carry multiple space-separated signatures (key rotation support).
        $expectedSignatures = explode(' ', $msgSignature);

        foreach ($expectedSignatures as $entry) {
            $parts = explode(',', $entry, 2);

            if (count($parts) !== 2 || $parts[0] !== 'v1') {
                continue;
            }

            if (hash_equals($digest, $parts[1])) {
                return true;
            }
        }

        PaymentLogger::warning('webhook.signature_mismatch', [
            'msg_id' => $msgId,
        ], gateway: 'whop', category: 'webhook');

        return false;
    }

    /**
     * Check that the webhook timestamp is within 5 minutes of the current time.
     *
     * Standard Webhooks timestamps are Unix epoch seconds as a decimal string.
     */
    protected function isTimestampFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= 300;
    }

    // ---- Status resolution ----------------------------------------------

    /**
     * Map a Whop webhook event type to a PaymentStatus enum value.
     *
     * Payment events:
     *   payment.succeeded                      -> PAID
     *   payment.failed                         -> FAILED   (card declined, all retries exhausted)
     *   payment.pending                        -> PENDING  (processing, do not fulfil yet)
     *   payment.refunded                       -> REFUNDED
     *
     * Subscription / membership lifecycle events:
     *   membership.activated                   -> PAID
     *     Fired when: initial subscription starts, each successful renewal cycle.
     *     Action: grant / extend access.
     *
     *   membership.deactivated                 -> CANCELLED
     *     Fired when: ALL of the following scenarios:
     *       - User cancelled and the billing period has now ended
     *       - Payment failed and all Whop retry attempts were exhausted
     *       - Admin revoked the membership
     *       - Membership expired (fixed-term plan ended)
     *     Action: revoke access.
     *
     *   membership.cancel_at_period_end_changed -> PENDING
     *     Fired when: user schedules a future cancellation (cancel_at_period_end = true)
     *     OR un-cancels (cancel_at_period_end = false).
     *     The membership is STILL ACTIVE — do NOT revoke access yet.
     *     Check data.cancel_at_period_end to distinguish schedule vs. un-cancel.
     *     Action: update UI to warn user their subscription ends on renewal_period_end.
     *     Access is revoked later when membership.deactivated fires.
     *
     * Dispute / refund events:
     *   dispute.created                        -> FAILED   (chargeback opened)
     *   refund.created                         -> REFUNDED
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveStatus(string $type, array $data): PaymentStatus
    {
        return match ($type) {
            'payment.succeeded'                     => PaymentStatus::PAID,
            'payment.failed'                        => PaymentStatus::FAILED,
            'payment.pending'                       => PaymentStatus::PENDING,
            'payment.refunded',
            'refund.created'                        => PaymentStatus::REFUNDED,
            'membership.activated'                  => PaymentStatus::PAID,
            'membership.deactivated'                => PaymentStatus::CANCELLED,
            'membership.cancel_at_period_end_changed' => PaymentStatus::PENDING,
            'dispute.created'                       => PaymentStatus::FAILED,
            default                                 => $this->mapStatusField($data['status'] ?? $data['substatus'] ?? ''),
        };
    }

    /**
     * Map a Whop payment status / substatus string to a PaymentStatus enum value.
     *
     * Used as a fallback when the event type is unknown or the payload is
     * a raw payment object rather than a typed webhook envelope.
     */
    protected function mapStatusField(string $status): PaymentStatus
    {
        return match (strtolower($status)) {
            'succeeded', 'paid', 'completed' => PaymentStatus::PAID,
            'failed', 'error'                => PaymentStatus::FAILED,
            'cancelled', 'canceled'          => PaymentStatus::CANCELLED,
            'refunded', 'auto_refunded'       => PaymentStatus::REFUNDED,
            'pending', 'processing'           => PaymentStatus::PENDING,
            default                           => PaymentStatus::PENDING,
        };
    }

    // ---- Helpers --------------------------------------------------------

    /**
     * Extract the invoice_id from the webhook data payload.
     *
     * The invoice ID is stored in data.metadata.invoice_id, which is the
     * key populated when creating the checkout configuration. The checkout
     * configuration ID (ch_xxx) is also available as a fallback via
     * data.checkout_configuration_id.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractInvoiceId(array $data): string
    {
        $metadata = $data['metadata'] ?? [];

        if (is_string($metadata)) {
            $decoded  = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return (string) ($metadata['invoice_id']
            ?? $data['checkout_configuration_id']
            ?? '');
    }

    /**
     * Extract a header value from a headers array, handling nested arrays.
     *
     * Laravel passes headers as arrays (e.g. ['content-type' => ['application/json']]).
     * Whop Standard Webhooks headers use lowercase names.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function extractHeader(array $headers, string $key): ?string
    {
        $value = $headers[$key] ?? $headers[strtolower($key)] ?? null;

        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return is_string($value) ? $value : null;
    }

    // ---- Direct client access -------------------------------------------

    /**
     * Access the underlying WhopClient for direct API calls.
     */
    public function client(): WhopClient
    {
        return $this->client;
    }
}
