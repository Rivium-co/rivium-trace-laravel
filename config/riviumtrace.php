<?php

return [
    // Your RiviumTrace API key (rv_live_xxx or rv_test_xxx)
    'api_key' => env('RIVIUMTRACE_API_KEY', ''),

    // Server secret for authentication (rv_srv_xxx)
    'server_secret' => env('RIVIUMTRACE_SERVER_SECRET', ''),

    // Enable or disable the SDK
    'enabled' => env('RIVIUMTRACE_ENABLED', true),

    // Environment name (defaults to APP_ENV)
    'environment' => env('RIVIUMTRACE_ENVIRONMENT', env('APP_ENV', 'production')),

    // Release/version of your app
    'release' => env('RIVIUMTRACE_RELEASE', '0.1.0'),

    // Error capture sample rate (0.0 to 1.0)
    'sample_rate' => env('RIVIUMTRACE_SAMPLE_RATE', 1.0),

    // Enable SDK debug logging
    'debug' => env('RIVIUMTRACE_DEBUG', false),

    // HTTP request timeout in seconds
    'timeout' => env('RIVIUMTRACE_TIMEOUT', 5),

    // Max breadcrumbs to keep per request (max 100)
    'max_breadcrumbs' => 50,

    // Rate limiting config
    'rate_limiting' => [
        'window_seconds' => 60,
        'max_errors_per_key' => 10,
        'max_total' => 100,
    ],

    // Logging config
    'logging' => [
        'enabled' => env('RIVIUMTRACE_LOGGING_ENABLED', true),
        'source_id' => env('RIVIUMTRACE_LOG_SOURCE_ID', null),
        'source_name' => env('RIVIUMTRACE_LOG_SOURCE_NAME', null),
        'batch_size' => 50,
    ],

    // Performance / APM config
    'performance' => [
        'enabled' => env('RIVIUMTRACE_PERFORMANCE_ENABLED', true),
        'batch_size' => 10,
        'track_db_queries' => true,
        'slow_query_threshold_ms' => 500,
    ],

    // Middleware config
    'middleware' => [
        'enabled' => true,
        'track_requests' => true,
        'track_user' => true,
        'capture_request_body' => false,
        'ignored_paths' => [
            '_debugbar/*',
            'telescope/*',
            'horizon/*',
        ],
    ],

    // Exception handler config
    'exception_handler' => [
        'enabled' => true,
        'report_4xx' => false,
        'ignored_exceptions' => [
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Validation\ValidationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        ],
    ],

    // API endpoint (do not change unless directed by support)
    'api_url' => env('RIVIUMTRACE_API_URL', 'https://trace.rivium.co'),
];
