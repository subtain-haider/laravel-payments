<?php

namespace Subtain\LaravelPayments\Gateways\Fxpay;

/**
 * FxPay Order API.
 *
 * Creates a new payment order and returns the payment URL to redirect
 * the customer to. FxPay uses a simple single-endpoint order model —
 * create an order, get a URL, redirect the customer.
 *
 * ── Required fields ────────────────────────────────────────────────
 * - customer      — object with name, email, phone
 * - amount        — payment amount (float)
 * - merchant_order_id — your unique order reference (used for webhook reconciliation)
 * - callback_url  — where FxPay POSTs the webhook on payment state change
 *
 * ── Optional fields ────────────────────────────────────────────────
 * - note          — free-text note (e.g. product name)
 *
 * @see https://fxpay.live — FxPay API docs
 */
class OrderService
{
    public function __construct(
        protected FxpayClient $client,
    ) {}

    /**
     * Create a new FxPay payment order.
     *
     * @param  array{
     *     customer: array{name: string, email: string, phone?: string},
     *     amount: float,
     *     merchant_order_id: string,
     *     callback_url: string,
     *     note?: string,
     * }  $data
     *
     * @return array{
     *     success: bool,
     *     order_id: string,
     *     payment_url: string,
     * }
     */
    public function create(array $data): array
    {
        $payload = [
            'customer'          => $data['customer'],
            'amount'            => $data['amount'],
            'merchant_order_id' => $data['merchant_order_id'],
            'callback_url'      => $data['callback_url'],
        ];

        if (! empty($data['note'])) {
            $payload['note'] = $data['note'];
        }

        return $this->client->post('api/v1/orders', $payload);
    }
}
