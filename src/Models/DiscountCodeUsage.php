<?php

namespace Subtain\LaravelPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DiscountCodeUsage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'original_amount'  => 'float',
        'discount_amount'  => 'float',
        'final_amount'     => 'float',
        'created_at'       => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('payments.tables.discount_code_usages', 'lp_discount_code_usages'));
    }

    // ── Relationships ──────────────────────────────────────

    /**
     * The discount code that was used.
     */
    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    /**
     * The payable entity (Order, Subscription, etc.).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
