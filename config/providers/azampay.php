<?php

return [
    'driver' => 'azampay',
    'base_url' => env('AZAMPAY_BASE_URL', 'https://sandbox.azampay.co.tz'),
    'client_id' => env('AZAMPAY_CLIENT_ID'),
    'client_secret' => env('AZAMPAY_CLIENT_SECRET'),
    'app_name' => env('AZAMPAY_APP_NAME', 'lipahuru'),
    'timeout' => (int) env('AZAMPAY_TIMEOUT', 30),
    'network_map' => [
        'VODACOM' => 'vodacom',
        'AIRTEL' => 'airtel',
        'YAS' => 'tigo',
        'HALOTEL' => 'halotel',
    ],
];
