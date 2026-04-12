<?php

namespace Subtain\LaravelPayments\Facades;

use Illuminate\Support\Facades\Facade;
use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;

/**
 * @method static PaymentGateway gateway(?string $name = null)
 * @method static CheckoutResult checkout(CheckoutRequest $request)
 * @method static string getDefaultDriver()
 *
 * @see \Subtain\LaravelPayments\PaymentManager
 */
class Payment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payment';
    }
}
