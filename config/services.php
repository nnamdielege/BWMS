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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Ordermentum (spec B3). Auth is the API token only (x-api-key, confirmed
     * 2026-10-27); username/password is not supported.
     */
    'ordermentum' => [
        'api_key' => env('ORDERMENTUM_API_KEY'),
        'base_url' => env('ORDERMENTUM_BASE_URL', 'https://api.ordermentum.com'),
        'stock_url' => env('ORDERMENTUM_STOCK_URL', 'https://stock.ordermentum.com'),
        'supplier_id' => env('ORDERMENTUM_SUPPLIER_ID'),
        'location_id' => env('ORDERMENTUM_LOCATION_ID'),
        'connect_timeout' => (int) env('ORDERMENTUM_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('ORDERMENTUM_TIMEOUT', 15),
        // A 429 whose Retry-After is at most this many seconds is waited out; longer fails.
        'max_retry_wait' => (int) env('ORDERMENTUM_MAX_RETRY_WAIT', 30),
        // Stock API supplier headers: plain (context-*), x (x-context-*) or both. The portal's
        // client reports plain works; confirm with `om:probe stock {id} --headers=...`.
        'context_headers' => env('ORDERMENTUM_CONTEXT_HEADERS', 'plain'),
        // Client-side budgets per minute, "host:METHOD[:path prefix]" (most specific wins).
        // Stock PUT 20 and POST 12 are confirmed (2026-10-27); PATCH is unconfirmed (asked),
        // so it gets the conservative POST figure. Core API figures are from the docs (B3.1).
        'rate_limits' => [
            'stock:PUT' => (int) env('ORDERMENTUM_RPM_STOCK_PUT', 20),
            'stock:POST' => (int) env('ORDERMENTUM_RPM_STOCK_POST', 12),
            'stock:PATCH' => (int) env('ORDERMENTUM_RPM_STOCK_PATCH', 12),
            'stock:GET' => (int) env('ORDERMENTUM_RPM_STOCK_GET', 40),
            'core:GET:/v1/orders' => 24,
            'core:GET:/v2/orders' => 40,
            'core:GET' => 40,
        ],
    ],

];
