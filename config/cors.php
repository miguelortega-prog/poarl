<?php

$configuredOrigins = array_values(array_filter(array_unique(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
))));

if (empty($configuredOrigins)) {
    $defaultOrigins = [
        env('APP_URL', 'http://localhost'),
        env('FRONTEND_URL', ''),
        'http://localhost',
        'http://127.0.0.1',
    ];

    foreach ($defaultOrigins as $origin) {
        if (! is_string($origin) || $origin === '') {
            continue;
        }

        $trimmed = rtrim($origin, '/');

        if ($trimmed === '') {
            continue;
        }

        if (! in_array($trimmed, $configuredOrigins, true)) {
            $configuredOrigins[] = $trimmed;
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'recaudo/comunicados/uploads/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => $configuredOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
