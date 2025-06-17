<?php

namespace XavierAu\LaravelQrPayment\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use XavierAu\LaravelQrPayment\LaravelQrPaymentServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations(['--database' => 'testing']);
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelQrPaymentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Setup the application environment for testing
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // QR Payment specific config
        $app['config']->set('qr-payment.qr_code.expiry_minutes', 5);
        $app['config']->set('qr-payment.session.timeout_minutes', 2);
        $app['config']->set('qr-payment.security.encryption_key', 'test-key-32-chars-long-for-tests');
        $app['config']->set('qr-payment.broadcasting.enabled', true); // Enable for testing events
        $app['config']->set('app.debug', true); // Enable debugging
    }
}