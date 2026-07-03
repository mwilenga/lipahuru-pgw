<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'passport',
            'provider' => 'merchants',
        ],
        'admin' => [
            'driver' => 'sanctum',
            'provider' => 'admin_users',
        ],
        'merchant' => [
            'driver' => 'sanctum',
            'provider' => 'merchant_users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ],
        'merchants' => [
            'driver' => 'eloquent',
            'model' => App\Models\Merchant::class,
        ],
        'admin_users' => [
            'driver' => 'eloquent',
            'model' => App\Models\AdminUser::class,
        ],
        'merchant_users' => [
            'driver' => 'eloquent',
            'model' => App\Models\MerchantUser::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
