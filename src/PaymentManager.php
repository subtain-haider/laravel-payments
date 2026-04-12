<?php

namespace Subtain\LaravelPayments;

use Illuminate\Support\Manager;
use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\Gateways\FanbasisGateway;
use Subtain\LaravelPayments\Gateways\Match2PayGateway;
use Subtain\LaravelPayments\Gateways\PremiumPayGateway;

/**
 * Payment gateway manager — uses Laravel's Manager pattern.
 *
 * Resolves gateway drivers from config. The default gateway
 * is set in config('payments.default').
 *
 * Usage:
 *   Payment::checkout($request);            // uses default gateway
 *   Payment::gateway('fanbasis')->checkout($request);
 */
class PaymentManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('payments.default', 'fanbasis');
    }

    /**
     * Resolve a gateway by name.
     */
    public function gateway(?string $name = null): PaymentGateway
    {
        return $this->driver($name);
    }

    /**
     * Create the Fanbasis gateway driver.
     */
    protected function createFanbasisDriver(): FanbasisGateway
    {
        $config = $this->config->get('payments.gateways.fanbasis', []);

        return new FanbasisGateway($config);
    }

    /**
     * Create the PremiumPay gateway driver.
     */
    protected function createPremiumpayDriver(): PremiumPayGateway
    {
        $config = $this->config->get('payments.gateways.premiumpay', []);

        return new PremiumPayGateway($config);
    }

    /**
     * Create the Match2Pay gateway driver.
     */
    protected function createMatch2payDriver(): Match2PayGateway
    {
        $config = $this->config->get('payments.gateways.match2pay', []);

        return new Match2PayGateway($config);
    }

    /**
     * Create a custom driver registered via config.
     *
     * Looks for a 'driver' key in the gateway config pointing
     * to a class implementing PaymentGateway.
     *
     * @param  string  $driver
     * @return \Subtain\LaravelPayments\Contracts\PaymentGateway
     *
     * @throws \InvalidArgumentException
     */
    protected function createDriver($driver)
    {
        $config = $this->config->get("payments.gateways.{$driver}", []);

        // If a custom driver class is specified, use it
        if (isset($config['driver']) && class_exists($config['driver'])) {
            $gatewayClass = $config['driver'];
            $instance = new $gatewayClass($config);

            if (! $instance instanceof PaymentGateway) {
                throw new \InvalidArgumentException(
                    "Gateway [{$driver}] class [{$gatewayClass}] must implement " . PaymentGateway::class
                );
            }

            return $instance;
        }

        // Fall back to Laravel's default driver resolution (createXxxDriver methods)
        return parent::createDriver($driver);
    }
}
