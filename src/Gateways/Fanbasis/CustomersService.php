<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Customers API.
 *
 * List customers, view saved payment methods, and charge customers directly.
 *
 * @see https://apidocs.fan — Customers
 */
class CustomersService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * List all customers (paginated, searchable).
     *
     * @param  array{search?: string, page?: int, per_page?: int}  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->get('customers', $query);
    }

    /**
     * Get a customer's saved payment methods.
     *
     * @return array<string, mixed>
     */
    public function paymentMethods(string $customerId): array
    {
        return $this->client->get("customers/{$customerId}/payment-methods");
    }

    /**
     * Charge a customer directly using a saved payment method.
     *
     * @param  array{
     *     payment_method_id: string,
     *     service_id: string,
     *     amount_cents: int,
     *     description?: string,
     *     metadata?: array<string, mixed>,
     * }  $data
     * @return array<string, mixed>
     */
    public function charge(string $customerId, array $data): array
    {
        return $this->client->post("customers/{$customerId}/charge", $data);
    }
}
