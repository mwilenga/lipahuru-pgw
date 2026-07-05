<?php

$origins = env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000'));
$parsedOrigins = array_values(array_filter(array_map('trim', explode(',', (string) $origins))));

return [

    'paths' => ['api/*', 'oauth/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $parsedOrigins, // use CORS_ALLOWED_ORIGINS=* to allow all

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')),
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
