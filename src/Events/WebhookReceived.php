<?php

namespace Subtain\LaravelPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Subtain\LaravelPayments\DTOs\WebhookResult;

/**
 * Dispatched for every incoming webhook regardless of status.
 *
 * Useful for logging, auditing, or handling custom statuses
 * that don't map to success/failure.
 */
class WebhookReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly WebhookResult $result,
    ) {}
}
