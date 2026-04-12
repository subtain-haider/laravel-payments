<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Products API.
 *
 * Products (called "services" in parts of the Fanbasis API) are items
 * you've set up to sell. Each product has its own payment link.
 *
 * @see https://apidocs.fan — Products
 */
class ProductsService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * List all products (paginated).
     *
     * @param  array{page?: int, per_page?: int}  $query
     * @return array<string, mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->get('products', $query);
    }
}
