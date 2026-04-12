<?php

namespace Subtain\LaravelPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Subtain\LaravelPayments\Enums\DiscountType;

class DiscountCode extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'type'                => DiscountType::class,
        'value'               => 'float',
        'min_order_amount'    => 'float',
        'max_discount_amount' => 'float',
        'max_total_uses'      => 'integer',
        'max_uses_per_user'   => 'integer',
        'times_used'          => 'integer',
        'starts_at'           => 'datetime',
        'expires_at'          => 'datetime',
        'gateways'            => 'array',
        'active'              => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('payments.tables.discount_codes', 'lp_discount_codes'));
    }

    // ── Relationships ──────────────────────────────────────

    /**
     * All recorded usages of this discount code.
     */
    public function usages(): HasMany
    {
        return $this->hasMany(DiscountCodeUsage::class);
    }

    // ── Validation ─────────────────────────────────────────

    /**
     * Check if this code is currently redeemable for a given user and amount.
     *
     * Returns true if valid, or a string error reason if not.
     */
    public function redeemable(?int $userId = null, ?float $amount = null, ?string $gateway = null): true|string
    {
        if (! $this->active) {
            return 'Discount code is not active.';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'Discount code is not yet valid.';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'Discount code has expired.';
        }

        if ($this->max_total_uses !== null && $this->times_used >= $this->max_total_uses) {
            return 'Discount code has reached its maximum number of uses.';
        }

        if ($amount !== null && $this->min_order_amount !== null && $amount < $this->min_order_amount) {
            return "Order amount must be at least \${$this->min_order_amount} to use this code.";
        }

        if ($gateway !== null && ! empty($this->gateways) && ! in_array($gateway, $this->gateways, true)) {
            return 'Discount code is not valid for this payment method.';
        }

        if ($userId !== null && $this->max_uses_per_user !== null) {
            $userUsages = $this->usages()
                ->where('user_id', $userId)
                ->count();

            if ($userUsages >= $this->max_uses_per_user) {
                return 'You have already used this discount code the maximum number of times.';
            }
        }

        return true;
    }

    /**
     * Whether this code can be redeemed (boolean shortcut).
     */
    public function isRedeemable(?int $userId = null, ?float $amount = null, ?string $gateway = null): bool
    {
        return $this->redeemable($userId, $amount, $gateway) === true;
    }

    // ── Calculation ────────────────────────────────────────

    /**
     * Calculate the discount amount for a given order amount.
     */
    public function calculateDiscount(float $orderAmount): float
    {
        $discount = match ($this->type) {
            DiscountType::PERCENTAGE => round($orderAmount * ($this->value / 100), 2),
            DiscountType::FIXED      => $this->value,
        };

        // Apply max discount cap
        if ($this->max_discount_amount !== null) {
            $discount = min($discount, $this->max_discount_amount);
        }

        // Never discount more than the order amount
        return min($discount, $orderAmount);
    }

    // ── Counter ────────────────────────────────────────────

    /**
     * Increment the usage counter (called after successful payment).
     */
    public function incrementUsage(): void
    {
        $this->increment('times_used');
    }

    // ── Scopes ─────────────────────────────────────────────

    /**
     * Only active and currently valid discount codes.
     */
    public function scopeValid($query)
    {
        return $query
            ->where('active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) {
                $q->whereNull('max_total_uses')
                    ->orWhereColumn('times_used', '<', 'max_total_uses');
            });
    }

    // ── Finder ─────────────────────────────────────────────

    /**
     * Find a discount code by its code string (case-insensitive).
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', strtoupper(trim($code)))->first();
    }
}
