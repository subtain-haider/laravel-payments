<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Checkout Sessions API.
 *
 * Supports 3 checkout modes:
 * 1. Dynamic — create a checkout session via API (one-time or subscription)
 * 2. Embedded — create an embedded checkout session for iframes
 * 3. Static — use a pre-configured payment link (no API call)
 *
 * @see https://apidocs.fan — Checkout Sessions
 */
class CheckoutSessionsService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * Create a checkout session (dynamic link).
     *
     * Returns: { id, checkout_session_id, payment_link }
     *
     * @param  array{
     *     product: array{title: string, description?: string},
     *     amount_cents: int,
     *     type: string,
     *     application_fee?: int,
     *     metadata?: array<string, mixed>,
     *     expiration_date?: string,
     *     subscription?: array{
     *         frequency_days: int,
     *         auto_expire_after_x_periods?: int|null,
     *         free_trial_days?: int,
     *         initial_fee?: int,
     *         initial_fee_days?: int,
     *     },
     *     success_url?: string,
     *     webhook_url?: string,
     *     discount_code?: string,
     *     allow_discount_codes?: bool,
     * }  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return $this->client->post('checkout-sessions', $data);
    }

    /**
     * Look up a checkout session by its ID.
     *
     * @return array<string, mixed>
     */
    public function find(string $checkoutSessionId): array
    {
        return $this->client->get("checkout-sessions/{$checkoutSessionId}");
    }

    /**
     * Delete a checkout session (deactivates the payment link).
     *
     * @return array<string, mixed>
     */
    public function delete(string $checkoutSessionId): array
    {
        return $this->client->delete("checkout-sessions/{$checkoutSessionId}");
    }

    /**
     * Get transactions for a checkout session.
     *
     * @param  array{page?: int, per_page?: int}  $query
     * @return array<string, mixed>
     */
    public function transactions(string $checkoutSessionId, array $query = []): array
    {
        return $this->client->get("checkout-sessions/{$checkoutSessionId}/transactions", $query);
    }

    /**
     * Create an embedded checkout session.
     *
     * Returns: { checkout_session_secret, created_at }
     * Use the secret to construct: https://embedded.fanbasis.io/session/{handle}/{product_id}/{secret}
     *
     * @param  array{product_id: string, metadata?: array<string, mixed>}  $data
     * @return array<string, mixed>
     */
    public function createEmbedded(array $data): array
    {
        return $this->client->post('checkout-sessions/embedded', $data);
    }

    /**
     * Build the embedded checkout URL from a secret.
     */
    public function buildEmbeddedUrl(string $creatorHandle, string $productId, string $secret): string
    {
        return "https://embedded.fanbasis.io/session/{$creatorHandle}/{$productId}/{$secret}";
    }

    /**
     * Get subscriptions for a checkout session.
     *
     * @param  array{page?: int, per_page?: int}  $query
     * @return array<string, mixed>
     */
    public function subscriptions(string $checkoutSessionId, array $query = []): array
    {
        return $this->client->get("checkout-sessions/{$checkoutSessionId}/subscriptions", $query);
    }

    /**
     * Cancel a subscription for a checkout session.
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(string $checkoutSessionId, string $subscriptionId): array
    {
        return $this->client->delete("checkout-sessions/{$checkoutSessionId}/subscriptions/{$subscriptionId}");
    }

    /**
     * Extend a subscription for a checkout session.
     *
     * @param  array{user_id: string, duration_days: int}  $data
     * @return array<string, mixed>
     */
    public function extendSubscription(string $checkoutSessionId, array $data): array
    {
        return $this->client->post("checkout-sessions/{$checkoutSessionId}/extend-subscription", $data);
    }

    /**
     * Refund a transaction.
     *
     * For full refund: omit amount_cents. For partial: include amount_cents.
     *
     * @param  array{amount_cents?: int}  $data
     * @return array<string, mixed>
     */
    public function refundTransaction(string $transactionId, array $data = []): array
    {
        return $this->client->post("checkout-sessions/transactions/{$transactionId}/refund", $data);
    }
}
