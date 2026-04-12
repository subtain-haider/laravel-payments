<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Transactions API.
 *
 * Look up individual transactions or list all transactions
 * with filters by product or customer.
 *
 * @see https://apidocs.fan — Transactions
 */
class TransactionsService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * Look up a single transaction by ID.
     *
     * @return array<string, mixed>
     */
    public function find(string $transactionId): array
    {
        return $this->client->get("transactions/{$transactionId}");
    }

    /**
     * Get all transactions (paginated, filterable).
     *
     * @param  array{
     *     product_id?: string,
     *     customer_id?: string,
     *     page?: int,
     *     per_page?: int,
     * }  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->get('checkout-sessions/transactions', $query);
    }
}
