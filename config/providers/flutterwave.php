<?php

return [
    'driver' => 'flutterwave',
    'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
    'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
    'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
    'timeout' => (int) env('FLUTTERWAVE_TIMEOUT', 30),
];
