# BWMS Audit (Phase 1)

Audit date: 2026-10-26 · Repo: `C:\xampp\htdocs\bwms` @ `6f3756e` (main) · Read-only; no code changed.

## 0. Caveats: read these first

- **Updated 2026-10-26: production has now been checked.** Sections 1–8 were written against the local XAMPP copy (`bwms`), a dev snapshot with seed suppliers, POs and customers. The production dump (MariaDB 10.5.29) has since been compared. **§9 lists every difference, and where §9 disagrees with earlier sections, §9 wins.** In short, the schema is nearly identical, but the production *data* confirms C-1 and C-3 directly and changes the migration scope.
- **Schema drift:** the local DB ran 6 migrations that are not in the repo (`add_slug_to_products_table`, an older `create_invoices_table`, `create_usage_tracking_table` + rename, an older `create_ordermentum_products_table`, `remove_unique_sku_from_ordermentum_products`). `inventory_push_logs` is in the repo but has **not** run locally. Production may differ again. The migration tool must read the live schema (`information_schema`) and must not trust the repo's migrations.
- **I have not yet checked Ordermentum's API docs.** §5 describes what the code does. Phase 2 will cover whether those endpoints and payloads match what Ordermentum actually supports.
- The code was written as a **multi-tenant SaaS template** (Stripe billing, trials, usage limits, super-admin), and Ordermentum was bolted on in Dec 2025. Much of it never worked. Many models have no table, and many routes point at methods that don't exist.

---

## 1. Executive summary

The app does not work as a stock system. Specifically:

1. **Receiving a PO does not change stock.** The live `receive` endpoint only increments `purchase_order_items.received_quantity` (`app/Http/Controllers/Api/PurchaseOrderController.php:177-209`). No inventory update, no ledger row, no push. A correct implementation exists in `OrderService::receivePurchaseOrder` but nothing calls it, and it would fail against the real schema anyway (§6, C-2).
2. **Ordermentum is the de-facto master, not BWMS.** The only scheduled stock job is `sync:stock` at 23:00 Brisbane time (`routes/console.php:47`). It **pulls** Ordermentum's figure and **overwrites** BWMS `quantity_on_hand` with no ledger entry (`app/Console/Commands/SyncFromOrdermentumStock.php:388-393`). The push (`push:stock`) was disabled on 2026-01-20 (commit `4e5c3f3`). Every Ordermentum order webhook also triggers a full pull.
3. **Sales don't decrement correctly.** Ordermentum orders are never imported as sales. Manual sales-order fulfilment decrements `quantity_on_hand` but writes no ledger row, never touches `quantity_available`, and can drive `quantity_allocated` negative (`SalesOrderController.php:337-351`).
4. **No B2B portal integration exists.** There is no reference to shop.localselection.com.au anywhere in the code.
5. **Security is open.** Anyone can self-register (`routes/api.php:48`) and is granted the `user` role. Many mutating routes have no permission check: bulk-delete products, import products, create/update POs and SOs, export customers. A warehouse-manager can also grant themselves `admin` (§6, C-5).
6. **Server-load hazards match the incident history:** a new curl handle (and DNS lookup) for every Ordermentum call, synchronous external HTTP inside DB transactions in FPM requests, a webhook-triggered full-catalogue sync with no de-duplication, queue `retry_after` (90s) shorter than the job timeout (300s) so jobs re-run while still running, and synchronous SMTP + mPDF with a remote font fetch in requests (§7).

**Worth migrating (pending prod check):** products (incl. Ordermentum IDs), product categories, suppliers, purchase orders + items, customers, current on-hand per product/warehouse (as opening balances), users (names/emails only, re-assign roles). Everything SaaS-related (subscriptions, payments, invoices, usage, webhook_events, plans) should be dropped.

---

## 2. Stack & runtime

| Item | Value |
|---|---|
| Framework | Laravel 12, PHP ^8.2, API prefix `api/v1` (`bootstrap/app.php:19`) |
| Front end | Vue 3 SPA (vue-router, Pinia), served by catch-all `routes/web.php`; **not** Inertia |
| Auth | Sanctum bearer tokens, stored in `localStorage` (`resources/js/stores/auth.js:6-7`) |
| Roles | spatie/laravel-permission (guard `api`) **plus** a free-text `users.role` column (e.g. `"admin,warehouse-manager"`). There are two sources of truth, and the middleware queries the spatie tables by hand (`app/Http/Middleware/CheckRole.php`, `CheckPermission.php`) |
| PDF | **Both** DomPDF (download, `PurchaseOrderController.php:240`) and mPDF (email attachment, `app/Mail/PurchaseOrderMail.php:51`) render the same Blade template |
| Queue / cache | `database` queue, `file` cache (local .env). Prod values unknown |
| Mail | SMTP, sent synchronously in-request |
| Other deps | maatwebsite/excel, stripe-php, Pusher configured in .env but not used in code |
| Scheduler | `routes/console.php`. Needs `schedule:run` cron; whether it runs on the VPS is unknown |
| Tests | 4 placeholder tests (`tests/Feature/*Test.php::test_example`). No real coverage |

---

## 3. What the app does today, module by module

"Works" means the code path is complete and would run against the current schema. It does not mean the result is correct.

| Module | Live code | Status |
|---|---|---|
| **Products** | `ProductController` (CRUD, duplicate, bulk update/delete, export, pricing/movement history); `ProductsImport`; artisan `import:variants-fresh` (CSV → products, keyed on `ordermentum_variant_id`) | CRUD works. `search`, `lowStock`, `outOfStock`, `import`, `getInventory` are routed but **don't exist** (500). No pack sizes or units: `unit_of_measure` is `"unit"` on 118/120 rows, and the pack size only appears in the name ("…330ml x 12"). `cost` = 0 on 113/120 rows, so all valuation figures are meaningless. One barcode on file. |
| **Inventory** | `InventoryController` (index, adjust, transfer, transactions, low/out-of-stock) | Mutable `inventory` row per product × warehouse. Adjust/transfer write an `inventory_transactions` row but edit quantities in place. `summary`, `inventoryValue`, `stockCount`, `getByProduct`, `getByWarehouse` are routed but **don't exist**. Adjust/transfer are **gated by SaaS usage limits**: a user with no subscription row gets 403 (`InventoryController.php:95-107`, `UsageTrackingService.php:54-61`). |
| **Warehouses / locations / bins** | `WarehouseController`, `WarehouseLocationController`, `BinController` | Basic CRUD. `warehouse_locations` and `bins` are two overlapping models with 0 rows. Inventory has a free-text `bin_location`. 3 warehouses exist, but stock exists only in WH 1. |
| **Suppliers** | `SupplierController` CRUD, `supplier_contacts` table (unused, 0 rows) | Works. `email` is UNIQUE and required, as is `address`. |
| **Purchase orders** | `PurchaseOrderController` (CRUD, receive, cancel, PDF download, email, send-to-supplier) | Create/list/edit work. **Receive doesn't touch stock.** There is no partial-received status (enum is `draft,pending,received,cancelled`). The PDF has had Blade syntax errors (`storage/logs/laravel.log`, 2026-03-27). `destroy` is routed via `apiResource` but doesn't exist. |
| **Sales orders** | `SalesOrderController` (CRUD, fulfill, cancel) | Manual entry only. Allocation and fulfilment logic are wrong (§6). `destroy` is missing. Ordermentum orders are not imported. |
| **Customers** | `CustomerController`, `CustomersImport` | CRUD. 3 rows locally (seed). |
| **Ordermentum** | `sync:stock` (pull), `push:stock` (push, unscheduled), `InventoryPushService` (push after manual adjust/transfer), `Webhooks\OrdermentumWebhookController` → `SyncOrdermentumStock` job, `OrdermentumApiService` (used only by `ordermentum:test`), CSV importers | See §5. |
| **Notifications** | `NotificationService`, `InventoryObserver`, PO/SO observers, `inventory:check-low-stock` daily | In-app only. Sent to users whose `role` column is **exactly** `'admin'` (`NotificationService.php:23`), so a user with `"admin,warehouse-manager"` gets nothing. Low-stock threshold is hard-coded to 20 in the observer (`InventoryObserver.php:14`), while the setting says 15. |
| **Reports / dashboard** | `ReportController`, `DashboardController` | Sales/purchase/inventory/product-performance. Low stock is hard-coded `<= 20` (`ReportController.php:228,256`). `inventoryStats` and `recentTransactions` are missing. |
| **Import/export** | `ImportController`, `ExportController`, `app/Exports/*` | Excel/CSV. No permission middleware. |
| **Users / roles** | `UserController`, `RoleController` | Works, with privilege escalation (§6). |
| **Settings** | `SettingController`, `settings` table (16 keys) | `company_name` = "Boundless Analytics Pty. Limited", `timezone` = `America/New_York` (not used; `config/app.php:68` is UTC). `GET /settings/displaySettings` is shadowed by `/settings/{key}` (`routes/api.php:252,255`). |
| **SaaS billing** | `SubscriptionController` (1,031 lines), `PlanChangeController`, Stripe webhook, invoices, usage tracking, super-admin dashboard | Irrelevant to an internal WMS. **Drop.** |
| **Dead / stub** | `OrderService`, `InventoryService` (registered as singletons, never resolved); `Api\OrdermentumController`, `Api\OrdermentumWebhookController` (not routed; reference non-existent `OrdermentumService` and tables); 42 models with no table (`Aisle`, `Barcode`, `PickList`, `StockTake`, `CycleCount`, `Shipment`, `Tenant`, `OrdermentumConnection`, …, all 10-line stubs); `product_variants` table (id + timestamps only) | Ignore. |

---

## 4. Data model

### 4.1 Tables and columns (from repo migrations; local row counts in brackets)

**Domain tables**

| Table [rows] | Columns | Notes |
|---|---|---|
| `products` [120] | id, ordermentum_variant_id (uuid, unique, null), ordermentum_product_id (uuid), sku (unique), ordermentum_sku, slug (unique), barcode (unique, null), name, description, category_id → product_categories (restrict), cost dec(10,2), price dec(10,2), unit_of_measure varchar(50), reorder_point, reorder_quantity, weight, dimensions, notes, is_active, sales_code, timestamps, deleted_at | All 120 have an Ordermentum variant ID. reorder_point is NULL on all rows. |
| `product_categories` [30] | id, name, slug (unique), description, parent_id → self (cascade), is_active, timestamps, deleted_at | Built from the Ordermentum "Categories" column (first pipe segment). |
| `ordermentum_products` [119] | id, sku (unique per repo; local migration removed it), product_id uuid, variant_id uuid unique, name, description, price, cost, tax_code, sales_code, categories, enabled, base_plus_tax, has_image, created/updated_at_ordermentum, created/updated_by, reference_id, groups, sales_unit, multiples_of, weight, pricing_unit, highlight_badge, minimum_quantity, delivery_days json, shipped_quantity, stock_availability, timestamps | Raw staging copy of an Ordermentum CSV export (`import:variants`). Useful as a reference for the SKU ↔ variant mapping. 1 SKU differs from `products`. |
| `warehouses` [3] | id, name, code (unique), address, city, state, postal_code, country, phone, email, manager, is_active, timestamps, deleted_at | Seed names ("Main Warehouse", …). |
| `inventory` [120] | id, product_id → products (cascade), warehouse_id → warehouses (cascade), quantity_on_hand, quantity_available, quantity_allocated, quantity_on_order (all int), bin_location, notes, timestamps; UNIQUE(product_id, warehouse_id) | **This is the stock figure today.** All rows are in WH 1. Total on-hand 1,834 units. `cascade` on product delete wipes stock history. |
| `inventory_transactions` [19] | id, product_id, warehouse_id, type ENUM(adjustment, damage, count, transfer_in, transfer_out, sale, purchase, return), quantity (signed in some paths, unsigned in others), quantity_before, quantity_after, reason, reference, notes, user_id, timestamps | Partial log, not a ledger: only adjust/transfer write here. Last row 2025-10-26. Sign convention is inconsistent: `transfer_out` stores a negative number (`InventoryController.php:310`), while adjust stores the raw signed input. |
| `stock_adjustments` [0], `stock_transfers` [0], `batches` [0] | adjustment_number, qty before/adjusted/after, adjustment_type, reason, user_id… / transfer_number, from/to warehouse, status, requested_by, approved_by… / product_id, batch_number (unique, global), manufacture_date, expiry_date, quantity | Never written by any code. No batch/expiry data exists. |
| `warehouse_locations` [0], `bins` [0] | warehouse_id, location_code, location_type ENUM(zone…bin), parent_id… / warehouse_id, bin_code, aisle, rack, level, position, capacity | Unused. |
| `suppliers` [4] | id, supplier_code (unique), company_name, contact_name, email (unique), phone, mobile, address, city, state, postal_code, country, tax_id, payment_terms, credit_limit, notes, is_active, timestamps, deleted_at | Seed data locally. |
| `supplier_contacts` [0] | supplier_id, name, position, email, phone, mobile, is_primary, notes | Unused. |
| `purchase_orders` [2] | id, order_number (unique), supplier_id (cascade), warehouse_id (restrict), order_date, expected_date, received_date, status ENUM(draft, pending, received, cancelled), subtotal, tax_rate, tax, shipping, discount, total, notes, timestamps, deleted_at | `supplier` cascade-delete destroys PO history. |
| `purchase_order_items` [2] | id, purchase_order_id (cascade), product_id (restrict), quantity, received_quantity, unit_price, discount, tax, subtotal, timestamps | No unit/pack, no batch, no expiry. |
| `customers` [3] | id, customer_code (unique), company_name, name, email (unique), phone, mobile, address, city, state, postal_code, country, tax_id, payment_terms, credit_limit, notes, is_active, timestamps, deleted_at | No Ordermentum purchaser ID or portal user ID. |
| `customer_addresses` [0] | customer_id, address_type, label, line1/2, city, state, postal_code, country, contact_name, phone, is_default | Unused. |
| `sales_orders` [4] | id, order_number (unique), customer_id (cascade), warehouse_id (restrict), order_date, expected_date, fulfilled_date, status ENUM(draft, pending, processing, fulfilled, cancelled), subtotal, tax_rate, tax, shipping, discount, total, notes, timestamps, deleted_at | No channel / external order ID. |
| `sales_order_items` [6] | id, sales_order_id, product_id (cascade), quantity, quantity_fulfilled (never written), unit_price, discount, tax, subtotal, bin_location, notes | |
| `inventory_push_logs` [table missing locally] | inventory_id, product_id, warehouse_id, sku, pushed_quantity, status, pushed_at, notes | Written by `push:stock`. Insert errors are swallowed (`PushStockToOrdermentum.php:334-338`). |
| `settings` [16] | key (unique), value, type, group, description | |
| `notifications` [69] | user_id, type, title, message, icon, color, link, is_read, read_at | Custom table (not Laravel's notifications). |

**Auth / framework:** `users` [7] (id, name, email, password, phone, avatar, role TEXT, is_active, remember_token, timestamps), `roles` [4: admin, user, warehouse-manager, delete-product], `permissions` [42], `model_has_roles` [6], `model_has_permissions` [0], `role_has_permissions` [57], `personal_access_tokens` [55], `sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs` [25 pending, dated 2026-03-14, so no worker runs locally], `job_batches`, `failed_jobs` [9, PO email template errors 2026-01-01].

**SaaS (drop):** `subscription_plans`, `subscriptions`, `subscription_limits`, `subscription_audits`, `payments`, `invoices`, `transactions`, `usage_records`, `usage_trackings` [112], `usage_reset_logs`, `webhook_events` [197, Stripe only].

### 4.2 Relationships

```
product_categories 1─* products 1─* inventory *─1 warehouses
                   (self parent)  1─* inventory_transactions *─1 warehouses, *─1 users
products 1─1 ordermentum_products   (by ordermentum_variant_id = variant_id, no FK)
suppliers 1─* purchase_orders 1─* purchase_order_items *─1 products
customers 1─* sales_orders 1─* sales_order_items *─1 products
purchase_orders, sales_orders *─1 warehouses
users *─* roles *─* permissions   (spatie)  + users.role free text
```

### 4.3 Migration worthiness

| Data | Migrate? | Why / how |
|---|---|---|
| products + categories | **Yes** | Core catalogue with Ordermentum variant/product IDs. Pack size must be parsed from names or set manually. Cost needs back-filling. |
| ordermentum_products | Reference only | Use it to validate the SKU ↔ variant mapping during import. |
| suppliers | **Yes** (if prod has real ones) | Local rows are seed data. |
| purchase_orders + items | **Yes**, as historical, read-only | Statuses map as draft→draft, pending→sent (or draft? needs confirmation), received→received, cancelled→cancelled. `received_quantity` is not trustworthy as stock evidence. |
| inventory (on_hand) | **Yes**, as opening-balance ledger entries | One entry per product × warehouse at the cutover timestamp. Must be reconciled against Ordermentum and a physical count first: the current figure is whatever Ordermentum said at the last 23:00 pull plus any manual edits since. |
| inventory_transactions | Optional archive | Too sparse and inconsistent to use as a ledger. |
| customers | Probably | Need mapping to Ordermentum purchaser IDs and portal accounts (not present today). |
| sales_orders | Archive only | Manual entries, not channel orders. |
| users | Names/emails | Re-assign roles under the new model. Force password reset. |
| settings | Selectively | Most values are SaaS-template defaults. |
| Everything SaaS, notifications, tokens, jobs | **No** | |

### 4.4 SQL to run on the production DB before Phase 3

```sql
-- row counts
SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name;
-- migrations actually applied
SELECT migration FROM migrations ORDER BY id;
-- stock sanity
SELECT warehouse_id, COUNT(*), SUM(quantity_on_hand), SUM(quantity_on_hand<0),
       SUM(quantity_allocated<0), SUM(quantity_available <> quantity_on_hand - quantity_allocated)
FROM inventory GROUP BY warehouse_id;
-- product mapping coverage
SELECT COUNT(*), SUM(ordermentum_variant_id IS NULL), SUM(cost = 0), SUM(deleted_at IS NOT NULL) FROM products;
-- PO receipt state
SELECT status, COUNT(*), SUM(received_date IS NOT NULL) FROM purchase_orders GROUP BY status;
```

---

## 5. Ordermentum integration (as coded)

### 5.1 Auth
- `POST https://app.ordermentum.com/v1/auth` with `{username, password}` of a **user account** (`ORDERMENTUM_USERNAME/PASSWORD`). The code expects HTTP 201 and `access_token`.
- Four separate implementations: `OrdermentumApiService::authenticate` (caches the token 23h), and copies in `SyncFromOrdermentumStock:148`, `PushStockToOrdermentum:178` and `InventoryPushService:104`. The last three **log in again on every run or every push**, with no token caching.
- `InventoryPushService.php:138-141` notes a 403 "2FA required" case. If 2FA is enabled on that account, the integration stops working silently.
- Three of the four read credentials with `env()` directly. If production runs `php artisan config:cache`, `env()` returns null and auth fails. **Unknown** whether prod caches config.
- `CURLOPT_SSL_VERIFYPEER=false` in every curl implementation, so credentials are exposed to MITM.

### 5.2 Endpoints called

| Purpose | Endpoint | Where | Live? |
|---|---|---|---|
| Read variant stock | `GET app.ordermentum.com/v1/products/{productId}/variants/{variantId}` → reads `available` / `quantity` / `stock` / `outOfStock` | `SyncFromOrdermentumStock.php:193` | **Yes**, nightly 23:00 plus on every order webhook |
| Write stock | `PUT stock.ordermentum.com/v1/items/{variantId}` body `{available, tracked:true}` | `PushStockToOrdermentum.php:227` | No (unscheduled since 2026-01-20; manual only) |
| Write stock (alt) | `PUT stock.ordermentum.com/v1/products/{productId}/variants/{variantId}` body `{quantity, available, outOfStock}` | `InventoryPushService.php:151` | Yes: called synchronously after every manual adjust/transfer. **Different endpoint from push:stock**, whose comment says `/items/{id}` is the "correct endpoint". This path likely always fails, but the failure is only logged. Unverified. |
| Products/variants/orders/venues | `/products`, `/variants?supplierId=`, `/orders`, `/venues`, `/inventory`, `/inventory/batch`, `/products/{id}/inventory` | `OrdermentumApiService` | Only `/products` is used, by `ordermentum:test`. The rest are unused, and several look guessed. |

Reading from `app.ordermentum.com` while writing to `stock.ordermentum.com` means the pull may not read the value the push wrote. Phase 2 will confirm against the docs.

### 5.3 Sync direction & triggers

| Trigger | Direction | Effect |
|---|---|---|
| Cron 23:00 Australia/Brisbane daily (`routes/console.php:47`) | **Ordermentum → BWMS** | Overwrites `inventory.quantity_on_hand` and `quantity_available` for the **first warehouse** only. No ledger row. |
| Webhook `POST /api/v1/webhooks/ordermentum`, events `order_created` / `order_updated` (`Webhooks/OrdermentumWebhookController.php:52-72`) | **Ordermentum → BWMS** | Dispatches `SyncOrdermentumStock`, which runs `sync:stock --force` for the whole catalogue. The order payload itself is ignored. Other events are only logged. |
| Manual adjust/transfer in UI | BWMS → Ordermentum | Synchronous push via `InventoryPushService` inside the DB transaction. |
| `php artisan push:stock` (manual) | BWMS → Ordermentum | Pushes WH-1 on-hand for every mapped product. |
| History | | 2025-12-30: pull **and** push every 30 min in business hours, then a pull+push closure at 06/09/12/15/18. 2026-01-20: push disabled, nightly pull only. The pull-then-push pairing was a no-op: it pushed back the value it had just pulled. |

### 5.4 Webhook verification
- HMAC-SHA256 of the raw body compared with `X-Ordermentum-Signature` (`Webhooks/OrdermentumWebhookController.php:264-282`). Whether Ordermentum signs webhooks this way is **unverified**; the code comment says "if Ordermentum sends one". The local .env has the secret set. If Ordermentum doesn't send that header, every real webhook gets a 401. If the secret is unset, or `env()` returns null under config cache, verification is skipped and anyone can trigger full syncs.
- Event names (`order_created`, `eventType`/`entityId` keys) are also unverified. `webhook:simulate` builds payloads from the same assumptions.

### 5.5 SKU mapping
- `products.ordermentum_variant_id` (unique) + `ordermentum_product_id`, populated by `import:variants-fresh` from a CSV with added `ordermentum_variant_id` / `ordermentum_product_id` columns (`ImportVariantsFromCsv.php:60-98`). Upserts key on variant ID.
- `products.barcode` is filled from a CSV column named `No` (`ImportVariantsFromCsv.php:81`), which is probably not a barcode.
- Mapping is 1 BWMS product : 1 Ordermentum variant. There is no unit conversion. Ordermentum `sales_unit` is `unit` and `multiples_of` is 1 for all rows, so a "carton of 12" product is one unit on both sides.

---

## 6. Bugs, broken flows and risks, ranked

Severity: **C** = loses or corrupts stock/data or is a security hole; **H** = core flow broken or wrong numbers; **M** = incorrect behaviour or partial failure; **L** = hygiene.

### Critical

| # | Finding | Location |
|---|---|---|
| C-1 | **PO receipt never increases stock.** `receive` only adds to `received_quantity`. No inventory update, no transaction row, no push. | `PurchaseOrderController.php:177-209` |
| C-2 | The correct-looking `OrderService::receivePurchaseOrder` and `fulfillSalesOrder` are dead code. They would also fail if wired up: they use `quantity_received` (column is `received_quantity`), PO statuses `completed`/`partial` (not in enum), and transaction types `in`/`out` (not in enum since `2025_10_08_060813`). | `app/Services/OrderService.php:345-479, 126-249, 159-169`; `AppServiceProvider.php:25-26` |
| C-3 | **Nightly pull overwrites BWMS stock with Ordermentum's number**, with no ledger entry and no conflict check. Any receipt or adjustment made in BWMS during the day is lost at 23:00 unless it was also pushed. | `SyncFromOrdermentumStock.php:361-411`, `routes/console.php:47` |
| C-4 | Pull sets stock to **1** when Ordermentum returns `outOfStock:false` without a quantity field. | `SyncFromOrdermentumStock.php:227-229` |
| C-5 | **Privilege escalation:** `warehouse-manager` can call `POST users/{user}/assign-role` with `role=admin`, including on themselves. | `routes/api.php:142-148`, `UserController.php:47-80` |
| C-6 | **Open registration + unprotected routes.** `POST /register` is public and grants the `user` role. These routes have only `auth:sanctum`: products bulk-update/bulk-delete/duplicate/export (`api.php:168-172`), all `export/*` incl. customers and suppliers (`:283`), `import/*` (`:292`), sales-orders and purchase-orders create/update (`:196, :203`), PO download/email/send-to-supplier (`:208-222`, which lets any user email arbitrary addresses from the company SMTP), inventory transactions (`:185`), search (`:302`). | `routes/api.php` as cited; `AuthController.php:16-54` |
| C-7 | Webhook endpoint can be **unauthenticated** (secret unset, or `env()` null under config cache), and each call triggers a full-catalogue Ordermentum crawl. That is an amplification/DoS vector against our own server and the Ordermentum account. | `Webhooks/OrdermentumWebhookController.php:266-270, 107, 121` |
| C-8 | Webhook error response returns the exception message to the caller, and the log line includes the full payload. | `Webhooks/OrdermentumWebhookController.php:82-90` |

### High

| # | Finding | Location |
|---|---|---|
| H-1 | SO creation "allocates" by incrementing `quantity_allocated` but never decrements `quantity_available`, while the stock check reads `quantity_available`. Concurrent or sequential orders can oversell. No row lock. | `SalesOrderController.php:138-154` |
| H-2 | SO fulfil subtracts the full line quantity from `quantity_allocated` even for orders that were never allocated (created as draft), so allocated goes negative. It leaves `quantity_available` unchanged, so available ends up greater than on-hand. It writes no `inventory_transactions` row, never sets `quantity_fulfilled`, has no partial fulfilment, and doesn't push to Ordermentum. The nightly pull then masks the drift by resetting available = on_hand. | `SalesOrderController.php:337-355` |
| H-3 | SO update/cancel release allocations with read-modify-write, no lock, and no check that the order was ever allocated (status may have been draft at creation but pending now). | `SalesOrderController.php:236-247, 397-407` |
| H-4 | Ordermentum orders are not recorded in BWMS at all. Stock only follows Ordermentum through the overwrite in C-3. There is no order history, no pick/dispatch, and no idempotency key. | `Webhooks/OrdermentumWebhookController.php:97-108` |
| H-5 | Manual adjust has no row lock (read on-hand, write on-hand + delta) and does a synchronous HTTP push **inside** the open DB transaction: up to 2×30s while the row and connection are held. The same applies to transfer. | `InventoryController.php:119-209, 266-382` |
| H-6 | Push targets one warehouse only (`Warehouse::first()`) and pushes `quantity_on_hand`, not available-to-sell. Allocated stock is advertised as sellable, and stock in other warehouses is ignored (or, after a transfer, the destination warehouse's figure alone is pushed). | `PushStockToOrdermentum.php:44, 90`; `InventoryController.php:356` |
| H-7 | `sync:stock --dry-run` still **creates** inventory rows (`firstOrCreate` runs before the dry-run check). | `SyncFromOrdermentumStock.php:254-265` |
| H-8 | Sync and push commands `return;` (exit 0) on auth failure or no warehouse. The queue job treats that as success, so failures are invisible. | `SyncFromOrdermentumStock.php:37-55`; `SyncOrdermentumStock.php:50-58` |
| H-9 | PO update deletes and recreates all lines with `received_quantity = 0`, in any status (including received), erasing receipt history. | `PurchaseOrderController.php:139-166` |
| H-10 | No partial-receipt status. A partially received PO stays `pending`. `received_quantity` can exceed `quantity` (no server-side cap). Cancel is allowed after partial receipt. Double-submit counts twice. | `PurchaseOrderController.php:181-203, 214-229`; PO enum migration |
| H-11 | PO/SO totals (`subtotal`, `tax`, `total`) are taken from the client, not computed. | `PurchaseOrderController.php:76-81`; `SalesOrderController.php:115-120` |
| H-12 | Stock adjust/transfer are blocked for any user without a SaaS subscription row (403 "No active subscription"). | `InventoryController.php:95-107`; `UsageTrackingService.php:54-61` |
| H-13 | Routes to non-existent methods return 500: `InventoryController::{getByProduct,getByWarehouse,summary,inventoryValue,stockCount}`, `ProductController::{getInventory,search,lowStock,outOfStock,import}`, `DashboardController::{inventoryStats,recentTransactions}`, `SubscriptionController::handlePaypalWebhook`, `SalesOrderController::destroy`, `PurchaseOrderController::destroy`. | `routes/api.php:181-190, 162-171, 122-123, 65, 196, 203` |

### Medium

| # | Finding | Location |
|---|---|---|
| M-1 | Order numbers come from `count()+1`. Concurrent creates collide on the UNIQUE index, and soft-deleted rows are excluded from `count()`, so a soft-delete guarantees a future collision (500). | `PurchaseOrderController.php:371-376`; `SalesOrderController.php:102` |
| M-2 | `cascade` deletes: deleting a product wipes its inventory, transactions and SO lines. Deleting a supplier or customer wipes their POs/SOs. Soft deletes mitigate this only via the UI path. | migrations `create_inventory_table`, `create_inventory_transactions_table`, `create_purchase_orders_table`, `create_sales_orders_table`, `create_sales_order_items_table` |
| M-3 | `inventory_transactions.quantity` sign convention is inconsistent (negative for transfer_out, signed input for adjust), so you can't sum it into a balance. | `InventoryController.php:149, 310, 322` |
| M-4 | Low-stock logic is inconsistent: the observer hard-codes 20, the setting says 15, reports hard-code 20, and the inventory filter uses `reorder_point` (NULL everywhere). Low and out-of-stock alerts share one cache key, so an item that just went "low" won't trigger "out of stock" for 24h. | `InventoryObserver.php:14,17`; `CheckLowStock.php:18-21`; `ReportController.php:228,256`; `InventoryController.php:57-60` |
| M-5 | Notifications go only to `users.role = 'admin'` exactly, so multi-role admins miss them. | `NotificationService.php:23` |
| M-6 | Two role systems (`users.role` text and spatie tables) are kept in sync by hand. `SuperAdminMiddleware` uses spatie `hasRole`, while the other middleware run raw queries. | `UserController.php:47-80`; `Middleware/*` |
| M-7 | Two PDF engines for one template: DomPDF (remote disabled, drops the Google Font) for download, mPDF (fetches the remote `@import`) for email. Output differs. The logo is `.webp`; support in either engine depends on the GD build (unverified). | `PurchaseOrderController.php:240`; `PurchaseOrderMail.php:51`; `resources/views/purchase-orders/pdf.blade.php:7,178` |
| M-8 | PO email is sent synchronously in the HTTP request (SMTP and PDF render). A failure returns the exception text to the client. `failed_jobs` shows template errors (`$emailMessage` undefined, 2026-01-01). | `PurchaseOrderController.php:288-321, 341-365` |
| M-9 | User-supplied `sort_by` goes straight into `orderBy`. Identifiers are quoted, so this isn't injection, but it's an error/enumeration vector. | `SalesOrderController.php:47-49`; `ProductController.php:56` |
| M-10 | `transfer` returns 422 from inside an open `DB::beginTransaction()` without rolling back. | `InventoryController.php:266-279` |
| M-11 | `InventoryPushService` writes `last_pushed_at`, which is neither a column nor fillable, so it's silently dropped. There is no record of successful pushes from the UI path. | `InventoryPushService.php:81` |
| M-12 | Schema drift between repo migrations and the DB (see §0). `inventory_push_logs` insert failures are swallowed. | `PushStockToOrdermentum.php:318-339` |
| M-13 | Timezones are inconsistent: app is UTC, settings say America/New_York, schedules use Australia/Brisbane. `order_date` and `whereDate('created_at', today())` (SO numbering) are UTC days, not Brisbane days. | `config/app.php:68`; `SalesOrderController.php:102` |
| M-14 | Product index is N+1: one inventory query per product row. | `ProductController.php:63-67` |
| M-15 | `GET /settings/displaySettings` is unreachable (shadowed by `/{key}`). | `routes/api.php:252,255` |

### Low

| # | Finding | Location |
|---|---|---|
| L-1 | Sanctum token stored in `localStorage` (XSS → token theft). Tokens never expire (55 rows locally). | `resources/js/stores/auth.js:6-7`; `config/sanctum.php` |
| L-2 | Super-admin `setInterval` pollers are never cleared, so they stack on each SPA visit. | `SuperAdminDashboard.vue:200`; `AuditLogs.vue:244` |
| L-3 | 42 stub models with no tables; unused `product_variants`, `stock_adjustments`, `stock_transfers`, `batches`, `bins`, `warehouse_locations`, `supplier_contacts`, `customer_addresses` tables. | `app/Models/*` |
| L-4 | Unused import of non-existent `Api\Admin\AdminDashboardController`. | `routes/api.php:3` |
| L-5 | `'\nStep 1…'` single-quoted strings print a literal `\n`. | `SyncFromOrdermentumStock.php:51,115,135` |
| L-6 | Commit history is "update"/"Update" with no messages, so it can't be used to reconstruct intent. | `git log` |

---

## 7. Server-side risks (FD exhaustion, DNS failures)

I found no single obvious socket leak: every `curl_init` is paired with `curl_close` (a no-op since PHP 8; handles are freed when they go out of scope). The code does have several patterns that **multiply** sockets and DNS lookups and that **hold PHP-FPM workers**. Together they explain the symptoms well. I am inferring this; I haven't confirmed it against server logs.

| # | Risk | Evidence | Effect |
|---|---|---|---|
| S-1 | **Fresh curl handle, and so a fresh DNS lookup and TLS handshake, per API call**, with no token reuse. `sync:stock` does 1 auth + 1 GET per product (≈121 handles per run). Each UI push does auth + PUT (2 handles). | `SyncFromOrdermentumStock.php:160-171, 195-207`; `InventoryPushService.php:115-126, 159-173` | Each lookup opens a UDP socket to the resolver. Under bursts (below) that means hundreds of lookups per minute from one host. |
| S-2 | **Webhook → full-catalogue sync with no de-duplication.** Each `order_created`/`order_updated` queues a complete sync. The job is not `ShouldBeUnique` and has no `WithoutOverlapping` middleware. | `Webhooks/OrdermentumWebhookController.php:107,121,248`; `Jobs/SyncOrdermentumStock.php` | A morning order rush of N orders (each firing created + updated) queues 2N full syncs ≈ 2N×121 HTTP calls. |
| S-3 | **Queue `retry_after` 90s < job `$timeout` 300s.** A sync longer than 90s is released and picked up again by another worker while the first is still running. `$tries = 3`. | `config/queue.php:42`; `Jobs/SyncOrdermentumStock.php:17-18` | Up to 3 concurrent copies of the same crawl per dispatch. |
| S-4 | **Synchronous external HTTP inside FPM requests and inside DB transactions.** Up to 2×30s per adjust/transfer with a row lock and DB connection held. | `InventoryController.php:181-206, 353-379` | A slow or unreachable Ordermentum (including DNS failure) ties up FPM workers, which then exhausts the pool. That in turn raises the open-FD count. |
| S-5 | Long-running loop with no time budget: `sync:stock` iterates all products sequentially with a 30s timeout each. No connect timeout is set, so DNS stalls count against the 30s total. | `SyncFromOrdermentumStock.php:79-100, 202` | Worst case ~1h per run with 120 products. Combined with S-3, runs pile up. |
| S-6 | **mPDF fetches `fonts.googleapis.com` on every PO email render**, synchronously, in-request. | `pdf.blade.php:7`; `PurchaseOrderMail.php:50-54` | One more outbound DNS/HTTP call per email, and a request that hangs when DNS is broken. |
| S-7 | **Synchronous SMTP** in-request. | `PurchaseOrderController.php:288, 341` | Same worker-holding pattern. |
| S-8 | `OrdermentumApiService` uses the `Http` facade (Laravel defaults: 30s timeout, 10s connect) and builds a new Guzzle client per call. `getAllVariants` loops up to 50 pages. It is only used by `ordermentum:test` today. | `OrdermentumApiService.php:100-120, 286-308` | Same per-call handle pattern if anything adopts it. |
| S-9 | Front-end pollers: notification count every 30s per open tab, plus the stacking super-admin pollers (L-2). | `NotificationBell.vue:124`; `SuperAdminDashboard.vue:200` | Steady FPM load. Minor alone. |
| S-10 | Unauthenticated webhook (C-7) lets anyone outside trigger S-2/S-3. | | |
| S-11 | `CURLOPT_SSL_VERIFYPEER=false`. Not an FD issue, but it means a hijacked DNS answer would be accepted silently. | all curl call sites | |

**Requirements for the rebuild:**
- One HTTP client per integration, reused for the life of the worker, with explicit connect and total timeouts and token caching.
- No external calls in web requests or inside DB transactions.
- Sync jobs must be per-SKU (or batched), unique and debounced, with `retry_after` greater than the timeout and backoff.
- Run supervised queue workers with `--max-jobs` / `--max-time` so workers recycle.
- Use a local caching resolver (systemd-resolved/nscd) on the VPS. This is an ops item to confirm.

---

## 8. Open questions for you (Phase 1 only)

1. Can I get read-only access to the **production** DB (or a fresh dump) to rerun §4.4? Migration scoping depends on it.
2. Is `php artisan config:cache` run on the VPS? If yes, the Ordermentum sync and webhook verification have probably been silently broken (§5.1, §5.4).
3. Is a queue worker running in prod, and with what options? Is `schedule:run` in cron?
4. Has Ordermentum ever been configured to send webhooks to this app? If so, which events and what signing scheme?
5. Today, where is stock actually managed day-to-day: Ordermentum's back office, this app, or a spreadsheet? (The nightly pull suggests Ordermentum.)
6. Do the three warehouses exist physically, or is it one site?
7. Is the `localselection` database on this machine the B2B portal's? I'll read its schema in Phase 2 to design the integration.

---

**Phase 1 complete. Stopping here for your approval before Phase 2.**

---

## 9. Production comparison (added 2026-10-26)

Source: `bwms_prod_2026-10-26.sql.gz` (MariaDB 10.5.29), restored locally into `bwms_legacy_prod` and read only through a SELECT-only user. The dump and any data from it are **not** committed. Only schema facts and aggregate counts appear here.

### 9.1 Schema differences vs the local copy

| Area | Local copy (§4) | Production | Effect |
|---|---|---|---|
| Tables | 48 | 49: adds `inventory_push_logs` (0 rows) | §4.1 note "table missing locally" applies to local only. In prod the table exists, but `push:stock` never recorded a push. |
| Migrations run | 52 | 47. Prod has **none** of the 6 local-only migrations (§0), and has `inventory_push_logs`. Every repo migration has run in prod. | Prod matches the repo exactly; the local copy was the outlier. |
| `product_categories.slug` | NOT NULL | NULL allowed | Migration must tolerate NULL slugs. |
| `product_categories.parent_id` FK | ON DELETE SET NULL | ON DELETE CASCADE (as in repo) | Deleting a parent category in prod would have deleted its children. Not relevant to migration. |
| `roles.description` | present | absent | None. |
| Everything else (columns, types, indexes, other FKs) | — | Identical | §4.1 column lists hold for prod. |

### 9.2 Data differences that change findings

| Finding | Local copy | Production | Consequence |
|---|---|---|---|
| **C-1 confirmed in practice** | 2 POs | **60 POs / 349 lines**: 1 draft, 11 pending, **47 received**, 1 cancelled. All 261 lines on received POs have `received_quantity = 0`, and **no PO has a `received_date`**. | Staff marked POs "received" by editing the status (`PurchaseOrderController::update` allows it). The receive flow was never used, so **no PO ever changed stock**. Legacy `received_quantity` cannot be used as receipt history. |
| **C-3 confirmed: stock figures are not trustworthy** | On-hand 1,834, no negatives | 120 inventory rows, all in warehouse 1. **Total on-hand −129; 35 rows negative (min −809, max 1,991); 18 zero.** Last update 2026-10-22 23:01, the final nightly `sync:stock` before shutdown. | The nightly pull copied Ordermentum's own (oversold/negative) figures into BWMS. Strongly supports D-7: opening balances must come from the cutover stocktake, never from legacy `inventory`. |
| H-11 (client-supplied totals) | — | 21 of 60 POs have `total` ≠ sum of line subtotals (0 zero-total). | Migrate the stored PO total as historical and recompute line totals. Flag the mismatches in the reconciliation report rather than "fixing" them. |
| Products | 120, all mapped | **123**: 120 with Ordermentum variant ID, **3 without** (added in prod, no inventory row). 113 have `cost = 0`. 40 have a reorder point. 1 barcode. Units: `unit` 118, `EA` 3, blank 2. No duplicate SKUs. No soft-deleted products. | 3 products need manual Ordermentum mapping. Reorder points are worth migrating (40). Cost back-fill is still required (D-11). |
| `ordermentum_products` staging table | 119 rows | **0 rows** | Mapping comes only from `products.ordermentum_*_id`. |
| Warehouses | 3 (seed) | 2: one live (all stock and POs), one soft-deleted and unused | Map the live warehouse to Clontarf Warehouse. Ignore the deleted one. |
| Suppliers / customers | 4 / 3 seed | **14 suppliers** (12 used on POs, all with email) / **20 customers** | Real data. Migrate both. |
| Sales orders | 4 | **0** | Confirms H-4: no channel or manual sales were ever recorded. Nothing to migrate. |
| `inventory_transactions` | 19 | 8 (5 adjustment, 3 count; Nov–Dec 2025) | Too sparse to be a ledger. Archive only. |
| Notifications | 69 | **13,578 `low_stock`** + 22 `new_order` | Confirms M-4/S-9: alert spam driven by the negative figures. Do not migrate. |
| Users | 7 | 2 (both active) | Invite both into the new app. |
| Jobs | 25 pending, 9 failed | 4 pending, 0 failed | Queue was effectively idle; nothing to recover. |

### 9.3 Effect on the migration plan (§4.3 / spec §B9)

- **Migrate:** 123 products (+30 categories, reorder points, Ordermentum IDs to channel listings), 14 suppliers, 20 customers, 60 POs + 349 lines as **read-only history**. Legacy status is kept as a label. Received POs are marked "closed (legacy)" with no receipt movements, because they never moved stock.
- **Do not migrate stock from legacy `inventory`.** Opening balances come from the Clontarf cutover stocktake (D-7). The reconciliation report will still show legacy on-hand next to the stocktake figure for information.
- **Flag in the reconciliation report:** 3 unmapped products, 113 zero-cost products, 21 PO total mismatches, blank/`EA` units, 35 negative legacy balances.
- **Engine:** production is MariaDB 10.5.29. The new app's migrations and full test suite have been run on MariaDB 10.5.29 with binary logging on. Ledger triggers need `log_bin_trust_function_creators=1` (or creation by an admin user) when binlog is enabled; the migration fails with instructions otherwise.
