<?php

namespace Subtain\LaravelPayments\DTOs;

/**
 * Returned by a gateway's checkout() method.
 *
 * Contains everything the caller needs to redirect the customer
 * to the payment page.
 */
class CheckoutResult
{
    /**
     * @param  string                $redirectUrl     URL to redirect the customer to
     * @param  string                $transactionId   Gateway's transaction/session ID
     * @param  string                $gateway         Gateway name that created this result
     * @param  array<string, mixed>  $raw             Full raw response from the gateway API
     */
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $transactionId = '',
        public readonly string $gateway = '',
        public readonly array  $raw = [],
    ) {}

    /**
     * Check if the checkout was successful (has a redirect URL).
     */
    public function successful(): bool
    {
        return $this->redirectUrl !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'redirect_url'   => $this->redirectUrl,
            'transaction_id' => $this->transactionId,
            'gateway'        => $this->gateway,
        ];
    }
}
