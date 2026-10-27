# Task: connect the portal to BWMS (portal side)

**Repo:** `localSelection` (shop.localselection.com.au). This is a standalone task for the portal maintainer. BWMS does not change the portal repo.
**Contract:** spec B4.3–B4.4. BWMS's side is built and tested (`docs/PORTAL.md` in the BWMS repo).
**Rule that must not break:** the portal **never stops taking orders because BWMS is down** (D-5).

## 1. Config and keys

- **Calling BWMS:** add `BWMS_BASE_URL` (private hostname or localhost on the VPS, no public DNS), `BWMS_KEY_ID` and `BWMS_SECRET`. This key is listed in BWMS's `PORTAL_API_KEYS`.
- **Verifying calls from BWMS:** add `BWMS_INBOUND_KEYS` (`keyId:secret[,…]`). It holds BWMS's `PORTAL_OUTBOUND_KEY_ID:PORTAL_OUTBOUND_SECRET`.
- **Never log secrets or signatures.**

## 2. Signing (both directions)

Headers on each request:
- `X-BWMS-Key-Id`
- `X-BWMS-Timestamp` (unix seconds)
- `X-BWMS-Signature`: `hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret)`, hex.

When verifying, reject with 401 if the key is unknown, the timestamp is more than 300s off, or the signature doesn't match (compare with `hash_equals`).

## 3. Reserve at checkout (`app/Services/CheckoutService.php`)

`placeOrder()` (~line 410) decrements `products.stock_qty` inside `DB::transaction` (~line 505). **The BWMS call must happen before that transaction opens, never inside it.**

1. Build the request from the validated cart snapshot:
   ```
   POST {BWMS}/api/portal/v1/reservations
   Idempotency-Key: portal:order:{reference}
   {order_ref, customer: {portal_user_id, business_name}, requested_delivery_date,
    lines: [{line_ref, portal_product_id, qty, unit_quantity}]}
   ```
   - `qty` is in the portal's own units: the pack the product is sold in.
   - `line_ref` must be stable for the line, for example the cart item ID.
2. Timeouts: **1s connect, 3s total**.
3. Act on the response:
   - **201 `confirmed`:** place the order as today. Set `orders.reservation_status = 'confirmed'`.
   - **409 `insufficient_stock`:** don't place the order. Show the shopper `available` for each short line. BWMS reserves nothing (all-or-nothing).
   - **422:** don't place it. This is a mapping or data problem. Log it and alert staff. A shopper-facing message like "this item can't be ordered right now" is enough.
   - **Timeout, connection error, 5xx or 429:** place the order anyway, set `reservation_status = 'unconfirmed'`, and write the request to an outbox (step 4).
4. Keep the local `stock_qty` decrement. It's only a display cache; BWMS's stock push overwrites it with the real figure.

## 4. Outbox and retries

- New table, for example `bwms_outbox`: `id, order_id, method, path, idempotency_key, body, attempts, next_attempt_at, last_error`.
- A queued job retries with the **same** `Idempotency-Key` and body. Schedule: 10s, 30s, 1m, 5m, then every 15m, with no limit while the order is open.
- **`confirmed`:** set `reservation_status = 'confirmed'` and delete the outbox row.
- **409:** set `reservation_status = 'reservation_failed'`, email staff, and show an admin banner. BWMS lists it on its Sync screen too. A person decides what to do. No automatic cancellation.
- Never send these from inside a DB transaction. Dispatch the job after commit.

## 5. Later changes: payment, edits, cancellation

Send the **whole order** whenever its state changes:
```
PUT {BWMS}/api/portal/v1/orders/{reference}
Idempotency-Key: portal:order:{reference}:v{version}
{version, status: "confirmed"|"cancelled", lines: [...]}
```
- Add `orders.bwms_version` and increment it on every change you send.
- Cancellation points to cover: `OrderAdminService` (~line 130), `Api/OrderController` (~line 88), and `ReleaseAbandonedStripeOrders` (~line 134).
- Edits are sent the same way with the new lines. A 409 means the increase couldn't be reserved; BWMS changes nothing.
- Use the same outbox and retries as step 4.

## 6. Endpoints BWMS calls

Both endpoints verify the signature (step 2). Make them stateless: no session, no CSRF.

- **`PUT /api/bwms/v1/stock`**, body `[{portal_product_id, available, as_of}]` (up to 100 per call):
  - Add `products.stock_as_of`.
  - Update `stock_qty = available` only when `as_of` is newer than the stored value, so late deliveries are ignored.
  - Return 200.
- **`POST /api/bwms/v1/order-events`**, body `{order_ref, event: "dispatched"|"short_shipped", lines: [{line_ref, qty}], at}`:
  - Dedupe on `order_ref + event + at`, or on the `Idempotency-Key` header.
  - Record it against the order, for example set status `dispatched`, or flag a short shipment for staff and the buyer.
  - Return 200.

## 7. Go-live

- **Product mapping:** export `portal_product_id, sku, pack_qty` for BWMS's `channels:portal-map`. `pack_qty` is the number of BWMS base units in the portal's sell unit.
- **Disable the portal's own Ordermentum sync (D-6):**
  - set `ORDERMENTUM_SYNC_MODE=off`
  - stop `PushProductStockToOrdermentum`
  - remove its Ordermentum webhook
  BWMS is then the only stock writer.
- **Ordermentum token:** if any Ordermentum code is still running in the portal past 1 Jan 2027, do the token switch in `docs/portal-om-token.md`.

## Open questions

- **`products.unit_quantity`:** what does it mean? BWMS assumes it's base units per sell pack, and reports a warning when it differs from the mapped pack.
- **Short shipments:** what should the portal do on `short_shipped` (refund, credit, notify)? That's a business decision.
