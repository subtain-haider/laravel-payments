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

        $this->app->singleton(PaymentService::class);
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

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_payments_table.php.stub'
                => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_payments_table.php'),
            __DIR__ . '/../database/migrations/create_payment_logs_table.php.stub'
                => database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_payment_logs_table.php'),
        ], 'payments-migrations');

        // Load webhook routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
    }
}
