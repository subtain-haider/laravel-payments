<?php

namespace Subtain\LaravelPayments\Gateways\Rebornpay;

/**
 * Rebornpay Pay-In API.
 *
 * Creates a new pay-in (deposit) transaction and returns the
 * payment page URL to redirect the customer to.
 *
 * The "payment_option_name" field controls the payment method:
 *   - "UPI"  — UPI QR code flow (default)
 *   - "IMPS" — Bank transfer flow
 *
 * After creating a transaction, append "redirect_success_url" to
 * the returned "payment_page_url" to redirect the customer back
 * to your site after successful payment.
 *
 * @see https://prod.api.rbpcloud.pro — Pay-In API
 */
class PayinService
{
    public function __construct(
        protected RebornpayClient $client,
    ) {}

    /**
     * Create a new pay-in transaction.
     *
     * @param  array{
     *     amount: float,
     *     currency: string,
     *     client_user: string,
     *     client_id: string,
     *     client_transaction_id: string,
     *     payment_option_name?: string,
     * }  $data
     *
     * @return array{
     *     payin_id: string,
     *     transaction_id: string,
     *     qr_link: string|null,
     *     payment_details: array|null,
     *     payment_option_name: string,
     *     client_transaction_id: string,
     *     client_user: string,
     *     payment_page_url: string,
     *     expiry_time: int,
     * }
     */
    public function create(array $data): array
    {
        $payload = array_filter([
            'amount'                => $data['amount'],
            'currency'              => $data['currency'] ?? 'INR',
            'client_user'           => $data['client_user'],
            'client_id'             => $data['client_id'],
            'client_transaction_id' => $data['client_transaction_id'],
            'payment_option_name'   => $data['payment_option_name'] ?? 'UPI',
        ], fn ($value) => $value !== null && $value !== '');

        return $this->client->post('api/v1/external/payin', $payload);
    }
}
