<?php

return [
    'driver' => 'godigital',
    'base_url' => env('GODIGITAL_BASE_URL', 'https://uat.godigital.example.com'),
    'client_id' => env('GODIGITAL_CLIENT_ID'),
    'client_secret' => env('GODIGITAL_CLIENT_SECRET'),
    'merchant_id' => env('GODIGITAL_MERCHANT_ID'),
    'signing_secret' => env('GODIGITAL_SIGNING_SECRET'),
    'callback_secret' => env('GODIGITAL_CALLBACK_SECRET'),
    'timeout' => (int) env('GODIGITAL_TIMEOUT', 30),
    'verify_ssl' => filter_var(env('GODIGITAL_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    'callback_url' => env('GODIGITAL_CALLBACK_URL', env('APP_URL').'/internal/webhooks/godigital'),
];
