<?php

return [
    'token_ttl' => (int) env('GATEWAY_TOKEN_TTL', 900),
    'timestamp_tolerance' => (int) env('GATEWAY_TIMESTAMP_TOLERANCE', 300),
    'nonce_ttl' => (int) env('GATEWAY_NONCE_TTL', 600),
    'callback_max_retries' => (int) env('GATEWAY_CALLBACK_MAX_RETRIES', 10),
    'callback_timeout' => (int) env('GATEWAY_CALLBACK_TIMEOUT', 30),
    'default_currency' => env('GATEWAY_DEFAULT_CURRENCY', 'TZS'),
    'transaction_id_prefix' => env('GATEWAY_TRANSACTION_PREFIX', 'TXN'),
    'rate_limits' => [
        'payments' => (int) env('GATEWAY_RATE_LIMIT_PAYMENTS', 60),
        'queries' => (int) env('GATEWAY_RATE_LIMIT_QUERIES', 120),
    ],
    'webhook_retry_delays' => [60, 300, 900, 3600, 21600, 86400],
    'poll_pending_after_seconds' => (int) env('GATEWAY_POLL_PENDING_AFTER', 120),
    'credential_rotation_grace_hours' => 24,
    /** Calendar-day filters (from/to) are interpreted in this timezone. */
    'filter_timezone' => env('GATEWAY_FILTER_TIMEZONE', 'Africa/Dar_es_Salaam'),
];
