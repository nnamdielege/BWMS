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

    /*
     * Ordermentum sync (spec B3.2). Both switches are off until cutover; each
     * listing also has its own sync_enabled flag.
     */
    'ordermentum' => [
        'push_enabled' => (bool) env('ORDERMENTUM_PUSH_ENABLED', false),
        'ingest_enabled' => (bool) env('ORDERMENTUM_INGEST_ENABLED', false),
        // Long random token in the webhook path; the only authentication Ordermentum allows (B3.1).
        'webhook_token' => env('ORDERMENTUM_WEBHOOK_TOKEN'),
        // Site whose available stock is pushed and where Ordermentum orders are fulfilled.
        'site_code' => env('ORDERMENTUM_SITE_CODE', env('BWMS_DEFAULT_SITE_CODE', 'CLONTARF')),
        // A burst of stock changes for one product becomes one push after this delay.
        'debounce_seconds' => (int) env('ORDERMENTUM_DEBOUNCE_SECONDS', 10),
        // Client-side limits per minute. Stock API limits are undocumented (B3.1); order fetch is 24/min.
        'stock_requests_per_minute' => (int) env('ORDERMENTUM_STOCK_RPM', 40),
        'order_requests_per_minute' => (int) env('ORDERMENTUM_ORDER_RPM', 20),

        /*
         * Where an order's line items and their fields are. Line items and
         * lineItem.quantity are confirmed (support, 2026-10-26). The variant ID
         * field is NOT confirmed: candidates are tried in order until
         * `om:probe order` shows a real order. Set ORDERMENTUM_LINE_VARIANT_FIELDS
         * (comma-separated, dot paths) once known.
         */
        'order_mapping' => [
            'lines' => ['lineItems', 'lines'],
            'quantity' => 'quantity',
            'variant' => array_values(array_filter(explode(',', (string) (env('ORDERMENTUM_LINE_VARIANT_FIELDS') ?: 'variantId,variant.id,variant_id,productVariantId')))),
            'line_ref' => ['id', 'lineItemId'],
            'unit_price' => 'price',
            'sku' => ['sku', 'variant.sku', 'SKU'],
            'name' => ['name', 'productName', 'product.name'],
        ],
    ],
];
