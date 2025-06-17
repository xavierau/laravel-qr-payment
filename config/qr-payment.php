<?php

return [
    /*
    |--------------------------------------------------------------------------
    | QR Code Settings
    |--------------------------------------------------------------------------
    */
    'qr_code' => [
        'expiry_minutes' => env('QR_PAYMENT_EXPIRY_MINUTES', 5),
        'size' => env('QR_PAYMENT_SIZE', 300),
        'format' => env('QR_PAYMENT_FORMAT', 'png'),
        'error_correction' => env('QR_PAYMENT_ERROR_CORRECTION', 'M'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Session Settings
    |--------------------------------------------------------------------------
    */
    'session' => [
        'timeout_minutes' => env('QR_PAYMENT_SESSION_TIMEOUT', 2),
        'cleanup_interval_minutes' => env('QR_PAYMENT_CLEANUP_INTERVAL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */
    'security' => [
        'encryption_key' => env('QR_PAYMENT_ENCRYPTION_KEY'),
        'rate_limit' => env('QR_PAYMENT_RATE_LIMIT', 60),
        'max_amount_offline' => env('QR_PAYMENT_MAX_OFFLINE_AMOUNT', 5000), // in cents
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Settings
    |--------------------------------------------------------------------------
    */
    'transaction' => [
        'currency' => env('QR_PAYMENT_CURRENCY', 'USD'),
        'decimal_places' => env('QR_PAYMENT_DECIMAL_PLACES', 2),
        'max_amount' => env('QR_PAYMENT_MAX_AMOUNT', 1000000), // in cents
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting Settings
    |--------------------------------------------------------------------------
    */
    'broadcasting' => [
        'enabled' => env('QR_PAYMENT_BROADCASTING_ENABLED', true),
        'connection' => env('QR_PAYMENT_BROADCAST_CONNECTION', 'pusher'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Settings
    |--------------------------------------------------------------------------
    */
    'database' => [
        'connection' => env('QR_PAYMENT_DB_CONNECTION', 'mysql'),
        'table_prefix' => env('QR_PAYMENT_TABLE_PREFIX', 'qr_payment_'),
    ],
];