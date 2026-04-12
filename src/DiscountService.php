<?php

namespace Subtain\LaravelPayments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Subtain\LaravelPayments\DTOs\DiscountResult;
use Subtain\LaravelPayments\Models\DiscountCode;
use Subtain\LaravelPayments\Models\DiscountCodeUsage;

/**
 * Service for validating, applying, and recording discount code usage.
 *
 * This service is gateway-agnostic — it works with any payment flow.
 * The consuming application calculates the final amount and sends it
 * to the payment gateway; the gateway never sees the discount code.
 */
class DiscountService
{
    /**
     * Validate a discount code for a given user and order amount.
     *
     * @param  string       $code     The discount code string.
     * @param  float        $amount   The order amount before discount.
     * @param  int|null     $userId   The user attempting to redeem (for per-user limits).
     * @param  string|null  $gateway  The payment gateway (for gateway-scoped codes).
     *
     * @throws ValidationException if the code is invalid or not redeemable.
     */
    public function validate(string $code, float $amount, ?int $userId = null, ?string $gateway = null): DiscountCode
    {
        $discountCode = DiscountCode::findByCode($code);

        if (! $discountCode) {
            throw ValidationException::withMessages([
                'discount_code' => ['Invalid discount code.'],
            ]);
        }

        $result = $discountCode->redeemable($userId, $amount, $gateway);

        if ($result !== true) {
            throw ValidationException::withMessages([
                'discount_code' => [$result],
            ]);
        }

        return $discountCode;
    }

    /**
     * Validate and calculate the discount for a given code and amount.
     *
     * @param  string       $code     The discount code string.
     * @param  float        $amount   The order amount before discount.
     * @param  int|null     $userId   The user attempting to redeem.
     * @param  string|null  $gateway  The payment gateway (for gateway-scoped codes).
     *
     * @throws ValidationException if the code is invalid.
     */
    public function apply(string $code, float $amount, ?int $userId = null, ?string $gateway = null): DiscountResult
    {
        $discountCode = $this->validate($code, $amount, $userId, $gateway);

        return DiscountResult::fromCode($discountCode, $amount);
    }

    /**
     * Record a discount code usage after a successful payment.
     *
     * Call this AFTER the payment succeeds (e.g. in your order fulfillment
     * listener or after DB transaction commit). This increments the usage
     * counter and creates an audit trail record.
     *
     * @param  DiscountResult  $result   The result from apply().
     * @param  int|null        $userId   The user who redeemed.
     * @param  Model|null      $payable  The polymorphic payable (Order, etc.).
     */
    public function recordUsage(
        DiscountResult $result,
        ?int $userId = null,
        ?Model $payable = null,
    ): DiscountCodeUsage {
        $result->discountCode->incrementUsage();

        $usage = new DiscountCodeUsage([
            'discount_code_id' => $result->discountCode->id,
            'user_id'          => $userId,
            'original_amount'  => $result->originalAmount,
            'discount_amount'  => $result->discountAmount,
            'final_amount'     => $result->finalAmount,
        ]);

        if ($payable) {
            $usage->payable_type = get_class($payable);
            $usage->payable_id   = $payable->getKey();
        }

        $usage->save();

        return $usage;
    }
}
