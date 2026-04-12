<?php

namespace Subtain\LaravelPayments\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Subtain\LaravelPayments\Models\DiscountCode;

/**
 * Laravel validation rule that checks if a discount code exists and is currently valid.
 *
 * Usage in Form Requests:
 *
 *     'discount_code' => ['nullable', 'string', new ValidDiscountCode(userId: $this->user()->id, amount: 299.00)]
 *
 * Or without scoping:
 *
 *     'discount_code' => ['nullable', 'string', new ValidDiscountCode()]
 */
class ValidDiscountCode implements ValidationRule
{
    public function __construct(
        protected ?int $userId = null,
        protected ?float $amount = null,
        protected ?string $gateway = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $code = DiscountCode::findByCode($value);

        if (! $code) {
            $fail('Invalid discount code.');
            return;
        }

        $result = $code->redeemable($this->userId, $this->amount, $this->gateway);

        if ($result !== true) {
            $fail($result);
        }
    }
}
