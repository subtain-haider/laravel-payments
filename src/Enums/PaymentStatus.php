<?php

namespace Subtain\LaravelPayments\Enums;

/**
 * Standardized payment statuses across all gateways.
 *
 * Each gateway maps its own status strings to one of these values
 * inside its parseWebhook() method.
 */
enum PaymentStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PAID = 'paid';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
}
