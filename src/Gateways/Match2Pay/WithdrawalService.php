<?php

namespace Subtain\LaravelPayments\Gateways\Match2Pay;

/**
 * Match2Pay Withdrawal (Pay-Out) API.
 *
 * Initiates a crypto withdrawal to a client's external wallet address.
 *
 * Withdrawal statuses:
 *   NEW              — Initiated
 *   ADMIN CONFIRMATION — Awaiting manual confirmation by Match2Pay support
 *   PENDING          — Processing on-chain
 *   DECLINED         — Failed (not final — can be reprocessed within same paymentID)
 *   DONE             — Completed
 *   FAIL             — Failed (final)
 *
 * TON withdrawals with memo: use "walletAddress;memo" format in cryptoAddress.
 *
 * @see https://docs.match2pay.com — Withdrawal Request section
 */
class WithdrawalService
{
    public function __construct(
        protected Match2PayClient $client,
    ) {}

    /**
     * Create a withdrawal transaction.
     *
     * @param  array{
     *     amount: float|int,
     *     currency: string,
     *     cryptoAddress: string,
     *     callbackUrl: string,
     *     successUrl: string,
     *     failureUrl: string,
     *     customer: array,
     *     paymentCurrency: string,
     *     paymentGatewayName: string,
     *     paymentMethod?: string,
     * }  $data
     *
     * @return array{
     *     paymentId: string,
     *     status: string,
     *     finalAmount: float,
     *     finalCurrency: string,
     *     address: string,
     *     tempTransactionId: string,
     * }
     */
    public function create(array $data, string $apiToken, string $apiSecret): array
    {
        $payload = array_filter([
            'amount'             => $data['amount'],
            'apiToken'           => $apiToken,
            'callbackUrl'        => $data['callbackUrl'],
            'cryptoAddress'      => $data['cryptoAddress'],
            'currency'           => $data['currency'] ?? 'USD',
            'customer'           => $data['customer'],
            'failureUrl'         => $data['failureUrl'] ?? '',
            'paymentCurrency'    => $data['paymentCurrency'],
            'paymentGatewayName' => $data['paymentGatewayName'],
            'paymentMethod'      => $data['paymentMethod'] ?? 'CRYPTO_AGENT',
            'successUrl'         => $data['successUrl'] ?? '',
            'timestamp'          => (string) time(),
        ], fn ($value) => $value !== null && $value !== '');

        $payload['signature'] = SignatureService::buildRequestSignature($payload, $apiSecret);

        return $this->client->post('payment/withdrawal', $payload);
    }
}
