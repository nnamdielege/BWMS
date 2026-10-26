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
        // A burst of stock changes becomes one push run after this delay.
        'debounce_seconds' => (int) env('ORDERMENTUM_DEBOUNCE_SECONDS', 10),
        // More than one pending item goes by bulk PATCH /v1/items (preferred), in chunks of this size.
        'bulk_push' => (bool) env('ORDERMENTUM_BULK_PUSH', true),
        'bulk_chunk' => (int) env('ORDERMENTUM_BULK_CHUNK', 50),
        // Pushes wait while order events are being ingested (so a push never overwrites a
        // decrement BWMS has not seen); an event older than this is treated as stuck.
        'ingest_wait_minutes' => (int) env('ORDERMENTUM_INGEST_WAIT_MINUTES', 10),

        /*
         * Webhook signature (Ordermentum signs webhooks; header name and algorithm
         * unconfirmed, asked 2026-10-27). Modes: report (check and record, never
         * reject), enforce (reject invalid), off. The path token and re-fetching
         * the order stay the guard until the scheme is confirmed.
         */
        'webhook_signature' => [
            'mode' => env('ORDERMENTUM_WEBHOOK_SIGNATURE_MODE', 'report'),
            'header' => env('ORDERMENTUM_WEBHOOK_SIGNATURE_HEADER'),
            'secret' => env('ORDERMENTUM_WEBHOOK_SIGNATURE_SECRET'),
            'algorithm' => env('ORDERMENTUM_WEBHOOK_SIGNATURE_ALGO', 'sha256'),
        ],

        /*
         * Where an order's line items and their fields are. Confirmed on a live
         * order with `om:probe order --structure --headers=both` (2026-10-27):
         * lineItems[].quantity (int) and lineItems[].variantId, which is the stock
         * API item ID (also present as variant.id). ORDERMENTUM_LINE_VARIANT_FIELDS
         * overrides the candidates (comma-separated dot paths).
         */
        'order_mapping' => [
            'lines' => ['lineItems', 'lines'],
            'quantity' => 'quantity',
            'variant' => array_values(array_filter(explode(',', (string) (env('ORDERMENTUM_LINE_VARIANT_FIELDS') ?: 'variantId,variant.id')))),
            'line_ref' => ['id', 'lineItemId'],
            'unit_price' => 'price',
            'sku' => ['sku', 'variant.sku', 'SKU'],
            'name' => ['name', 'productName', 'product.name'],
        ],
    ],
];
