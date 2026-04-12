<?php

namespace Subtain\LaravelPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Subtain\LaravelPayments\DTOs\WebhookResult;

/**
 * Dispatched when a payment webhook confirms a successful payment.
 *
 * Listen to this event in your application to trigger business logic
 * (e.g. provision accounts, send emails, update orders).
 */
class PaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WebhookResult $result,
    ) {}
}
