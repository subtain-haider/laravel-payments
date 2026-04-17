<?php

namespace Subtain\LaravelPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Subtain\LaravelPayments\Enums\PaymentStatus;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'status'           => PaymentStatus::class,
        'amount'           => 'float',
        'discount_amount'  => 'float',
        'metadata'         => 'array',
        'paid_at'          => 'datetime',
        'refunded_at'      => 'datetime',
        'is_sandbox'       => 'boolean',
        'key_fingerprint'  => 'string',  // first4****last4 of the gateway API key active at payment time
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('lp_payments.tables.payments', 'lp_payments'));
    }

    // ── Relationships ──────────────────────────────────────

    /**
     * The model that owns this payment (Order, User, Subscription, etc.).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The discount code applied to this payment, if any.
     */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    /**
     * Webhook logs for this payment.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PaymentLog::class);
    }

    // ── Status Transitions ─────────────────────────────────

    /**
     * Valid status transitions. Prevents invalid state changes.
     *
     * @var array<string, string[]>
     */
    protected static array $transitions = [
        'pending'   => ['processing', 'paid', 'failed', 'cancelled'],
        'processing'=> ['paid', 'failed', 'cancelled'],
        'paid'      => ['refunded'],
        'failed'    => ['pending'],  // retry
        'cancelled' => [],
        'refunded'  => [],
    ];

    /**
     * Transition to a new status with guard rails.
     *
     * @throws \LogicException if the transition is not allowed.
     */
    public function transitionTo(PaymentStatus $newStatus): self
    {
        $current = $this->status->value;
        $allowed = static::$transitions[$current] ?? [];

        if (! in_array($newStatus->value, $allowed, true)) {
            throw new \LogicException(
                "Cannot transition payment [{$this->id}] from [{$current}] to [{$newStatus->value}]."
            );
        }

        $this->status = $newStatus;

        if ($newStatus === PaymentStatus::PAID) {
            $this->paid_at = now();
        }

        $this->save();

        return $this;
    }

    /**
     * Mark as paid (convenience).
     */
    public function markAsPaid(?string $transactionId = null): self
    {
        if ($transactionId) {
            $this->transaction_id = $transactionId;
        }

        return $this->transitionTo(PaymentStatus::PAID);
    }

    /**
     * Mark as failed (convenience).
     */
    public function markAsFailed(): self
    {
        return $this->transitionTo(PaymentStatus::FAILED);
    }

    /**
     * Mark as refunded (convenience).
     */
    public function markAsRefunded(): self
    {
        $this->refunded_at = now();

        return $this->transitionTo(PaymentStatus::REFUNDED);
    }

    // ── Query Helpers ──────────────────────────────────────

    /**
     * Whether a discount was applied to this payment.
     */
    public function hasDiscount(): bool
    {
        return $this->discount_code_id !== null;
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::PAID;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::FAILED;
    }

    /**
     * Find a payment by its invoice ID.
     */
    public static function findByInvoiceId(string $invoiceId): ?self
    {
        return static::where('invoice_id', $invoiceId)->first();
    }

    /**
     * Find a payment by its gateway transaction ID.
     */
    public static function findByTransactionId(string $transactionId): ?self
    {
        return static::where('transaction_id', $transactionId)->first();
    }
}
