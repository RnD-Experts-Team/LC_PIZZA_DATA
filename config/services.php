<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'lcegateway' => [
        'username'      => env('LC_GATEWAY_USERNAME'),
        'password'      => env('LC_GATEWAY_PASSWORD'),
        'portal_server' => env('LC_GATEWAY_PORTAL_SERVER'),
        'hmac_user'     => env('LC_GATEWAY_HMAC_USER'),
        'hmac_key'      => env('LC_GATEWAY_HMAC_KEY'),
    ],

    'auth_server' => [
        'base_url'    => env('AUTH_SERVER_BASE_URL', 'http://auth-service.local'),
        'verify_path' => env('AUTH_SERVER_VERIFY_PATH', '/api/v1/auth/token-verify'),
        'service_name' => env('AUTH_SERVER_SERVICE_NAME', 'my-service'),
        'call_token'  => env('AUTH_SERVER_CALL_TOKEN', ''),

        'timeout'  => (int) env('AUTH_SERVER_TIMEOUT', 3),
        'retries'  => (int) env('AUTH_SERVER_RETRIES', 1),
        'retry_ms' => (int) env('AUTH_SERVER_RETRY_MS', 100),

        // Redis caching on client side
        'cache_ttl' => (int) env('AUTH_SERVER_CACHE_TTL', 30),
    ],

    'old_api' => [
        'base_url' => env('OLD_API_BASE_URL', 'http://localhost'),
        'api_key' => env('OLD_API_KEY'),
    ],

    'x_secret_key' => env('X_SECRET_KEY'),
];
