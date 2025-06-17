<?php

namespace XavierAu\LaravelQrPayment;

use Illuminate\Support\ServiceProvider;
use XavierAu\LaravelQrPayment\Contracts\QrCodeServiceInterface;
use XavierAu\LaravelQrPayment\Contracts\PaymentSessionServiceInterface;
use XavierAu\LaravelQrPayment\Contracts\TransactionServiceInterface;
use XavierAu\LaravelQrPayment\Contracts\NotificationServiceInterface;
use XavierAu\LaravelQrPayment\Services\QrCodeService;
use XavierAu\LaravelQrPayment\Services\PaymentSessionService;
use XavierAu\LaravelQrPayment\Services\TransactionService;
use XavierAu\LaravelQrPayment\Services\NotificationService;

class LaravelQrPaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/qr-payment.php',
            'qr-payment'
        );

        // Bind interfaces to concrete implementations following SOLID principles
        $this->app->bind(QrCodeServiceInterface::class, QrCodeService::class);
        $this->app->bind(PaymentSessionServiceInterface::class, PaymentSessionService::class);
        $this->app->bind(TransactionServiceInterface::class, TransactionService::class);
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // Publish config
            $this->publishes([
                __DIR__ . '/../config/qr-payment.php' => config_path('qr-payment.php'),
            ], 'qr-payment-config');

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'qr-payment-migrations');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }
}