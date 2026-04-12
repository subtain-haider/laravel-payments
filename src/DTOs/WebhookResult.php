<?php

namespace Subtain\LaravelPayments\DTOs;

use Subtain\LaravelPayments\Enums\PaymentStatus;

/**
 * Standardized result of parsing an incoming webhook.
 *
 * No matter which gateway sent the webhook, your application
 * always receives this same structure via the PaymentSucceeded
 * or PaymentFailed events.
 */
class WebhookResult
{
    /**
     * @param  PaymentStatus         $status          Parsed payment status
     * @param  string                $invoiceId       Your internal invoice/order ID
     * @param  string                $transactionId   Gateway's transaction ID
     * @param  string                $gateway         Gateway name
     * @param  float                 $amount          Amount paid (0 if not provided)
     * @param  string                $currency        Currency code
     * @param  array<string, mixed>  $metadata        Metadata originally sent with checkout
     * @param  array<string, mixed>  $raw             Full raw webhook payload
     */
    public function __construct(
        public readonly PaymentStatus $status,
        public readonly string        $invoiceId = '',
        public readonly string        $transactionId = '',
        public readonly string        $gateway = '',
        public readonly float         $amount = 0,
        public readonly string        $currency = 'USD',
        public readonly array         $metadata = [],
        public readonly array         $raw = [],
    ) {}

    /**
     * Check if this webhook represents a successful payment.
     */
    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::PAID;
    }

    /**
     * Check if this webhook represents a failed payment.
     */
    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::FAILED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status'         => $this->status->value,
            'invoice_id'     => $this->invoiceId,
            'transaction_id' => $this->transactionId,
            'gateway'        => $this->gateway,
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'metadata'       => $this->metadata,
        ];
    }
}
