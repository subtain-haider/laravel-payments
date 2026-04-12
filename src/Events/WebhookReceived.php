<?php

namespace Subtain\LaravelPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Subtain\LaravelPayments\DTOs\WebhookResult;
use Subtain\LaravelPayments\Models\Payment;

/**
 * Dispatched for every incoming webhook regardless of status.
 *
 * Useful for logging, auditing, or handling custom statuses
 * that don't map to success/failure.
 *
 * $event->payment is the Payment model (null if not tracked by package).
 */
class WebhookReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WebhookResult $result,
        public readonly ?Payment $payment = null,
    ) {}
}
