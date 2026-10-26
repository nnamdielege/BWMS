<?php

return [
    // Business dates (order dates, best-before, cut-offs) are evaluated in this zone.
    // Timestamps are stored in UTC (config/app.php timezone).
    'business_timezone' => env('BWMS_BUSINESS_TIMEZONE', 'Australia/Brisbane'),

    // Create DB triggers that reject UPDATE/DELETE on stock_movements (NFR-9).
    'ledger_db_triggers' => (bool) env('BWMS_LEDGER_DB_TRIGGERS', true),
];
