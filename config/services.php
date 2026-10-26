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
     * Ordermentum (spec B3). Auth: x-api-key when ORDERMENTUM_API_KEY is set,
     * otherwise the legacy username/password login (deprecated 1 Jan 2027, R-1).
     */
    'ordermentum' => [
        'api_key' => env('ORDERMENTUM_API_KEY'),
        'username' => env('ORDERMENTUM_USERNAME'),
        'password' => env('ORDERMENTUM_PASSWORD'),
        'auth_url' => env('ORDERMENTUM_AUTH_URL', 'https://app.ordermentum.com/v1/auth'),
        'base_url' => env('ORDERMENTUM_BASE_URL', 'https://api.ordermentum.com'),
        'stock_url' => env('ORDERMENTUM_STOCK_URL', 'https://stock.ordermentum.com'),
        'supplier_id' => env('ORDERMENTUM_SUPPLIER_ID'),
        'location_id' => env('ORDERMENTUM_LOCATION_ID'),
        'connect_timeout' => (int) env('ORDERMENTUM_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('ORDERMENTUM_TIMEOUT', 15),
        // A 429 whose Retry-After is at most this many seconds is waited out; longer fails.
        'max_retry_wait' => (int) env('ORDERMENTUM_MAX_RETRY_WAIT', 30),
    ],

];
