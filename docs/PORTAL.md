# Portal integration (BWMS side)

BWMS and the portal (shop.localselection.com.au) talk over two small signed APIs (spec B4.3–B4.4). BWMS owns stock. The portal keeps `stock_qty` as a display cache and reserves at checkout. The portal's own changes are a separate task: `docs/portal-bwms-integration.md`.

## Settings

| Setting | Effect |
|---|---|
| `PORTAL_API_ENABLED` | Turns on the inbound API at `/api/portal/v1`. While off, every call gets 503. |
| `PORTAL_API_KEYS` | Inbound keys as `keyId:secret[,keyId2:secret2]`. Two can be active at once, for rotation. |
| `PORTAL_BASE_URL`, `PORTAL_OUTBOUND_KEY_ID`, `PORTAL_OUTBOUND_SECRET` | Where BWMS calls the portal, and the key it signs with. On the same VPS, use a private hostname or localhost (no public DNS). |
| `PORTAL_PUSH_ENABLED` | Lets BWMS push stock to the portal. Each portal listing must also be switched on. |
| `PORTAL_EVENTS_ENABLED` | Lets BWMS send dispatch notifications to the portal. |

All of these are off by default. The Sync screen has a Portal section showing the switches, listings, push failures, and refused or invalid calls.

## Signing (both directions)

Each request carries:
- `X-BWMS-Key-Id`
- `X-BWMS-Timestamp` (unix seconds)
- `X-BWMS-Signature`: hex HMAC-SHA256 of `timestamp + "." + raw body`, using the secret for that key ID.

A request is rejected with 401 if the signature is missing or wrong, the key ID is unknown, or the timestamp is more than 300 seconds off.

## Portal → BWMS

Every mutating call needs an `Idempotency-Key`:
- A replay with the same body returns the stored response, with header `Idempotent-Replay: true`.
- The same key with a different body gets 422 `idempotency_conflict`.
- 5xx responses aren't stored, so a retry runs again.

Every mutating call is also stored in `integration_inbox` (channel `portal`).

| Call | BWMS behaviour |
|---|---|
| `POST /reservations` | All-or-nothing reservation (D-3). **201** `confirmed` returns `reserved_qty` per line. **409** `insufficient_stock` returns `requested`/`available` per short line, and nothing is reserved. **422** for an unknown product (it appears in the Sync screen mapping queue) or invalid data. |
| `PUT /orders/{ref}` | Full-state upsert by `version`. A stale version returns 200 unchanged. `cancelled` releases everything not yet dispatched. A quantity increase beyond stock returns 409 and nothing changes. An unknown order being cancelled returns 404. |
| `GET /orders/{ref}` | Returns `status`, `reservation_status`, and per line `reserved_qty` / `dispatched_qty`. |
| `GET /stock?product_ids=…` | Returns available packs per mapped product, never below zero. |

**Quantities on the wire are portal packs.** A portal product maps to one BWMS product and pack (`channel_listings`, channel `portal`), and BWMS converts to and from base units.
- The pack is chosen at mapping time. `unit_quantity` × `unit` only describes the portal's sell unit, and the same unit can be written "1 × carton" or "12 × bottle".
- **The check only runs when the portal sends `unit`:**
  - `unit` = the BWMS base unit → `unit_quantity` must equal the pack size
  - `unit` = the pack's name → `unit_quantity` must be 1
  - anything else, or a mismatch, adds a `warnings` entry on the response and the inbox row
  The call still succeeds.

Portal customers are matched by `portal_user_id`. One is created as `P-{id}` on first sight.

## BWMS → Portal

- **Stock:** `PUT {portal}/api/bwms/v1/stock` with `[{portal_product_id, available, as_of}]`, in batches of up to 100.
  - It works like the Ordermentum push: any stock change flags the product's enabled portal listings and queues one debounced run (`PushPortalStock`).
  - `available = max(0, floor(available_base / pack))`. An unchanged figure is skipped for up to 24h.
  - A 5xx or timeout keeps the items pending and retries. A 4xx is shown on the Sync screen.
- **Dispatch:** `POST {portal}/api/bwms/v1/order-events` with `{order_ref, event: dispatched | short_shipped, lines:[{line_ref, qty}], at}`.
  - Sent once per dispatched pick list per portal order, from a queued job.
  - The `Idempotency-Key` is `bwms:order-event:{ref}:{pickListId}`.

Timeouts are 5s connect and 15s total. The client refuses to run during a web request or inside a DB transaction.

## Mapping portal products

- **Bulk:** run `php artisan channels:portal-map products.csv [--dry-run]`. The CSV has columns `portal_product_id, sku[, pack_qty]`. `pack_qty` picks the BWMS pack; without it, the product's default sale pack is used. Listings start with sync off.
- **One at a time:** an unknown product on a portal call appears in the Sync screen's mapping queue (channel `portal`). Map it there.

## Cutover

1. Exchange keys: BWMS's inbound key goes to the portal, and the portal's outbound key comes to BWMS.
2. Map the portal products.
3. Set `PORTAL_API_ENABLED=true`. The portal starts reserving.
4. Set `PORTAL_PUSH_ENABLED=true` and `PORTAL_EVENTS_ENABLED=true`.
5. Click "Enable all portal listings" on the Sync screen. With the push switch on, this queues a full push of every listing it enables.
6. Disable the portal's own Ordermentum sync (D-6), so BWMS is the only stock writer.
