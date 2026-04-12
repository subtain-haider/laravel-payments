<?php

namespace Subtain\LaravelPayments\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Subtain\LaravelPayments\Enums\PaymentStatus;
use Subtain\LaravelPayments\Models\Payment;

/**
 * Add to any Eloquent model that can have payments.
 *
 * Usage:
 *   class Order extends Model {
 *       use HasPayments;
 *   }
 *
 *   $order->payments;                   // all payments
 *   $order->latestPayment();            // most recent one
 *   $order->hasPaidPayment();           // any successful?
 *   $order->createPayment([...]);       // create a new payment for this model
 */
trait HasPayments
{
    /**
     * All payments for this model.
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Get the most recent payment.
     */
    public function latestPayment(): ?Payment
    {
        return $this->payments()->latest()->first();
    }

    /**
     * Check if this model has at least one paid payment.
     */
    public function hasPaidPayment(): bool
    {
        return $this->payments()->where('status', PaymentStatus::PAID->value)->exists();
    }

    /**
     * Get all paid payments.
     */
    public function paidPayments(): MorphMany
    {
        return $this->payments()->where('status', PaymentStatus::PAID->value);
    }

    /**
     * Create a new payment record linked to this model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createPayment(array $attributes): Payment
    {
        return $this->payments()->create($attributes);
    }
}
