<?php

namespace Subtain\LaravelPayments\Gateways\Fanbasis;

/**
 * Fanbasis Refunds API.
 *
 * Issue full or partial refunds for successful payments.
 * Refunds are processed back to the original payment method.
 *
 * Rules:
 * - Transaction must have status "succeeded"
 * - Refund amount cannot exceed the paid amount
 * - A payment can only be refunded once (full) or partially multiple times up to original amount
 *
 * @see https://apidocs.fan — Refunds
 */
class RefundsService
{
    public function __construct(
        protected FanbasisClient $client,
    ) {}

    /**
     * Create a refund for a transaction.
     *
     * For full refund: omit amount or pass 0.
     * For partial refund: specify amount in cents.
     *
     * @param  array{amount?: int, reason?: string}  $data
     * @return array<string, mixed>
     */
    public function create(string $transactionId, array $data = []): array
    {
        return $this->client->post("checkout-sessions/{$transactionId}/refund", $data);
    }
}
