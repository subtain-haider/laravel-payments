<?php

namespace Subtain\LaravelPayments;

use Illuminate\Support\ServiceProvider;
use Subtain\LaravelPayments\Contracts\PaymentGateway;

class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/payments.php', 'payments'
        );

        $this->app->singleton('payment', function ($app) {
            return new PaymentManager($app);
        });

        $this->app->bind(PaymentGateway::class, function ($app) {
            return $app->make('payment')->gateway();
        });
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/payments.php' => config_path('payments.php'),
        ], 'payments-config');

        // Load webhook routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
    }
}
