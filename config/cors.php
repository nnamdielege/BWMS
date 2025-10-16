<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel CORS
    |--------------------------------------------------------------------------
    |
    | allowedOrigins - a list of hosts to allow CORS requests from
    | allowedOriginsPatterns - Optional CORS requests from origins matching a regex pattern
    | allowedMethods - a list of HTTP verbs that are allowed for CORS requests
    | allowedHeaders - a list of HTTP headers that are allowed on CORS requests
    | exposedHeaders - a list of HTTP headers that browsers are allowed to access
    | maxAge - indicates how long the results of a preflight request can be cached
    | supportsCredentials - indicates whether the request can include user credentials
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];