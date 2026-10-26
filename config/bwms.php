<?php

return [
    // Business dates (order dates, best-before, cut-offs) are evaluated in this zone.
    // Timestamps are stored in UTC (config/app.php timezone).
    'business_timezone' => env('BWMS_BUSINESS_TIMEZONE', 'Australia/Brisbane'),

    // First site seeded on install. More sites are added in the Locations screen.
    'default_site' => [
        'code' => env('BWMS_DEFAULT_SITE_CODE', 'CLONTARF'),
        'name' => env('BWMS_DEFAULT_SITE_NAME', 'Clontarf Warehouse'),
    ],

    // Create DB triggers that reject UPDATE/DELETE on stock_movements (NFR-9).
    'ledger_db_triggers' => (bool) env('BWMS_LEDGER_DB_TRIGGERS', true),
];
