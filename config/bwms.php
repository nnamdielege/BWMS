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

    // Delivery runs: orders must be in by this time (business time zone) on the business day before delivery.
    'delivery' => [
        'cutoff_time' => env('BWMS_DELIVERY_CUTOFF', '13:00'),
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
        // Secret path token. Optional once signatures are verified on a real event (set
        // ORDERMENTUM_WEBHOOK_REQUIRE_TOKEN=false); only honoured while signatures are enforced.
        'webhook_token' => env('ORDERMENTUM_WEBHOOK_TOKEN'),
        'webhook_require_token' => (bool) env('ORDERMENTUM_WEBHOOK_REQUIRE_TOKEN', true),
        // Site whose available stock is pushed and where Ordermentum orders are fulfilled.
        'site_code' => env('ORDERMENTUM_SITE_CODE', env('BWMS_DEFAULT_SITE_CODE', 'CLONTARF')),
        // A burst of stock changes becomes one push run after this delay.
        'debounce_seconds' => (int) env('ORDERMENTUM_DEBOUNCE_SECONDS', 10),
        // More than one pending item goes by bulk PATCH /v1/items (preferred), in chunks of this size.
        // PATCH has no rate limit (Ordermentum, 2026-10-27); the client spaces calls 5s apart to stay polite.
        'bulk_push' => (bool) env('ORDERMENTUM_BULK_PUSH', true),
        'bulk_chunk' => (int) env('ORDERMENTUM_BULK_CHUNK', 50),
        // Pushes wait while order events are being ingested (so a push never overwrites a
        // decrement BWMS has not seen); an event older than this is treated as stuck.
        'ingest_wait_minutes' => (int) env('ORDERMENTUM_INGEST_WAIT_MINUTES', 10),
        // Local testing only (APP_ENV local/testing): orders saved by om:simulate-webhook in
        // storage/app/private/ordermentum-fixtures are used instead of fetching them.
        'local_order_fixtures' => (bool) env('ORDERMENTUM_LOCAL_FIXTURES', false),

        /*
         * Webhook signature (confirmed by Ordermentum, 2026-10-27): header
         * x-ordermentum-signature = HMAC-SHA256(raw body, supplier sharedSecret from
         * GET /v1/suppliers/{id}). Enforced: a missing or wrong signature is 401.
         * Set the secret with `php artisan om:probe secret --write-env`.
         */
        'webhook_signature' => [
            'mode' => env('ORDERMENTUM_WEBHOOK_SIGNATURE_MODE', 'enforce'), // enforce | report
            'header' => env('ORDERMENTUM_WEBHOOK_SIGNATURE_HEADER', 'x-ordermentum-signature'),
            'secret' => env('ORDERMENTUM_WEBHOOK_SECRET'),
            'algorithm' => 'sha256',
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
            // Paths seen holding the stock item ID on that live order. The Sync screen shows the
            // variant field as confirmed when the first configured path is one of these.
            'variant_confirmed' => ['variantId', 'variant.id'],
            'line_ref' => ['id', 'lineItemId'],
            'unit_price' => 'price',
            'sku' => ['sku', 'variant.sku', 'SKU'],
            'name' => ['name', 'productName', 'product.name'],
        ],
    ],

    /*
     * Portal integration (spec B4.3-B4.4): shop.localselection.com.au.
     * Both directions are signed: X-BWMS-Key-Id, X-BWMS-Timestamp and
     * X-BWMS-Signature = hex HMAC-SHA256(timestamp . "." . raw body).
     */
    'portal' => [
        // Inbound API (/api/portal/v1). Off returns 503.
        'api_enabled' => (bool) env('PORTAL_API_ENABLED', false),
        // "keyId:secret,keyId2:secret2": two may be active at once for rotation.
        'inbound_keys' => collect(explode(',', (string) env('PORTAL_API_KEYS', '')))
            ->map(fn ($pair) => array_pad(explode(':', trim($pair), 2), 2, ''))
            ->filter(fn ($kv) => $kv[0] !== '' && $kv[1] !== '')
            ->mapWithKeys(fn ($kv) => [$kv[0] => $kv[1]])->all(),
        'timestamp_tolerance' => 300,
        // Site whose stock the portal sells and where its orders are fulfilled.
        'site_code' => env('PORTAL_SITE_CODE', env('BWMS_DEFAULT_SITE_CODE', 'CLONTARF')),

        // Outbound: BWMS -> portal stock pushes and dispatch events. Off until cutover.
        'base_url' => env('PORTAL_BASE_URL'),
        'outbound_key_id' => env('PORTAL_OUTBOUND_KEY_ID'),
        'outbound_secret' => env('PORTAL_OUTBOUND_SECRET'),
        'push_enabled' => (bool) env('PORTAL_PUSH_ENABLED', false),
        'events_enabled' => (bool) env('PORTAL_EVENTS_ENABLED', false),
        'connect_timeout' => 5,
        'timeout' => 15,
        'push_batch' => 100,
        'debounce_seconds' => (int) env('PORTAL_DEBOUNCE_SECONDS', 10),
    ],

    // bwms:dev-reset may only wipe these databases (APP_ENV=local, local host).
    'dev_reset_databases' => array_values(array_filter(explode(',', (string) env('BWMS_DEV_RESET_DATABASES', 'bwms_next')))),
];
