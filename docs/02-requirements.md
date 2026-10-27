# BWMS Requirements (Phase 2)

Date: 2026-10-26 · Inputs: `docs/01-audit.md`, legacy code, local `localselection` (portal) DB schema (read-only), Ordermentum docs at docs.ordermentum.io (fetched 2026-10-26).

Conventions: **MUST / SHOULD / MAY**. Items marked **⚠ UNCONFIRMED** must be verified before or during Phase 3. **[DECISION D-n]** points to §B10.

---

## B0. Inputs still missing

The §8 answers in the Phase 2 brief came through as **unfilled placeholders** (`[PATH]`, `[YES/NO]`, `[ANSWER]`, `[one site / N sites]`). I have not guessed. These stay open:

| # | Question | Effect on this spec |
|---|---|---|
| Q1 | Path to the production DB dump | Phase 3 migration command and reconciliation need it. No dump was found on disk. |
| Q2 | `config:cache` on the VPS? | Historical only. The rebuild uses `config()` throughout, so it doesn't matter going forward. |
| Q3 | Queue worker / scheduler in prod? | Ops requirements in §B8 assume we provision both (Supervisor + cron). |
| Q4 | Ordermentum account setup (webhooks configured? API key issued?) | Decides when §B3 can move from documented to verified. |
| Q5 | Where stock is managed day to day | Decides the cutover source of truth for opening balances (§B9). |
| Q6 | Number of sites | The spec models multi-location, and seeds **one** site per your instruction. |

---

# Part A: Current-state requirements (reverse-engineered)

What the legacy app is *trying* to do, stated as requirements. Defects are in the audit and not repeated here.

| ID | Requirement as implied by the code | Source |
|---|---|---|
| A-1 | Maintain a product catalogue (SKU, name, category, cost, price, unit, reorder point/qty, barcode, weight, active) with soft delete. | `ProductController`, `products` |
| A-2 | Map each product 1:1 to an Ordermentum product + variant ID. Import mappings from an Ordermentum CSV export. | `import:variants-fresh`, `products.ordermentum_*` |
| A-3 | Hold stock per product per warehouse as on-hand / allocated / available / on-order. | `inventory` |
| A-4 | Allow manual stock adjustments (adjustment, damage, count) and inter-warehouse transfers with a reason, logged with before/after quantities and user. | `InventoryController::adjust/transfer` |
| A-5 | Raise purchase orders to suppliers (lines with qty, unit price, discount, tax), with draft/pending/received/cancelled states. | `PurchaseOrderController` |
| A-6 | Record receipt of PO lines, including partial receipt. *(Intended; the stock effect is missing.)* | `receive`, `OrderService::receivePurchaseOrder` (dead) |
| A-7 | Generate a PO PDF, download it, and email it to the supplier or any recipient. | `download`, `sendEmail`, `sendToSupplier`, `PurchaseOrderMail` |
| A-8 | Record sales orders against customers: allocate stock on confirmation, deduct on fulfilment, release on cancel. | `SalesOrderController` |
| A-9 | Keep Ordermentum stock aligned with BWMS. The code tried both directions over time: push after BWMS changes, and a nightly pull. | `push:stock`, `sync:stock`, `InventoryPushService` |
| A-10 | React to Ordermentum order events. | `Webhooks\OrdermentumWebhookController` |
| A-11 | Alert admins about low and out-of-stock items (in-app, daily digest). | `InventoryObserver`, `inventory:check-low-stock` |
| A-12 | Reports: sales, purchases, inventory, product performance, inventory value. Dashboard KPIs. | `ReportController`, `DashboardController` |
| A-13 | Import/export products, customers, suppliers, orders, inventory (Excel/CSV). | `ImportController`, `ExportController` |
| A-14 | Role- and permission-based access (admin, warehouse-manager, user). | spatie tables, `CheckRole`, `CheckPermission` |
| A-15 | Company settings: name, tax rate, order prefixes, low-stock threshold. | `settings` |
| — | SaaS billing, trials and usage limits | **Not carried forward.** |

---

# Part B: Target specification

## B1. Scope and principles

1. **BWMS is the single source of truth for stock.** Ordermentum and the portal hold read-only *copies* of available-to-sell, written only by BWMS.
2. **The stock ledger is append-only.** Every change is a movement row. On-hand is derived. No code path updates a quantity in place, apart from the balance projection (§B2.3), which is maintained only by the ledger service.
3. **Inbound sales are recorded as orders, then as movements.** They are never recorded by overwriting a number.
4. **Integration is asynchronous.** No external calls in web requests or DB transactions (§B8).
5. Stack: Laravel 12, Inertia.js, Vue 3, MySQL 8 (same VPS), Redis for queue/cache/locks (⚠ confirm Redis is available on the VPS; the fallback is the database queue with the same semantics). New sibling folder. The old app is left untouched.
6. Timezone: **Australia/Brisbane** for business dates (order date, expiry, cut-offs). Timestamps are stored in UTC.

## B2. Stock model

### B2.1 Products, ranges, pack sizes

**Decided 2026-10-26:**
- **Flavours and other variants are separate products**, each with its own SKU and its own stock (e.g. "Dash Syrup Vanilla 750 mL" and "Dash Syrup Caramel 750 mL" are two products).
- **Range** (optional, display only): a product may belong to one range (e.g. "Dash syrups"), used only to group, filter and sort lists. A range has no stock, price or sync behaviour of its own.
- **Pack sizes** are `product_units` of one product (Each, Carton ×12…), as built in step 2.
- **An Ordermentum variant maps to exactly one product + one pack size** (`channel_listings.product_id` + `product_unit_id`). Pushed quantity = `floor(ATS_base / pack base_qty)`.

### B2.1a Units and pack sizes
- Each product has one **base unit**, the smallest unit you ever stock or sell separately (e.g. *bottle*). All ledger quantities are **integers in base units**.
- `product_units` defines pack sizes: name (e.g. *carton*), `base_qty` (e.g. 12), barcode (GTIN/ITF-14), and flags `purchasable`, `sellable`, `default_purchase`, `default_sale`.
- PO lines, sales lines and channel listings each name a unit. The system converts to base units on write and stores both the entered unit/qty and the base qty.
- Channel listings carry `units_per_listing`. Pushed quantity = `floor(ATS_base / units_per_listing)`.
- Today every Ordermentum variant is effectively one sell unit, often a carton ("…330ml x 12"), and the pack size lives only in the name. **[DECISION D-1]**: base unit = single item, or base unit = carton for items never split.

### B2.2 Movement types

| Type | Sign | Created by |
|---|---|---|
| `opening_balance` | + | Migration only |
| `receipt` | + | PO receiving (per batch, per location) |
| `sale` | − | Channel order deduction (Ordermentum, portal, manual) **[D-2]** |
| `sale_reversal` | + | Cancellation or credit after deduction |
| `adjustment` | ± | Manual, reason code required |
| `write_off` | − | Damage, expiry, spoilage, reason code required |
| `transfer_out` / `transfer_in` | −/+ | Location-to-location (same row pair, shared `transfer_id`) |
| `stocktake_variance` | ± | Posting a stocktake or cycle count |
| `return_to_supplier` | − | Phase 2 |
| `customer_return` | + | Phase 2 (to quarantine or sellable) |

Each movement has: `id`, `product_id`, `location_id`, `batch_id` (nullable until batches are on), `qty_base` (signed int, ≠ 0), `type`, `reason_code_id`, `source_type`/`source_id` (PO line, order line, stocktake line…), **`idempotency_key` (UNIQUE)**, `unit_cost` (for valuation), `occurred_at`, `created_by`, `created_at`, `note`.

Rules:
- The DB user for the app has no UPDATE/DELETE on `stock_movements`. Enforce this with a trigger as well (⚠ needs the TRIGGER privilege on the VPS MySQL). Corrections are new movements.
- Writes go through one `StockLedger` service, in a transaction, with `SELECT … FOR UPDATE` on the affected balance rows. A negative resulting on-hand is rejected unless the movement type is in the allowed-oversell set **[D-3]**.
- Idempotency key format: `{source}:{external_id}:{line}:{action}`, e.g. `om:order:<uuid>:<line>:sale`. A duplicate insert is a no-op that returns the existing row.

### B2.3 Derived figures
- `stock_balances(product_id, location_id, batch_id)` holds `on_hand`. It is a projection, updated in the same transaction as each movement, and can be rebuilt (`stock:rebuild-balances`). A nightly job asserts projection = SUM(ledger) and alerts on drift.
- `reservations(product_id, qty_base, source, external_ref, status, expires_at)`: committed-but-not-shipped demand when stock is deducted at dispatch **[D-2]**.
- **Available-to-sell (ATS)** per product = on-hand in *sellable* locations, excluding expired and quarantined batches, − active reservations − channel safety buffer **[D-4]**.

### B2.4 Batches and expiry
- `batches(product_id, supplier_id, lot_code, best_before, received_at)`. Unique on (product, lot_code, best_before).
- Receiving captures lot and best-before per line. Batch tracking can be **required per product** (juices, milks, kombucha: yes; dry goods: optional).
- **FEFO:** pick suggestions take the earliest best-before first. Expired batches are excluded from ATS automatically at 00:00 Brisbane, and an expiry job raises write-off suggestions.
- "Short-dated" threshold per product (days), used for alerts and reports.

## B3. Ordermentum integration

### B3.1 What the docs say

Doc index: https://docs.ordermentum.io/llms.txt. Status key: **Confirmed** = documented in Ordermentum's official reference or guide (link given). Not yet tested against the live account. **⚠ Unconfirmed** = undocumented, contradictory, or assumed.

| Topic | Finding | Status | Source |
|---|---|---|---|
| **Auth** | `x-api-key` header with an API token, generated in the supplier platform under *settings → integrations*. Works for the core and stock APIs. BWMS uses only this: username/password support has been removed, and the token is never logged. | **Confirmed by Ordermentum** (Ordermentum support (Michael), 2026-10-27) | [Getting started](https://docs.ordermentum.io/docs/getting-started-with-ordermentum-api-authentication-and-first-steps.md) |
| Auth (legacy) | `POST app.ordermentum.com/v1/auth` username/password → 24h Bearer. **Deprecated from 1 Jan 2027.** BWMS does not use it. The portal still does (R-1, `docs/portal-om-token.md` in the app repo). | Confirmed | [Migration guide](https://docs.ordermentum.io/docs/authentication-migration-guide.md) |
| Core API base | `https://api.ordermentum.com` (sandbox `api.ordermentum-sandbox.com`). `app.ordermentum.com` still works ("backwards compatible"). | Confirmed | Same; [Get order](https://docs.ordermentum.io/reference/get_v1-orders-id.md) |
| Stock API base | `https://stock.ordermentum.com` (sandbox `stock.ordermentum-sandbox.com`). | Confirmed | [Upsert item](https://docs.ordermentum.io/reference/put_v1-items-id.md) |
| Stock API context headers | `context-entity: supplier` / `context-id` (docs also say `x-context-*`). **With the API token, the stock API ignores them on reads:** none, plain, `x-` and even a wrong supplier ID all returned 200. The token scopes access to the supplier. BWMS sends plain `context-*` (as the docs and portal do). Writes were not tested, because the probe is read-only. | **Confirmed for reads** (live read-only `om:probe`, 2026-10-27). Writes: untested | — |
| **Set stock (absolute)** | `PUT /v1/items/{id}` body `{available, tracked, allowOversell, locationId, defaultLocationId, supplierId}`. "`available` cannot be negative unless `allowOversell` is true". | Confirmed | [Upsert item](https://docs.ordermentum.io/reference/put_v1-items-id.md) |
| `{id}` = variant ID for PUT | The reference says "Resource id". The bulk endpoint says `id` is "the variant ID". The old `push:stock` used variant ID. | ⚠ Likely, not stated for PUT | [Bulk items](https://docs.ordermentum.io/reference/patch_v1-items.md) |
| Bulk stock actions | `PATCH /v1/items` body `{actions:[{id(variantId), productId, action: ADD\|REMOVE\|UPDATE\|CLEAR, name, quantity, locationId}]}`. 1 MB body cap; ~50–100 actions per call. | Confirmed (semantics of `UPDATE` vs `ADD` are not defined; we assume UPDATE = set) | [Bulk items](https://docs.ordermentum.io/reference/patch_v1-items.md) |
| Read stock | `GET /v1/items/{id}?locationId=` returns `{available, tracked, allowOversell, stock:[{locationId, available}]}`. | Confirmed | [Get item](https://docs.ordermentum.io/reference/get_v1-items-id.md) |
| Stock locations | `GET/POST /v1/locations` returns `{id, name, default, externalId}`. | Confirmed | [List locations](https://docs.ordermentum.io/reference/get_v1-locations.md) |
| Variant stock fields | The Variant schema has only `outOfStock` (bool), `SKU` (capital), `barcode`, `unit`, `uom`. No quantity. The old app's `sync:stock` read `available/quantity` from this endpoint, which the schema does not define. | Confirmed | [Get variant](https://docs.ordermentum.io/reference/get_v1-products-productid-variants-id.md) |
| **Does Ordermentum decrement its own stock when an order is placed?** | **Yes, at order placement, for products with stock tracking enabled.** Ordermentum stock cannot go negative: it blocks an order beyond available. Products with tracking off are not decremented (`om:probe tracking` lists them). The legacy −809-style negatives (audit §9.2) are therefore **likely legacy BWMS writes or untracked products**, not Ordermentum overselling. | **Confirmed by Ordermentum** (Ordermentum support (Michael), 2026-10-27) | — |
| Webhook registration | `POST /v1/webhooks` `{name, url, entityType, entityId, events[]}`. List/get/update/delete exist. | Confirmed | [Create webhook](https://docs.ordermentum.io/reference/post_v1-webhooks.md), [Guide](https://docs.ordermentum.io/docs/registering-webhooks-for-order-events-1.md) |
| Webhook events | Reference enum: `order_created`, `order_updated`, `invoice_created`, `invoice_updated`, `product_created`, `product_updated`, `purchaser_created`, `purchaser_updated`. The guide also lists `credit_note_created/updated/completed/cancelled` and omits the product events. | Confirmed for the order events. ⚠ credit_note/product events contradictory | Same |
| Webhook payload | `{event, timestamp, data:{id, createdAt, updatedAt, total, …}}`. | Confirmed (example only, not a schema) | [Guide](https://docs.ordermentum.io/docs/registering-webhooks-for-order-events-1.md) |
| **Webhook signing** | Webhooks **are signed** with an Ordermentum header. The header name and algorithm have not been confirmed yet (asked). BWMS has a configurable HMAC verifier in report-only mode, logs incoming webhook **header names** only (no values or bodies), and keeps the path token plus fetching the order back as the guard until then. | Signed: **confirmed** (Ordermentum support (Michael), 2026-10-27). ⚠ Header/algorithm unconfirmed | — |
| Webhook event ID | None. Only `data.id` (entity ID). | Confirmed absent | Same |
| Webhook retries | "exponential backoff… fails after three unsuccessful attempts". 2xx = ack. No timeout figure. No ordering guarantee. | Confirmed | Same |
| Search orders | `GET /v2/orders?supplierId&retailerId&status&pageNo&pageSize(≤1000 default)&includeLines` returns `{id, number, status, total, deliveryDate, reference, retailerId, externalId, cancelledAt, createdAt, updatedAt}`. | Confirmed | [Search orders](https://docs.ordermentum.io/reference/get_v2-orders.md) |
| Updated-since filter | `GET /v2/orders?supplierId&updatedAt[gte]=…` filters (185 orders since 2026-10-01, out of 13,303). Results are **not sorted** and the default order is oldest first. `sortBy`/`sort` return 400. | **Confirmed** (live read-only `om:probe`, 2026-10-27) | — |
| **Order line items** | `GET /v1/orders/{id}?includeLines=true` returns `lineItems[]` with `quantity` (int), `variantId` (also `variant.id` and `product.variantId`), `productId`, `id` and price fields. **`variantId` is the stock API item ID** (200, `tracked: true`) and matches the legacy mapping. All 8 lines of the probed order mapped. | **Confirmed**: structure and quantity (support, 2026-10-26); variant field (live read-only `om:probe`, 2026-10-27) | [Get order](https://docs.ordermentum.io/reference/get_v1-orders-id.md) |
| Order statuses | String, no enum. Examples: "Placed", "Paid", "Delivered". Cancellation via `cancelledAt` and `PATCH /v1/orders/{id}/cancel`. Lock/unlock via `PATCH /v1/orders/{id}/lock` and `/unlock`. | ⚠ Enum unconfirmed | Same, [Cancel](https://docs.ordermentum.io/reference/patch_v1-orders-id-cancel.md) |
| Pagination | `pageNo`, `pageSize` → `meta{totalResults,totalPages,pageSize,pageNo}`, `links{self,first,prev,next,last}`. Stop when `next` is null. | Confirmed | [List format](https://docs.ordermentum.io/docs/api-response-format-for-list-endpoints.md) |
| Rate limits (per 60s) | Order fetch 24 · ordersSearchV2 40 · ordersCreate 12 · purchasersRead 40 · purchasersUpdate 20 · productSearch 40 · productRead 40 · productUpdate 20 · productDestroy 20. **429 + `Retry-After`**. Docs advise webhooks over polling. | Confirmed | [Rate limits](https://docs.ordermentum.io/docs/rate-limits-and-api-usage-guidelines.md) |
| Stock API rate limits | **PUT 20/min, POST 12/min.** PATCH (bulk) limit not given (asked); BWMS treats it as 12/min. Enforced per endpoint in the shared client, plus 429 + `Retry-After`. Bulk `PATCH /v1/items` is preferred when more than one item is pending (PATCH limit configurable, default 12/min). **Full push of ~120 listings:** bulk = 3 PATCH calls (under a minute); PUT-only fallback = 120 ÷ 20/min ≈ 6 minutes (replaces the earlier 40/min ≈ 3 min estimate). | PUT/POST **confirmed** (Ordermentum support (Michael), 2026-10-27). ⚠ PATCH unconfirmed | — |
| Sandbox | Hosts listed in specs. How to get sandbox access is not documented. | ⚠ Ask api@ordermentum.com | Specs above |
| API terms | Governed by the Application Developer and API Agreement. | Confirmed | [Agreement](https://www.ordermentum.com/application-developer-api-agreement) |

### B3.2 Integration design (target)

**Outbound (BWMS → Ordermentum stock)**
- **An Ordermentum order is a BWMS reservation only.** Ordermentum has already decremented its own tracked stock at placement (confirmed 2026-10-27), so nothing is deducted in Ordermentum again. Pushes are absolute: `available = max(0, floor(ATS / pack) − safety buffer)`, `tracked = true`, `allowOversell = false`.
- Trigger: any committed stock or reservation change → `StockChanged` (after commit) flags the product's enabled listings as pending and queues one debounced push run (10s).
- **Ingestion before push (per SKU).** A push run waits while any Ordermentum order event is still being ingested (a stuck event, older than 10 min, is logged and no longer blocks). SKUs on an order held for mapping are not pushed until it is mapped. This stops a stale push from overwriting an Ordermentum decrement BWMS has not recorded yet.
- Payload: one pending item → `PUT /v1/items/{variantId}`; more → **bulk `PATCH /v1/items`** (`UPDATE` actions, assumed to set; ⚠ unconfirmed) in chunks of 50. Absolute values, so retries are idempotent. Rate limits per endpoint in the shared client (B3.1).
- **Cancel/edit.** Whether Ordermentum restores its stock on cancel or edit is unconfirmed (asked). Until answered, BWMS re-fetches the order, updates the reservation, then force-pushes the absolute figure for every SKU on the order.
- Store `last_pushed_qty`, `last_pushed_at`, `last_response_code`, `last_error` on the listing. Skip the call if the computed qty equals `last_pushed_qty` and the last push was less than 24h ago.
- Nightly **full reconciliation**: for each listing, `GET /v1/items/{id}` and compare with ATS. Re-push on mismatch and record it on the sync screen. This is a check only and never writes BWMS stock.

**Inbound (Ordermentum orders → BWMS)**
- `POST /webhooks/ordermentum/{secret-token}` over HTTPS. Guards: the long random path token, a configurable signature check (report-only until Ordermentum confirms the header and algorithm, then enforced), and fetching the order back from the API. Incoming headers are logged; bodies are not logged.
- Handler: persist the raw body and headers to `integration_inbox`, dedupe key `sha256(event + data.id + data.updatedAt)` (unique), return **200 immediately**, dispatch `ProcessOrdermentumOrder(order_id)` (unique per order ID).
- **Treat the webhook as a hint, not data.** The job fetches the order from the API (`GET /v1/orders/{id}`, with lines ⚠), which is authoritative and also defeats spoofed webhooks.
- The job upserts the order and its lines keyed by (channel, external order ID) as a full-state, versioned update (`updatedAt` in ms). The order becomes **reservation changes only [D-2]**: no movements in BWMS until pick/dispatch, and nothing deducted in Ordermentum (it already decremented at placement). A cancelled order (`cancelledAt` set) releases its reservation.
- **Safety-net poll** every 10 min: `GET /v2/orders?supplierId=…` (with `updatedAt[gte]` if it works ⚠, otherwise newest-first pages until known orders are reached). Enqueue anything unseen or changed. At ~1–2 requests per run this is well inside the 40/min limit.
- Unmapped variant on an inbound line: the order goes to a **"needs mapping"** queue on the sync screen and no movement is written until it is mapped. No silent drops.

**Open items** (blocking items 2 and 5 are resolved: Ordermentum decrements at placement; API token issued)
1. ~~Variant ID field~~ **Resolved 2026-10-27:** `lineItems[].variantId`, wired into the order mapper (default `variantId,variant.id`).
2. **Asked Ordermentum (2026-10-27), awaiting answers:** webhook signature header and algorithm; bulk `PATCH /v1/items` rate limit (and `UPDATE` semantics); whether cancel/edit restores Ordermentum stock.
3. ~~Stock API context header name~~ **Resolved for reads:** ignored with the API token. BWMS sends plain `context-*`.
4. Stock tracking: `om:probe tracking` on 2026-10-27 found **all 120 mapped variants tracked**. The nightly check and the sync screen flag any that change.
5. Portal client still on username/password: `docs/portal-om-token.md` (app repo) before 1 Jan 2027 (R-1).

## B4. Portal integration (shop.localselection.com.au)

### B4.1 What the portal has today (schema read-only)
- `products`: `sku` (unique, **nullable**), `unit` (string), `unit_quantity`, `stock_qty` (**unsigned int**), `min_order_qty`, `price`, `cost_price`, `is_active`, soft deletes. 109 rows locally, against 120 in BWMS. The SKU overlap is unverified.
- `orders`: `reference` (unique), `status` enum `awaiting_payment, confirmed, processing, dispatched, delivered, cancelled, payment_failed`, `payment_method` (stripe/wallet/cod/invoice), `requested_delivery_date`, delivery zones with cut-offs, Xero invoice link. `order_items`: product_id, sku, unit, unit_quantity, qty (snapshot fields).
- **The portal already has its own Ordermentum stock integration:** `product_ordermentum_mappings` (variant/location IDs, `last_pushed_stock_qty`, `last_pulled_stock_qty`, `sync_status`), `ordermentum_stock_events` (order ID → product, `qty_decremented`, `reversed_at`), `ordermentum_stock_pulls`(+items), `ordermentum_matches`(+items), and `ordermentum_connections` (access token). All are empty locally. **If both BWMS and the portal write Ordermentum stock, they will fight.** The portal's Ordermentum stock sync **MUST be disabled** at BWMS go-live **[D-6]**.
- I did not read the portal's code. If you want, I'll read it in Phase 3 to see when it deducts `stock_qty` today (likely at order confirm).

### B4.2 Options

| Option | How | Pros | Cons |
|---|---|---|---|
| **1. API on BWMS + signed webhooks (recommended)** | BWMS exposes `/api/portal/v1` (token auth, HMAC-signed requests). Portal → BWMS: order events (`created/confirmed/cancelled/dispatched`) with idempotency key = portal order reference + version. BWMS → portal: stock-level push to a portal endpoint (`PUT /api/bwms/stock`, HMAC-signed), via the same unique/debounced job used for Ordermentum. Portal keeps `stock_qty` as a read-only cache for display and checkout checks. | Clear ownership. Both channels use the same sync machinery, logging and retries. Either app can be deployed independently. Testable. Works if the portal moves hosts. | Two small APIs to build. Stock is eventually consistent (seconds). |
| 2. Shared DB | Portal reads BWMS's `stock_balances` / an ATS view directly, and writes orders into a BWMS inbox table. | Lowest latency, no HTTP. | Couples schemas and deploys. Cross-app transactions and locks. The portal can corrupt BWMS. Breaks the "BWMS owns stock" boundary. Harder to audit. |
| 3. Event bus (Redis streams / queue) | Both apps publish and consume events on a shared Redis. | Decoupled, fast. | New infrastructure to operate. Still needs the same idempotency. Harder to debug and replay than HTTP + inbox tables. Overkill for two apps. |

**Recommendation: Option 1.** On the same VPS, call over localhost or a private hostname to avoid public DNS (relevant to the DNS incident).

### B4.3 Portal flow (Option 1, D-5 revised 2026-10-26)

**Principle: the portal must never stop taking orders because BWMS is down.**

1. The portal checks its cached `stock_qty` at add-to-cart and checkout (soft check).
2. At order placement, the portal calls `POST /api/portal/v1/reservations` **synchronously, with a 3s total timeout** (1s connect).
   - **201 `confirmed`**: the reservation is held and the order proceeds normally.
   - **409 `insufficient_stock`**: the portal **rejects** the order (or the affected lines) and shows the shopper the available quantity from the response (D-3: no oversell on the portal).
   - **Timeout, connection error, 5xx or 429**: the portal **accepts** the order, marks it `reservation_status = unconfirmed`, writes the request to its outbox, and a portal queue job retries with exponential backoff (10s, 30s, 1m, 5m, then every 15m, no maximum while the order is open) using the **same idempotency key**.
   - When a retry later returns `confirmed`, the portal clears the flag.
   - When a retry returns `insufficient_stock`, the portal flags the order `reservation_failed` and **alerts staff** (email + portal admin banner). BWMS also lists it on its sync screen. A human decides: short-ship, substitute, or contact the café. No automatic cancellation.
3. Payment completion, cancellation and edits are sent as later events against the same reservation (B4.4). Each is an idempotent upsert of the whole order.
4. BWMS stores every inbound call in `integration_inbox`, dedupes, upserts `channel_orders(channel=portal)`, and creates or adjusts **reservations** (D-2: deduct at dispatch).
5. The stock change → `PushChannelStock(portal)` and `PushChannelStock(ordermentum)`.
6. Mapping: `channel_listings(channel=portal, external_product_id = portal products.id, product_unit_id)`, bulk via `channels:portal-map` (CSV) or one at a time from the sync screen. ⚠ The portal's `unit_quantity` meaning is unconfirmed: BWMS uses the mapped pack and returns a warning when `unit_quantity` differs.
7. The portal's `stock_qty` is unsigned. BWMS pushes `max(0, floor(ATS / pack))`.

**Status (2026-10-27):** the BWMS side of B4.3–B4.4 is built and tested: signed API, idempotency store, reservations, full-state updates, reads, stock push and dispatch events (app `docs/PORTAL.md`). The portal side is written up as a standalone task (app `docs/portal-bwms-integration.md`). The portal repo has not been changed.

### B4.4 Portal ↔ BWMS integration contract (v1)

**Transport and auth (both directions)**
- HTTPS. On the same VPS, use a private hostname or localhost so no public DNS is involved.
- Headers: `X-BWMS-Key-Id`, `X-BWMS-Timestamp` (unix seconds), `X-BWMS-Signature` = hex HMAC-SHA256 of `timestamp + "." + raw_body` with the shared secret for that key ID.
- The receiver rejects a missing or bad signature, or a timestamp more than 300s off (401). Two key IDs may be active at once, for rotation.
- Every mutating request carries `Idempotency-Key`. The receiver stores `(key, request hash, response)`. A replay with the same hash returns the stored response; the same key with a different body gets 422 `idempotency_conflict`.

**Portal → BWMS**

| Call | Purpose | Request (essentials) | Responses |
|---|---|---|---|
| `POST /api/portal/v1/reservations` | Reserve stock for a new order (sync, 3s timeout) | `Idempotency-Key: portal:order:{reference}`; `{order_ref, customer: {portal_user_id, business_name}, requested_delivery_date, lines: [{line_ref, portal_product_id, qty, unit_quantity}]}` | `201 {status:"confirmed", reservation_id, lines:[{line_ref, reserved_qty}]}` · `409 {status:"insufficient_stock", lines:[{line_ref, requested, available}]}` (nothing reserved; all-or-nothing) · `422` validation / unknown product (no retry; alert) · `5xx`/timeout → portal retries per B4.3 |
| `PUT /api/portal/v1/orders/{order_ref}` | Full-state upsert after any change (paid, edited, cancelled) | `Idempotency-Key: portal:order:{reference}:v{version}`; `{version, status, lines:[…]}`, where status ∈ `confirmed, cancelled` | `200 {status, lines}`. BWMS diffs against the current reservation and applies the delta. Stale versions (≤ current) return `200` unchanged. Cancel releases every reservation not yet dispatched. |
| `GET /api/portal/v1/orders/{order_ref}` | Reconciliation / support | — | `200 {status, reservation_status, lines:[{line_ref, reserved, dispatched}]}` |
| `GET /api/portal/v1/stock?product_ids=…` | On-demand ATS for display or repair | — | `200 [{portal_product_id, available}]` |

**BWMS → Portal**

| Call | Purpose | Request | Expected response |
|---|---|---|---|
| `PUT {portal}/api/bwms/v1/stock` | Push available-to-sell (unique/debounced job, B3.2 rules) | `[{portal_product_id, available, as_of}]` (batch up to 100), `available = max(0, floor(ATS_base / pack base_qty))` | `200`. The portal stores the value only if `as_of` is newer than what it holds (out-of-order safe). |
| `POST {portal}/api/bwms/v1/order-events` | Dispatch notifications | `{order_ref, event: "dispatched"\|"short_shipped", lines:[{line_ref, qty}], at}` | `200` (idempotent by `order_ref + event + at`) |

**Timeouts and retries:** portal→BWMS reservation: 3s total. All other calls: 5s connect / 15s total, retried from a queue with backoff. Neither app ever calls the other inside a DB transaction.

## B5. Target data model (summary)

Core: `ranges` (display grouping), `products`, `product_units`, `product_barcodes`, `categories`, `suppliers`, `supplier_contacts`, `supplier_products` (supplier SKU, cost, pack, lead time, MOQ), `locations` (site → zone → bin, self-referencing, flags `sellable`, `quarantine`, `receiving`), `batches`, `stock_movements` (append-only), `stock_balances` (projection), `reservations`, `reason_codes`, `purchase_orders`, `purchase_order_lines`, `receipts`, `receipt_lines` (batch, location, qty received/damaged/rejected, discrepancy note), `stocktakes`, `stocktake_lines`, `channel_orders`, `channel_order_lines`, `customers` (with `ordermentum_retailer_id`, `portal_user_id`), `pick_lists`, `pick_list_lines`, `dispatches`.

Integration: `channels`, `channel_listings` (channel, product, unit, external IDs, location ID, `units_per_listing`, `sync_enabled`, last pushed qty/at/status/error), `integration_inbox` (raw inbound, dedupe key unique, status, attempts, error), `sync_attempts` (outbound log: channel, product, request summary, response code, duration, error, attempt no.), `integration_credentials` (encrypted).

Platform: `users` (invite-only), spatie `roles/permissions`, `audit_log` (who/what/when/before/after for every non-ledger mutation), `settings`, `notifications`, `jobs`, `failed_jobs`.

## B6. Functional requirements by phase

| Area | MVP (go-live) | Phase 2 | Later |
|---|---|---|---|
| **Products** | SKU, name, category, optional range (display grouping), base unit, pack sizes, barcodes, cost, price, active, batch-required flag, shelf-life/short-dated days, reorder point/qty; flavours = separate products; cost back-fill (zero-cost report, inline edit, CSV import with preview) | Supplier-product catalogue (supplier SKU, cost history, lead time, MOQ); product images | Kits/bundles |
| **Locations** | One site, flat bins, sellable/quarantine/receiving flags, default receiving location | Zone/aisle hierarchy, bin capacity, putaway suggestions | Multi-site transfers with in-transit state |
| **Stock ledger** | All movement types in §B2.2 except returns; derived on-hand and ATS; nightly projection check | Returns (customer, supplier) | Valuation by FIFO cost layers (MVP: weighted average cost on receipt) |
| **Batches / expiry** | Lot + best-before on receipt for batch-required products; FEFO pick order; expired batches excluded from ATS; expiry alert | Write-off suggestions, short-dated price-down flag to channels | Recall report by lot → customers |
| **Suppliers** | CRUD, contacts, email for POs, payment terms | Supplier performance (lead time, fill rate) | |
| **Purchase orders** | Draft → Sent → Partially received → Received / Cancelled / Closed-short; lines in purchase units; PDF (queued mPDF, local fonts); email to supplier (queued) with send log | Suggested POs from reorder points and sales velocity; PO approval threshold | EDI / supplier portal |
| **Receiving** | Receive against PO (full/partial), per line: qty, batch, best-before, location, damaged/rejected qty, discrepancy reason; over-receipt blocked unless permitted; posts `receipt` movements atomically; idempotent submit (client token) | Receive without PO (unplanned receipt) with approval; barcode scanning UI | Mobile app / handheld |
| **Adjustments** | Reason codes (damage, expired, found, lost, correction, sample), required note above a threshold | Approval workflow above a value threshold | |
| **Stocktakes** | Full stocktake: freeze snapshot, count sheets, enter counts, variance review, post `stocktake_variance` | Cycle counts by ABC class / location; blind counts | |
| **Reorder** | Low-stock list (ATS ≤ reorder point), daily digest email | Suggested PO generation, per-supplier grouping | Demand forecasting |
| **Orders / dispatch** | Channel orders list (Ordermentum, portal, manual), status, lines, stock effect; manual order entry | Pick lists (FEFO, by bin), pack/dispatch confirmation, delivery-run grouping using portal delivery zones | Route optimisation |
| **Sync** | Sync-status screen: per channel health, last success, failure queue with error, retry/replay buttons, unmapped-SKU queue, per-product push history, reconciliation mismatches | Alerts (email/Slack) on sustained failure | |
| **Roles / audit** | Invite-only users; roles Admin, Warehouse Manager, Warehouse Staff, Purchasing, Read-only; policy on every route; audit log for all mutations; ledger is its own audit | Field-level change history UI | SSO |
| **Reports** | Stock on hand + value (by product/location/batch); movement history (filterable, export CSV); expiring stock; sync log | Slow movers / days of cover; supplier spend; channel sales | Scheduled report emails |
| **Dashboard** | Low stock count, expiring in N days, open POs / overdue POs, today's orders by channel, sync health | Trend charts | |
| **Import / migration** | One-off migration command (§B9); CSV product import | CSV price/cost updates | |

## B7. Sync status screen (MVP detail)
- Per channel: last successful push and pull/ingest, failures in the last 24h, queue depth.
- Failure list: product/order, error, attempts, next retry, **Retry** and **Retry all**. Jobs older than 24h failed are kept, never auto-deleted.
- Inbox list: raw inbound events with status (received / processed / duplicate / failed / needs-mapping) and a **Replay** button.
- Reconciliation: products where the channel figure ≠ BWMS ATS at the last check.
- Mapping screen: unmapped BWMS products and unmapped channel variants. Map by SKU suggestion, then confirm.

## B8. Non-functional requirements

**From the audit (as specified in your brief):**

| ID | Requirement |
|---|---|
| NFR-1 | **One shared HTTP client per integration** (singleton Guzzle client per worker process, connection reuse/keep-alive), explicit **connect timeout (5s) and read timeout (15s)**, TLS verification **on**, token/API key from config, and rate-limit handling: honour 429 `Retry-After`, a client-side token bucket per endpoint group at the documented limits (§B3.1), exponential backoff with jitter on 5xx/timeouts. |
| NFR-2 | **No external API calls inside web requests or DB transactions.** Enforced by architecture (jobs only) and by a test that fails if the HTTP client is resolved in an HTTP-kernel context. Events are dispatched `afterCommit`. |
| NFR-3 | **Sync jobs are `ShouldBeUnique` and debounced per (channel, product)**. No full-catalogue sync per webhook. Full reconciliation is scheduled nightly and rate-limited. |
| NFR-4 | **Queue `retry_after` > job `timeout`** for every queue (e.g. timeout 60s, retry_after 120s). `tries`/`backoff` explicit per job. Workers run under Supervisor with `--max-jobs=500 --max-time=3600` so they recycle. |
| NFR-5 | **Webhooks:** authenticate (path secret, plus header/basic auth if Ordermentum supports it; HMAC verification for portal events), **store the raw payload**, **return 200 fast** (< 200 ms, no processing inline), **process async**, **dedupe** by event ID or a derived key where no event ID exists (Ordermentum). |
| NFR-6 | **Mail and PDF generation are queued.** mPDF uses **locally bundled fonts** (in the repo, mPDF `fontDir`); remote fetches are disabled (no `@import url(http…)`, images loaded from local paths). One PDF engine (mPDF). |
| NFR-7 | **No public registration.** Invite-only (signed, expiring invite link). **Policy/permission check on every route** (a route-list test fails if any route lacks `can:`/policy middleware). **No self-escalation:** users cannot change their own roles, and only Admin can grant Admin. |
| NFR-8 | **Data migration reads the live schema from the prod dump** (`information_schema` of a restored copy), never the legacy migration files. |

**Additional:**

| ID | Requirement |
|---|---|
| NFR-9 | Ledger integrity: unique idempotency key, no UPDATE/DELETE on movements, nightly projection check, all stock writes through `StockLedger`. |
| NFR-10 | Concurrency: row locks on balance rows; deterministic lock order (by product_id, location_id) to avoid deadlocks; retry on deadlock (max 3). |
| NFR-11 | Feature tests (Pest or PHPUnit) for: every movement type, negative-stock rules, idempotent re-delivery, out-of-order webhook delivery, partial receipt, cancel-after-deduct, unit conversion, ATS calculation, unique/debounced job dispatch, 429 handling (HTTP fakes). CI runs them. |
| NFR-12 | Observability: structured logs with correlation ID (inbox ID → job → outbound attempt); `sync_attempts` retained 90 days; Horizon (if Redis) or a queue dashboard; health endpoint reporting queue lag and last sync times. |
| NFR-13 | Security: secrets in `.env` only, integration credentials encrypted at rest, HTTPS only, CSRF on the Inertia app, session auth (no tokens in localStorage), rate-limited login, 2FA for Admin **[D-8]**. |
| NFR-14 | Ops on the VPS: Supervisor workers, cron `schedule:run`, a local caching DNS resolver (systemd-resolved/unbound), `ulimit -n` and PHP-FPM `pm.max_children` sized and documented, daily DB backup with a restore test. **Database (confirmed 2026-10-26): MariaDB 10.5.29.** Ledger triggers need the TRIGGER grant and, with binary logging on, `log_bin_trust_function_creators=1` (verified: error 1419 otherwise). `php artisan bwms:check-triggers` must pass after every deploy (deploy checklist in the app repo, `docs/DEPLOY.md`). ⚠ Remaining items need VPS access to confirm. |
| NFR-15 | Performance: stock screens < 1s for 5k SKUs; outbound push latency p95 < 60s from movement commit (including the debounce). |
| NFR-16 | Accessibility/usability: receiving and stocktake screens usable on a tablet; barcode-scanner (keyboard wedge) input on SKU/barcode fields. |

## B9. Data migration requirements
- `bwms:migrate-legacy --source=<connection> --dry-run|--commit`, reading from a **restored copy of the prod dump** on a separate DB connection (read-only user). It never connects to production.
- Schema discovery: assert the expected columns exist in the dump's `information_schema`; report missing or extra columns before importing anything.
- Imports: categories → products (+ Ordermentum IDs into `channel_listings`) → suppliers → customers → POs + lines (historical, read-only, status mapped) → **opening balances**: one `opening_balance` movement per product × location at the cutover timestamp, quantity from legacy `inventory.quantity_on_hand` **or** from a supplied stocktake CSV **[D-7]**.
- Idempotent: re-running `--commit` skips rows already imported (legacy ID stored on each new row).
- **Reconciliation report** (Markdown/CSV): row counts source vs target per entity; products without Ordermentum mapping; SKU conflicts; per-product legacy on-hand vs opening balance vs current Ordermentum `available` (`GET /v1/items`) vs portal `stock_qty`; zero-cost products; negative or blank quantities.
- Users: migrate name/email only, no passwords; send invites.
- **Decisions (2026-10-27):**
  - Legacy draft/pending POs are imported as closed history; none are kept open.
  - No bins are created from legacy bin labels; they appear in the report only. A new site starts with STOCK + QUARANTINE.
  - Packs are set up before the stocktake on a **Pack setup** screen/CSV (`sku, base_unit, pack_name, qty_per_pack[, legacy_unit]`). The stocktake import warns about products still on the default single "Unit" pack.
  - An SKU that is still invalid after uppercasing (one contains a space) is imported unchanged and flagged for a manual rename.

## B10. Business decisions

Status: **Decided** = you chose it (2026-10-26). **Accepted (default)** = my recommendation, adopted on your instruction and open to change.

| # | Decision | Outcome | Status |
|---|---|---|---|
| D-1 | Base unit per product | **Single item** is the base unit for anything that may be split. **Carton** is the base unit for products only ever stocked and sold by the carton. Set per product in the product form, defaulting to the Ordermentum sell unit. | Accepted (default) |
| D-2 | When a sale reduces stock | **Reserve at order placement, deduct at pick/dispatch.** `available = on_hand − reserved`. Dispatch posts `sale` movements against the FEFO-picked batches and releases the reservation. | **Decided** |
| D-3 | Oversell | **Portal: blocked.** BWMS rejects a portal reservation that would take available below 0, and the portal shows the order as failed/out of stock. **Ordermentum: always accepted**, kept as a safety net for sync lag (Ordermentum itself blocks orders beyond its tracked stock, confirmed 2026-10-27). The reservation is created even if available goes negative; the order is flagged `oversold` and an alert is raised. Ordermentum items are pushed with `allowOversell=false`, and available is pushed as `max(0, ATS)`. | **Decided** |
| D-4 | Channel allocation | Shared pool, plus an optional per-product, per-channel safety buffer (default 0). | Accepted (default) |
| D-5 | Portal checkout guarantee | **Synchronous reservation call at order placement with a ~3s timeout.** BWMS says insufficient → portal rejects. BWMS unreachable/timeout → portal accepts the order, flags it "unconfirmed reservation", queues the reservation and retries until BWMS answers; if the answer is insufficient stock, staff are alerted. The portal never stops taking orders because BWMS is down. Contract: B4.3–B4.4. | **Decided** (revised 2026-10-26) |
| D-6 | Portal's own Ordermentum sync | **Disabled at BWMS go-live** (set portal `ORDERMENTUM_SYNC_MODE` to off; remove its webhook registration). BWMS becomes the only Ordermentum stock writer. | Accepted (default) |
| D-7 | Opening balance source | **Physical stocktake at cutover**, imported as `opening_balance` ledger entries (with batch and best-before where counted). | **Decided** |
| D-8 | Roles; 2FA | Roles: Admin, Warehouse Manager, Warehouse Staff, Purchasing, Read-only. **TOTP 2FA (Laravel Fortify) with recovery codes is in the MVP**: required for Admin (an admin without 2FA is sent to set it up before anything else, and cannot disable it); optional for everyone else. | **Decided** (2FA moved to MVP 2026-10-26); roles Accepted (default) |
| D-9 | Sync conflict rule | **BWMS always wins.** The nightly reconciliation re-pushes divergent figures and logs them. Stock edits made in the Ordermentum back office are never pulled back. | Accepted (default) |
| D-10 | Ordermentum credit notes | Financial-only by default. A physical return is recorded in BWMS as `customer_return` to the quarantine location (Phase 2). | Accepted (default) |
| D-11 | Valuation | **Weighted average cost**, updated on each receipt. **Cost back-fill (MVP):** a "Product costs" screen listing products with cost = 0 (CSV export), inline cost editing, and bulk CSV import (`sku, cost, pack`) with a preview before applying. Every change is audited. (113/123 products in production have cost 0.) | Accepted (default); back-fill path **Decided** 2026-10-26 |
| D-12 | PO numbering & branding | `PO-YYYY-NNNNN` from a DB sequence (no `count()+1`). Company details come from settings, seeded as "Local Selection" with placeholders you fill in. | Accepted (default) |
| D-13 | Expired stock | At best-before, the batch is excluded from available (it stays on-hand). An expiry job flags it for write-off. Selling past best-before isn't supported in MVP. | Accepted (default) |
| D-14 | Where received stock lands | **Per-site setting.** Default: the sellable STOCK bin, so received stock is available immediately. RECEIVING plus a put-away step can be chosen per site. A RECEIVING bin is not seeded: a new site starts with STOCK + QUARANTINE only (2026-10-27), and a site that wants put-away adds the bin. Damaged goods still go to quarantine. | **Decided** 2026-10-27 |
| D-15 | Dispatch below zero | **Never block dispatch.** When dispatch takes a bin negative, BWMS logs it, opens a count task for that bin (one open task per bin), and shows negative bins and open count tasks on the dashboard. Posting a stocktake that covers the bin closes the task. A manager can dismiss it with a note. | **Decided** 2026-10-27 |

### B10.1 Risks

| # | Risk | Detail | Deadline / action |
|---|---|---|---|
| R-1 | **Portal's Ordermentum client uses deprecated username/password auth** | `localSelection/app/Services/Ordermentum/OrdermentumClient.php:46-76` does `POST {base_url}/auth` with `grant_type=password` and username/password, then sends a Bearer token. Ordermentum deprecates this from **1 January 2027** ([migration guide](https://docs.ordermentum.io/docs/authentication-migration-guide.md)). The class's own comment says the account is "on Ordermentum's older auth mechanism". | **Before 1 Jan 2027:** obtain an `x-api-key` (settings → integrations in the Ordermentum supplier platform). BWMS uses it from day one. If the portal's Ordermentum code is still running at that date it will stop working. Mitigated by D-6, which retires it at BWMS go-live; go-live must therefore happen before 1 Jan 2027, or the portal client must be switched to `x-api-key` in the meantime. Task written up as `docs/portal-om-token.md` in the app repo (2026-10-27). |
| R-2 | The portal's Ordermentum order parsing is also unverified | `OrdermentumStockEventService.php:44-46` reads `lineItems[].variantId` / `quantity`. Line items and `quantity` are now confirmed (support, 2026-10-26). `variantId` is not: the published create example uses `productId`. Its own client comments say response fields are "NOT yet confirmed". | *Resolved 2026-10-27:* `lineItems[].variantId` and `quantity` are confirmed on a live order, so the portal's parsing matches. No evidence it has run live (portal sync mode defaults to `log_only`; Ordermentum tables are empty locally). |
| R-3 | Legacy BWMS also uses username/password | Old app being taken offline, so no action. | — |

## B11. Unconfirmed items to close before Phase 3 build

1. ~~Ordermentum order line variant ID field~~ **Resolved:** `lineItems[].variantId` (live probe, 2026-10-27).
2. ~~Whether Ordermentum self-decrements~~ **Resolved 2026-10-27:** yes, at placement, for tracked products; it cannot go negative.
3. Webhook signature header/algorithm, PATCH limit, cancel/edit stock restore: asked Ordermentum 2026-10-27.
4. ~~Stock API context header names~~ Ignored on reads with the API token. Rate limits: PUT 20/min, POST 12/min confirmed.
5. ~~API key issuance~~ **Resolved:** token issued. The portal still needs switching (R-1).
6. Portal `unit_quantity` semantics and SKU overlap with BWMS (needs the prod dump + portal data).
7. Redis on the VPS; MySQL TRIGGER privilege.
8. The §B0 placeholders.

Phase 3 can start with everything that doesn't depend on 1–2 (ledger, products, POs, receiving, migration, portal API). The Ordermentum inbound adapter will be built behind an interface and finished once 1–2 are verified.

---

**Phase 2 complete. Stopping here for your review.**
