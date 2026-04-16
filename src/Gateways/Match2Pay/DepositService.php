<?php

namespace Subtain\LaravelPayments\Gateways\Match2Pay;

/**
 * Match2Pay Deposit (Pay-In) API.
 *
 * Creates a new crypto deposit transaction and returns a checkout URL
 * to redirect the customer to the Match2Pay payment page.
 *
 * Two flows are supported:
 *   - Dynamic (single-use): new wallet address per deposit. Recommended.
 *     Omit paymentCurrency/paymentGatewayName for a 2-step selection page,
 *     or provide them to skip directly to payment details.
 *   - Static (fixed wallet): same address reused per client/email.
 *     Enable via "fixed wallets" on the gateway config in Match2Pay dashboard.
 *
 * The customer object must be provided in the exact key order specified
 * by the docs — this is enforced by SignatureService::formatCustomer().
 *
 * @see https://docs.match2pay.com — Deposit Request section
 */
class DepositService
{
    public function __construct(
        protected Match2PayClient $client,
    ) {}

    /**
     * Create a deposit transaction.
     *
     * @param  array{
     *     amount: float|int,
     *     currency: string,
     *     callbackUrl: string,
     *     successUrl: string,
     *     failureUrl: string,
     *     customer: array,
     *     paymentCurrency?: string,
     *     paymentGatewayName?: string,
     *     paymentMethod?: string,
     * }  $data
     *
     * @return array{
     *     address: string,
     *     paymentId: string,
     *     status: string,
     *     checkoutUrl: string,
     *     finalAmount: float,
     *     finalCurrency: string,
     *     expiration: string,
     * }
     */
    public function create(array $data, string $apiToken, string $apiSecret): array
    {
        $payload = array_filter([
            'amount'             => $data['amount'],
            'apiToken'           => $apiToken,
            'callbackUrl'        => $data['callbackUrl'],
            'currency'           => $data['currency'] ?? 'USD',
            'customer'           => $data['customer'],
            'failureUrl'         => $data['failureUrl'] ?? '',
            'paymentCurrency'    => $data['paymentCurrency'] ?? null,
            'paymentGatewayName' => $data['paymentGatewayName'] ?? null,
            'paymentMethod'      => $data['paymentMethod'] ?? 'CRYPTO_AGENT',
            'successUrl'         => $data['successUrl'] ?? '',
            'timestamp'          => (string) time(),
        ], fn ($value) => $value !== null && $value !== '');

        $payload['signature'] = SignatureService::buildRequestSignature($payload, $apiSecret);

        return $this->client->post('payment/deposit', $payload);
    }
}
