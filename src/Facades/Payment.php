<?php

namespace Subtain\LaravelPayments\Facades;

use Illuminate\Support\Facades\Facade;
use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\DTOs\CheckoutRequest;
use Subtain\LaravelPayments\DTOs\CheckoutResult;
use Subtain\LaravelPayments\Gateways\FanbasisGateway;

/**
 * @method static PaymentGateway gateway(?string $name = null)
 * @method static CheckoutResult checkout(CheckoutRequest $request)
 * @method static string getDefaultDriver()
 *
 * Fanbasis-specific (when using Payment::gateway('fanbasis')):
 * @see FanbasisGateway::checkoutSessions()
 * @see FanbasisGateway::customers()
 * @see FanbasisGateway::subscribers()
 * @see FanbasisGateway::discountCodes()
 * @see FanbasisGateway::products()
 * @see FanbasisGateway::transactions()
 * @see FanbasisGateway::refunds()
 * @see FanbasisGateway::webhooks()
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
