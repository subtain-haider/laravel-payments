<?php

namespace Subtain\LaravelPayments\DTOs;

/**
 * Gateway-agnostic checkout request.
 *
 * Pass this to any gateway's checkout() method. The gateway
 * maps these fields to its own API format internally.
 */
class CheckoutRequest
{
    /**
     * @param  float                 $amount         Amount in the smallest major unit (e.g. 299.00 = $299)
     * @param  string                $currency       ISO 4217 currency code (e.g. 'USD')
     * @param  string                $invoiceId      Your internal order/invoice identifier
     * @param  string                $customerEmail  Customer email for the payment page
     * @param  string                $customerName   Customer name (optional)
     * @param  string                $customerIp     Customer IP address (required by some gateways)
     * @param  string                $productName    Product/item name shown on payment page
     * @param  string                $productDescription  Product description
     * @param  string                $successUrl     URL to redirect on success
     * @param  string                $cancelUrl      URL to redirect on cancel
     * @param  string                $webhookUrl     URL for the gateway to send webhooks
     * @param  array<string, mixed>  $metadata       Arbitrary key-value data passed to the gateway
     * @param  array<string, mixed>  $extra          Gateway-specific fields (e.g. billing address, crypto currency)
     */
    public function __construct(
        public readonly float  $amount,
        public readonly string $currency = 'USD',
        public readonly string $invoiceId = '',
        public readonly string $customerEmail = '',
        public readonly string $customerName = '',
        public readonly string $customerIp = '',
        public readonly string $productName = '',
        public readonly string $productDescription = '',
        public readonly string $successUrl = '',
        public readonly string $cancelUrl = '',
        public readonly string $webhookUrl = '',
        public readonly array  $metadata = [],
        public readonly array  $extra = [],
    ) {}

    /**
     * Create from an associative array (useful when building from validated request data).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'USD',
            invoiceId: $data['invoice_id'] ?? '',
            customerEmail: $data['customer_email'] ?? '',
            customerName: $data['customer_name'] ?? '',
            customerIp: $data['customer_ip'] ?? '',
            productName: $data['product_name'] ?? '',
            productDescription: $data['product_description'] ?? '',
            successUrl: $data['success_url'] ?? '',
            cancelUrl: $data['cancel_url'] ?? '',
            webhookUrl: $data['webhook_url'] ?? '',
            metadata: $data['metadata'] ?? [],
            extra: $data['extra'] ?? [],
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount'              => $this->amount,
            'currency'            => $this->currency,
            'invoice_id'          => $this->invoiceId,
            'customer_email'      => $this->customerEmail,
            'customer_name'       => $this->customerName,
            'customer_ip'         => $this->customerIp,
            'product_name'        => $this->productName,
            'product_description' => $this->productDescription,
            'success_url'         => $this->successUrl,
            'cancel_url'          => $this->cancelUrl,
            'webhook_url'         => $this->webhookUrl,
            'metadata'            => $this->metadata,
            'extra'               => $this->extra,
        ];
    }
}
