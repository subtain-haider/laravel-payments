<?php

namespace Subtain\LaravelPayments\Gateways\Rebornpay;

/**
 * Rebornpay Transaction Status API.
 *
 * Provides methods to check the current status of a pay-in transaction
 * and to store a UTR (Unique Transaction Reference) for manual verification.
 *
 * Transaction status reference:
 *   - "activated"     — Final. Payment confirmed. Safe to credit the user.
 *   - "fake"          — Final. Fraudulent transaction. Do NOT credit the user.
 *   - "non_activated" — Pending. Payment received but not yet verified.
 *   - "non_paid"      — Pending. Awaiting customer payment.
 *
 * Use webhooks (postbacks) for real-time notifications instead of polling.
 *
 * @see https://prod.api.rbpcloud.pro — Transaction Status API
 * @see https://prod.api.rbpcloud.pro — UTR Entry API
 */
class TransactionService
{
    public function __construct(
        protected RebornpayClient $client,
    ) {}

    /**
     * Check the status of a transaction by system transaction ID.
     *
     * @return array{
     *     status: string,
     *     transaction_id: string,
     *     amount: float,
     *     currency: string,
     *     creation_timestamp: int,
     *     add_timestamp: int,
     *     activation_timestamp: int|null,
     *     client_user: string,
     * }
     */
    public function checkByTransactionId(string $transactionId): array
    {
        return $this->client->get('api/v1/external/transactions/check', [
            'transaction_id' => $transactionId,
        ]);
    }

    /**
     * Check the status of a transaction by your own client transaction ID.
     *
     * Requires both client_id and client_transaction_id.
     *
     * @return array{
     *     status: string,
     *     transaction_id: string,
     *     amount: float,
     *     currency: string,
     *     creation_timestamp: int,
     *     add_timestamp: int,
     *     activation_timestamp: int|null,
     *     client_user: string,
     * }
     */
    public function checkByClientTransactionId(string $clientId, string $clientTransactionId): array
    {
        return $this->client->get('api/v1/external/transactions/check', [
            'client_id'             => $clientId,
            'client_transaction_id' => $clientTransactionId,
        ]);
    }

    /**
     * Check the status of a transaction by UTR (bank reference number).
     *
     * @return array{
     *     status: string,
     *     transaction_id: string,
     *     amount: float,
     *     currency: string,
     *     creation_timestamp: int,
     *     add_timestamp: int,
     *     activation_timestamp: int|null,
     *     client_user: string,
     * }
     */
    public function checkByUtr(string $utr): array
    {
        return $this->client->get('api/v1/external/transactions/check', [
            'UTR' => $utr,
        ]);
    }

    /**
     * Store a UTR for a transaction (identified by system transaction ID).
     *
     * Stores the UTR without activating the transaction. Rebornpay will
     * use it for automatic verification and matching.
     *
     * @return array{success: bool, message: string, transaction_id: string}
     */
    public function storeUtrByTransactionId(string $clientId, string $transactionId, string $utr): array
    {
        return $this->client->post('api/v1/external/transactions/utr', [
            'client_id'      => $clientId,
            'transaction_id' => $transactionId,
            'utr'            => $utr,
        ]);
    }

    /**
     * Store a UTR for a transaction (identified by your own client transaction ID).
     *
     * @return array{success: bool, message: string, transaction_id: string}
     */
    public function storeUtrByClientTransactionId(string $clientId, string $clientTransactionId, string $utr): array
    {
        return $this->client->post('api/v1/external/transactions/utr', [
            'client_id'             => $clientId,
            'client_transaction_id' => $clientTransactionId,
            'utr'                   => $utr,
        ]);
    }
}
