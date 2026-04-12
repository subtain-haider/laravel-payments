<?php

namespace Subtain\LaravelPayments\DTOs;

use Subtain\LaravelPayments\Models\DiscountCode;

/**
 * Immutable result of applying a discount code.
 */
class DiscountResult
{
    public function __construct(
        public readonly DiscountCode $discountCode,
        public readonly float $originalAmount,
        public readonly float $discountAmount,
        public readonly float $finalAmount,
    ) {}

    /**
     * Create from a discount code and order amount.
     */
    public static function fromCode(DiscountCode $code, float $orderAmount): self
    {
        $discount = $code->calculateDiscount($orderAmount);

        return new self(
            discountCode: $code,
            originalAmount: $orderAmount,
            discountAmount: $discount,
            finalAmount: round($orderAmount - $discount, 2),
        );
    }

    /**
     * Convert to array (useful for logging/metadata).
     */
    public function toArray(): array
    {
        return [
            'discount_code'   => $this->discountCode->code,
            'discount_type'   => $this->discountCode->type->value,
            'discount_value'  => $this->discountCode->value,
            'original_amount' => $this->originalAmount,
            'discount_amount' => $this->discountAmount,
            'final_amount'    => $this->finalAmount,
        ];
    }
}
