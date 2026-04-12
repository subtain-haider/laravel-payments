<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Discount Codes API.
 *
 * Full CRUD for discount codes: percentage or fixed amount,
 * with duration, expiry, redemption limits, and product scoping.
 *
 * @see https://apidocs.fan — Discount Codes
 */
class DiscountCodesService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * List all discount codes (paginated, searchable).
     *
     * @param  array{search?: string, per_page?: int}  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->get('discount-codes', $query);
    }

    /**
     * Create a new discount code.
     *
     * @param  array{
     *     code: string,
     *     description?: string,
     *     discount_type: string,
     *     value: int|float,
     *     duration: string,
     *     no_of_months?: int,
     *     expiry?: string,
     *     limited_redemptions?: bool,
     *     usable_number?: int,
     *     one_time?: bool,
     *     service_ids?: array<int>,
     * }  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return $this->client->post('discount-codes', $data);
    }

    /**
     * Get a single discount code by ID.
     *
     * @return array<string, mixed>
     */
    public function find(int|string $id): array
    {
        return $this->client->get("discount-codes/{$id}");
    }

    /**
     * Update a discount code (partial update supported).
     *
     * @param  array<string, mixed>  $data  Only include fields to change.
     * @return array<string, mixed>
     */
    public function update(int|string $id, array $data): array
    {
        return $this->client->put("discount-codes/{$id}", $data);
    }

    /**
     * Delete a discount code.
     *
     * Existing subscriptions using this code keep their discount.
     *
     * @return array<string, mixed>
     */
    public function delete(int|string $id): array
    {
        return $this->client->delete("discount-codes/{$id}");
    }
}
