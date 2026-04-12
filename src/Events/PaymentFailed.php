<?php

namespace Subtain\LaravelPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Models\Payment;

/**
 * Dispatched when a payment webhook reports a failed payment.
 *
 * Listen to this event to handle failure scenarios
 * (e.g. notify user, mark order as failed).
 *
 * $event->payment is the Payment model (null if not tracked by package).
 */
class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WebhookResult $result,
        public readonly ?Payment $payment = null,
    ) {}
}
