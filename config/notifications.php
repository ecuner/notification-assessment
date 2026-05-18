<?php

return [
    'api_key' => env('NOTIFICATIONS_API_KEY'),
    'provider_url' => env('NOTIFICATIONS_PROVIDER_URL'),
    'provider_timeout_seconds' => (int) env('NOTIFICATIONS_PROVIDER_TIMEOUT_SECONDS', 5),
    'rate_limit_per_second' => (int) env('NOTIFICATIONS_RATE_LIMIT_PER_SECOND', 100),
    'rate_limit_release_seconds' => (int) env('NOTIFICATIONS_RATE_LIMIT_RELEASE_SECONDS', 1),
];
