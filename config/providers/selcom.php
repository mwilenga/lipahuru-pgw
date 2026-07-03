<?php

return [
    'driver' => 'selcom',
    'base_url' => env('SELCOM_BASE_URL'),
    'api_key' => env('SELCOM_API_KEY'),
    'api_secret' => env('SELCOM_API_SECRET'),
    'timeout' => (int) env('SELCOM_TIMEOUT', 30),
];
