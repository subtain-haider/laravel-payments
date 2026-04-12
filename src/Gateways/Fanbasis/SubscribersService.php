<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Subscribers API.
 *
 * Unified view of who is subscribed to what across all products.
 *
 * @see https://apidocs.fan — Subscribers
 */
class SubscribersService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * List all subscribers (paginated, filterable).
     *
     * @param  array{
     *     product_id?: string,
     *     customer_id?: string,
     *     status?: string,
     *     page?: int,
     *     per_page?: int,
     * }  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->get('subscribers', $query);
    }

    /**
     * Get subscriptions for a checkout session.
     *
     * @param  array{page?: int, per_page?: int}  $query
     * @return array<string, mixed>
     */
    public function forCheckoutSession(string $checkoutSessionId, array $query = []): array
    {
        return $this->client->get("checkout-sessions/{$checkoutSessionId}/subscriptions", $query);
    }

    /**
     * Get subscriptions for a product.
     *
     * @param  array{page?: int, per_page?: int}  $query
     * @return array<string, mixed>
     */
    public function forProduct(string $productId, array $query = []): array
    {
        return $this->client->get("checkout-sessions/{$productId}/subscriptions", $query);
    }

    /**
     * Cancel a subscription.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $checkoutSessionId, string $subscriptionId): array
    {
        return $this->client->delete("checkout-sessions/{$checkoutSessionId}/subscriptions/{$subscriptionId}");
    }

    /**
     * Refund a transaction (full or partial).
     *
     * For full refund: pass empty $data. For partial: include amount_cents.
     *
     * @param  array{amount_cents?: int}  $data
     * @return array<string, mixed>
     */
    public function refundTransaction(string $transactionId, array $data = []): array
    {
        return $this->client->post("checkout-sessions/transactions/{$transactionId}/refund", $data);
    }

    /**
     * Extend a subscription.
     *
     * @param  array{user_id: string, duration_days: int}  $data
     * @return array<string, mixed>
     */
    public function extend(string $checkoutSessionId, array $data): array
    {
        return $this->client->post("checkout-sessions/{$checkoutSessionId}/extend-subscription", $data);
    }
}
