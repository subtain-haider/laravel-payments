<?php

namespace Subtain\LaravelPayments;

use Illuminate\Support\ServiceProvider;
use Subtain\LaravelPayments\Contracts\PaymentGateway;
use Subtain\LaravelPayments\Gateways\Fanbasis\FanbasisClient;

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
        $this->app->singleton(DiscountService::class);

        // Register FanbasisClient as a singleton for direct DI usage
        $this->app->singleton(FanbasisClient::class, function ($app) {
            $config = $app['config']->get('payments.gateways.fanbasis', []);

            return new FanbasisClient($config);
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

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_payments_table.php.stub'
                => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_payments_table.php'),
            __DIR__ . '/../database/migrations/create_payment_logs_table.php.stub'
                => database_path('migrations/' . date('Y_m_d_His', time() + 1) . '_create_payment_logs_table.php'),
            __DIR__ . '/../database/migrations/create_discount_codes_table.php.stub'
                => database_path('migrations/' . date('Y_m_d_His', time() + 2) . '_create_discount_codes_table.php'),
            __DIR__ . '/../database/migrations/create_discount_code_usages_table.php.stub'
                => database_path('migrations/' . date('Y_m_d_His', time() + 3) . '_create_discount_code_usages_table.php'),
        ], 'payments-migrations');

        // Load webhook routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
    }
}
