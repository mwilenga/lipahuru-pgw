<?php

return [
    'driver' => 'godigital',
    'base_url' => env('GODIGITAL_BASE_URL', 'https://uat.godigital.example.com'),
    'oauth_path' => env('GODIGITAL_OAUTH_PATH', '/api/v1/oauth/token'),
    'client_id' => env('GODIGITAL_CLIENT_ID'),
    'client_secret' => env('GODIGITAL_CLIENT_SECRET'),
    'merchant_id' => env('GODIGITAL_MERCHANT_ID'),
    'timeout' => (int) env('GODIGITAL_TIMEOUT', 30),
    'verify_ssl' => filter_var(env('GODIGITAL_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    'callback_url' => env('GODIGITAL_CALLBACK_URL', env('APP_URL').'/internal/webhooks/godigital'),
];
