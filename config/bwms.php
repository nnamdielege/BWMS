<?php

return [
    // Business dates (order dates, best-before, cut-offs) are evaluated in this zone.
    // Timestamps are stored in UTC (config/app.php timezone).
    'business_timezone' => env('BWMS_BUSINESS_TIMEZONE', 'Australia/Brisbane'),
];
