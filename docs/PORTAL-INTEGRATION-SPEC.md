# Portal ↔ BWMS integration spec

The contract the portal (`localSelection`, shop.localselection.com.au) implements to send orders to BWMS, the warehouse system. This document is self-contained: you don't need the BWMS repo to build from it. BWMS's side is built and tested. Every response body below is real output from it.

**Rule that must never break:** the portal keeps taking orders when BWMS is down. BWMS being slow or unreachable is never a reason to refuse a shopper.

**Who owns what:**
- BWMS owns stock. The portal's `products.stock_qty` becomes a display cache that BWMS overwrites.
- The portal owns prices, payment, invoices and the shopper.
- Each side calls the other. Both directions are signed the same way.

**Contents:** [1 Config](#1-config) · [2 Signing](#2-signing-both-directions) · [3 Idempotency](#3-idempotency-keys) · [4 Reserve at checkout](#4-post-reservations-reserve-at-checkout) · [5 Refusals at checkout](#5-handling-the-response-at-checkout) · [6 Update](#6-put-ordersref-full-state-update) · [7 Cancel](#7-cancel) · [8 Outbox and retries](#8-outbox-and-retries) · [9 Reading from BWMS](#9-reading-from-bwms) · [10 Stock from BWMS](#10-put-apibwmsv1stock-stock-from-bwms) · [11 Order events](#11-post-apibwmsv1order-events-order-events) · [12 Portal changes](#12-changes-in-the-portal-code) · [13 Go-live](#13-go-live) · [14 Acceptance](#14-acceptance-checks)

## 1. Config

Add to the portal's `.env`. Both apps are on the same VPS, so use a private hostname or localhost, not public DNS.

| Setting | Holds |
|---|---|
| `BWMS_BASE_URL` | BWMS's base URL. All calls below go to `{BWMS_BASE_URL}/api/portal/v1/…`. |
| `BWMS_KEY_ID`, `BWMS_SECRET` | The key the portal signs with when calling BWMS. |
| `BWMS_INBOUND_KEYS` | `keyId:secret[,keyId2:secret2]`. The keys BWMS signs with when calling the portal. Two can be active at once, for rotation. |
| `BWMS_ENABLED` | Off until cutover. While off, the portal behaves exactly as it does today. |

Never log a secret or a signature.

## 2. Signing (both directions)

There's no bearer token or API key header. Every request carries three headers:

| Header | Value |
|---|---|
| `X-BWMS-Key-Id` | The key ID |
| `X-BWMS-Timestamp` | Unix seconds, as a string of digits |
| `X-BWMS-Signature` | Lowercase hex HMAC-SHA256 of `{timestamp}.{raw body}`, using the secret for that key ID |

```php
$body = json_encode($payload, JSON_UNESCAPED_SLASHES);  // sign these exact bytes, and send these exact bytes
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
```

- **Sign the exact bytes you send.** Encode once, then pass that string as the request body. Don't hand an array to the HTTP client and let it encode again.
- **A GET signs an empty body:** `{timestamp}.`
- **Re-sign on every attempt.** Signatures go stale after 300 seconds, so an outbox stores the body, never the signature.

**Test vector.** Secret `test-secret`, timestamp `1762400000`:

| Body | Signature |
|---|---|
| `{"order_ref":"ORD-TEST"}` | `b990a69c8bee0f1bd4fc2c1ffdd975103da0e7cb7c2660e44acefa6d5f8a2ba7` |
| (empty) | `706c92724e4f461f647d1b8c045967f6c4eaaeb983a78be7171f33ef220fac03` |

**Failures from BWMS:**

| Status | Body | Meaning |
|---|---|---|
| 401 | `{"error":"unknown_key"}` | Key ID missing or not known |
| 401 | `{"error":"stale_timestamp"}` | More than 300 seconds off. Check the clock. |
| 401 | `{"error":"bad_signature"}` | Wrong secret, or the signed bytes aren't the sent bytes |
| 503 | `{"error":"portal_api_disabled"}` | BWMS has the API switched off |
| 429 | | More than 300 requests a minute. Honour `Retry-After`. |

**When the portal verifies calls from BWMS** ([10](#10-put-apibwmsv1stock-stock-from-bwms), [11](#11-post-apibwmsv1order-events-order-events)), apply the same rules. Answer 401 for an unknown key, a timestamp more than 300 seconds off, or a signature that doesn't match. Compare with `hash_equals`, over the raw request body (`$request->getContent()`).

## 3. Idempotency keys

Every `POST` and `PUT` to BWMS needs an `Idempotency-Key` header, 1 to 191 characters. Without one the answer is `400 {"error":"idempotency_key_required"}`.

| Call | Key |
|---|---|
| Reservation | `portal:order:{reference}:reserve` |
| Update or cancel | `portal:order:{reference}:v{version}` |

BWMS stores the first answer for 30 days, per key:

- **Same key, same body:** the stored response comes back, with header `Idempotent-Replay: true`. Nothing runs again.
- **Same key, different body:** `422 {"error":"idempotency_conflict"}`.
- **5xx answers aren't stored,** so a retry runs for real.

> **A stored refusal stays refused.** A 409 or 422 is stored like a success. Retrying with the same key returns the same refusal even after the problem is fixed: more stock arrived, or the product was mapped. To try again after a fix, use a **new key**, for example `portal:order:{reference}:reserve:r2`. Retries for a timeout or 5xx keep the **same** key.

## 4. `POST /reservations`: reserve at checkout

Call this once per order, when it's placed, **before** the portal's stock transaction opens and never inside it.

```
POST {BWMS_BASE_URL}/api/portal/v1/reservations
Content-Type: application/json
Accept: application/json
Idempotency-Key: portal:order:ORD-7K2M9XQ4LP1A:reserve
X-BWMS-Key-Id: …
X-BWMS-Timestamp: …
X-BWMS-Signature: …
```

```json
{
  "order_ref": "ORD-7K2M9XQ4LP1A",
  "customer": { "portal_user_id": 77, "business_name": "Cafe Test" },
  "requested_delivery_date": "2026-11-10",
  "delivery": { "street": "1 Test St", "suburb": "Clontarf", "postcode": "4019", "instructions": "Rear door" },
  "lines": [
    { "line_ref": "501", "portal_product_id": 501, "qty": 2, "unit_quantity": 1, "unit": "carton" }
  ]
}
```

| Field | Rules | Source in the portal |
|---|---|---|
| `order_ref` | Required, string, max 128. Unique per placement attempt. | `orders.reference` |
| `customer.portal_user_id` | Integer. Send it: without it the order has no customer. | `orders.retailer_id` |
| `customer.business_name` | String, max 255. Only used the first time BWMS sees this user. | The buyer's business name |
| `requested_delivery_date` | `YYYY-MM-DD`, Brisbane date. **Always send it.** | The value `placeOrder()` stores in `orders.requested_delivery_date` |
| `payment_status` | `awaiting_payment` on **every order paid by Stripe**. See [Unpaid orders](#unpaid-orders-held-until-paid). Leave it out for invoice orders. | |
| `pay_by` | Timestamp, sent with `awaiting_payment` | The session's `expires_at`, or `orders.payment_due_at` for a payment link |
| `delivery.street` | String, max 255 | `delivery_address['street']` |
| `delivery.suburb` | String, max 100 | `delivery_address['city']`, if not empty |
| `delivery.postcode` | **Exactly 4 digits, or leave it out** | See below |
| `delivery.instructions` | String, max 1000 | `delivery_address['notes']` |
| `lines` | Required, 1 to 500 lines | The order's items |
| `lines[].line_ref` | Required, string, max 64, unique in the order, **the same on every later call** | Use the product ID as a string. Cart lines are unique per product, and the item ID doesn't exist yet. |
| `lines[].portal_product_id` | Required, integer | `product_id` |
| `lines[].qty` | Required, whole number, 0 to 1,000,000. In the portal's sell units. | `qty` |
| `lines[].unit_quantity`, `lines[].unit` | Optional. Send both. | From the item snapshot |

**`version` and `status` are refused on this call** (`422`, "field is prohibited"). They belong to `PUT`.

**Delivery date.** BWMS uses this date as sent. If the area doesn't run that day, BWMS keeps the date and flags it for staff. It never moves it, and it never refuses the order over a date. Leaving the date out, or sending one that can't be read, puts the order on the next run after 1 pm cutoff with a "No delivery date from Portal" flag. A timestamp is accepted and takes its Brisbane date.

**Postcode.** BWMS works out the delivery run from it. A postcode that isn't 4 digits is a `422` and **refuses the order**, so only send one you've checked against `/^\d{4}$/`.
- Retail buyers: `users.cached_postcode`.
- Wholesale buyers have no postcode field. Take a 4-digit token from the end of `users.business_address` if there is one.
- If you have neither, leave `postcode` out. BWMS parks the order for staff to assign a run.
- Leave out empty strings throughout `delivery`.

**Quantities are portal sell units,** not BWMS base units. Each portal product maps to one BWMS pack equal to one sell unit. `unit_quantity` and `unit` are only a consistency check: a mismatch adds a `warnings` entry and the order still succeeds. Log warnings for staff.

**Success: 201.**

```json
{"status":"confirmed","bwms_status":"open","reservation_status":"confirmed","reservation_id":1,"lines":[{"line_ref":"501","reserved_qty":2}]}
```

With a warning:

```json
{"status":"confirmed","bwms_status":"open","reservation_status":"confirmed","reservation_id":3,"lines":[{"line_ref":"501","reserved_qty":1}],"warnings":["line 501: portal \"12 x carton\" differs from the mapped pack (1 Carton)"]}
```

Reservation is all-or-nothing: every line is reserved, or none.

## 5. Handling the response at checkout

**Timeouts: 1 second to connect, 3 seconds total.** A shopper is waiting.

| Response | What the portal does | `reservation_status` |
|---|---|---|
| **201** | Place the order as today. | `confirmed` |
| **409** `insufficient_stock` | **Don't place it.** Show the shopper `available` per line. BWMS reserved nothing. | none |
| **422** | **Don't place it.** Show a plain message, log it, alert staff. | none |
| Timeout, connection error, **5xx**, **503**, **429**, **401**, **400** | **Place the order anyway.** Queue the same request in the outbox ([8](#8-outbox-and-retries)). Alert staff on 400 or 401: those are bugs or bad keys, not outages. | `unconfirmed` |

```json
{"status":"insufficient_stock","lines":[{"line_ref":"501","requested":9,"available":3}]}
```

`requested` and `available` are in sell units. `available` can be 0.

**The 422s** are all refusals, in three shapes. Show the shopper something like "This item can't be ordered right now", never the raw error.

```json
{"status":"unknown_product","lines":[{"line_ref":"501","portal_product_id":999}]}
{"status":"invalid","errors":{"delivery.postcode":["The delivery.postcode field format is invalid."]}}
{"error":"idempotency_conflict"}
```

| 422 | Cause | Fix |
|---|---|---|
| `unknown_product` | The product isn't mapped in BWMS. It appears in BWMS's mapping queue. | Staff map it. **The shopper can then try again.** |
| `invalid` | A field broke the rules above. | A portal bug: fix the payload. |
| `idempotency_conflict` | A key was reused with a different body. | A portal bug: references must be unique per attempt. |

**Each placement attempt gets a new `reference`.** A refused order is never saved, so the shopper's next try mints a new reference, and so a new key. That's what stops a stored refusal ([3](#3-idempotency-keys)) from sticking to them.

**If the portal can't finish after a 201.** BWMS is now holding stock for an order that won't exist: for example, the stock transaction threw. Queue a cancel for that `order_ref` in the outbox ([7](#7-cancel)). The outbox row can't depend on a saved order.

## 6. `PUT /orders/{ref}`: full-state update

Send this whenever an order changes: payment confirmed, a line edited, the delivery date or address changed.

```
PUT {BWMS_BASE_URL}/api/portal/v1/orders/ORD-7K2M9XQ4LP1A
Idempotency-Key: portal:order:ORD-7K2M9XQ4LP1A:v1
```

```json
{
  "version": 1,
  "status": "confirmed",
  "customer": { "portal_user_id": 77, "business_name": "Cafe Test" },
  "requested_delivery_date": "2026-11-10",
  "delivery": { "street": "1 Test St", "suburb": "Clontarf", "postcode": "4019", "instructions": "Rear door" },
  "lines": [
    { "line_ref": "501", "portal_product_id": 501, "qty": 2, "unit_quantity": 1, "unit": "carton" }
  ]
}
```

`order_ref` comes from the URL. The fields follow the rules in [4](#4-post-reservations-reserve-at-checkout), plus:

| Field | Rules |
|---|---|
| `version` | Required, integer from 1. Increases on every change sent. |
| `status` | Required: `confirmed` or `cancelled` |

> **It's full state: send every field, every time.** BWMS replaces what it holds. A `PUT` with only `version`, `status` and `lines` **clears the order's customer and delivery date**, and flags it "No delivery date from Portal". The exception is `delivery`: left out, the address stays as it was.

**How BWMS applies it:**

| Situation | Result |
|---|---|
| `version` is higher than the last applied | Applied. 200, same body as a reservation. |
| `version` is the same or lower | **Ignored.** 200 with the current state, so check `lines` if it matters. |
| A line left out | That line is cancelled. |
| A quantity raised beyond stock | **409** `insufficient_stock`. Nothing changes: not the lines, the date or the version. |
| Order unknown to BWMS, `status: confirmed` | Creates it, the same as a reservation. |
| New delivery date, not yet on a pick list | The order moves to the new run. |
| New delivery date, already picking or packed | It **stays on the old date** and is flagged for staff with both dates. The answer is still 200. |
| New delivery date on an order staff moved to an earlier run | It **stays on the earlier run** and is flagged for staff with all three dates. The answer is still 200. |
| Change while being picked | Applied, and flagged for staff. |
| Order already dispatched or cancelled | **422** `{"status":"invalid","message":"…"}`. A cancelled order can't come back: place a new one. |

**Version.** Add `orders.bwms_version`, default 0 (a reservation counts as 0). Increment it and save it with the change, then queue the call. After a 409 on an edit, BWMS keeps its old state and a person decides. No automatic cancellation.

**Payment.** BWMS only tracks payment for orders sent as `awaiting_payment`: see [Unpaid orders](#unpaid-orders-held-until-paid). Leaving `payment_status` out of a `PUT` changes nothing about the hold.

## 7. Cancel

A cancel is a `PUT` with `status: "cancelled"`.

```
PUT {BWMS_BASE_URL}/api/portal/v1/orders/ORD-7K2M9XQ4LP1A
Idempotency-Key: portal:order:ORD-7K2M9XQ4LP1A:v2
```

```json
{
  "version": 2,
  "status": "cancelled",
  "customer": { "portal_user_id": 77, "business_name": "Cafe Test" },
  "requested_delivery_date": "2026-11-10",
  "lines": []
}
```

**Send `lines: []` on a cancel.** The field must be present, but BWMS doesn't need the lines, and a line whose product has since been unmapped would otherwise refuse the cancel with a 422.

BWMS releases everything not yet dispatched and removes it from any open pick list.

| Situation | Response | Then |
|---|---|---|
| Nothing dispatched yet | 200, `"status":"cancelled"` | Done. `reserved_qty` is 0. |
| **Part already dispatched** | 200, `"status":"confirmed","bwms_status":"dispatched"` | The remainder is cancelled. Goods that left stay left. **Read `bwms_status`, not `status`.** Handle what shipped as in [11](#11-post-apibwmsv1order-events-order-events). |
| Already cancelled | 422 `{"status":"invalid","message":"… is Cancelled."}` | Treat as done. |
| Already fully dispatched | 422 `{"status":"invalid","message":"…"}` | Too late. Alert staff: the goods have left. |
| BWMS never saw the order | 404 `{"status":"unknown_order","message":"…"}` | Treat as done. Make sure no reservation for it is still waiting in the outbox ([8](#8-outbox-and-retries)). |

```json
{"status":"cancelled","bwms_status":"cancelled","reservation_status":"confirmed","reservation_id":10,"lines":[{"line_ref":"501","reserved_qty":0}]}
```

`reservation_status` always reads `confirmed`, even on a cancelled order. Ignore it after the first reservation.

**Every path that ends an order must send this.** See [12](#12-changes-in-the-portal-code).

### Stripe orders: the 30-minute window

**Decided 2026-11-07.** A Stripe order reserves at checkout like any other order, and is held from picking until paid ([below](#unpaid-orders-held-until-paid)). It's released if payment fails, or isn't confirmed within 30 minutes.

| Stripe outcome | Portal does | BWMS sees |
|---|---|---|
| Paid within the window | Confirms as today, and sends `paid` | Lifts the hold. The order can be picked. |
| Payment fails (`checkout.session.async_payment_failed`) | `release()` to `payment_failed` | Cancel |
| Not paid when the session expires (`checkout.session.expired`) | `release()` to `cancelled` | Cancel |

A declined card isn't a failure yet: the shopper can retry until the session expires, which is what the 30 minutes covers.

**The portal doesn't work this way today.** Stripe sessions last 24 hours and the sweep waits 25. To make the window real:
1. **Set `expires_at` on the checkout session** in `placeOrder()` to 31 minutes from now, worked out before the reservation call so `pay_by` is the same moment. Stripe refuses anything under 30 minutes after creation, so don't pass exactly 30.
2. **Tighten the sweep:** in `ReleaseAbandonedStripeOrders`, change `MIN_AGE_HOURS` (25) to 40 minutes, and run it every 5 minutes instead of hourly. It stays the safety net behind the webhook.
3. **Send the cancel from `StripeOrderPaymentService::release()`** ([12](#12-changes-in-the-portal-code)), so all of the above reach BWMS.

Payment arriving after the release goes down the existing late-payment path: `confirmPaid()` alerts admins and a person refunds or re-places it. The reservation doesn't come back.

### Unpaid orders: held until paid

**Decided 2026-11-07.** Any order paid by Stripe is sent as `awaiting_payment` until it's paid. BWMS reserves its stock but holds it out of picking, so nothing is picked or delivered unpaid. Invoice orders leave `payment_status` out and aren't held.

| Order | `pay_by` | Released at |
|---|---|---|
| Stripe checkout | The session's `expires_at`, 31 minutes out | Session expiry ([above](#stripe-orders-the-30-minute-window)) |
| Payment link (staff-entered) | `orders.payment_due_at`, which can be days away | The pay-by deadline |

**At placement,** send these on the reservation as well:

```json
{ "payment_status": "awaiting_payment", "pay_by": "2026-11-12T07:00:00Z" }
```

The 201 then includes `"payment_hold": true`, and so does every later response while the order is held.

| What happens | Portal sends | BWMS does |
|---|---|---|
| Paid (`confirmPaid()`) | `PUT` with `"payment_status": "paid"` and the next `version` | Lifts the hold. The order can be picked. |
| Not paid in time, or payment failed (`release()`) | The cancel in [7](#7-cancel) | Releases the stock |
| New pay-by date | `PUT` with `"payment_status": "awaiting_payment"` and `pay_by` | Updates the date. Stays held. |

**Send `paid` promptly.** Until it lands, a paid order stays out of picking. It goes through the outbox like any other call, after the reservation for that order ([8](#8-outbox-and-retries)). If the reservation is still waiting, it goes first, so the order is held and then released.

- **Only the first call sets a hold.** Sending `awaiting_payment` later doesn't hold an order that's already live, so a manager's **Release to pick** in BWMS stays released.
- **BWMS never cancels on its own at pay-by.** The portal decides, because it can see a last-minute payment. If no cancel arrives, the order stays held and is marked overdue for staff.
- **If a manager releases it by hand,** the order is picked and delivered unpaid. Later responses drop `payment_hold`. Keep collecting payment as normal.
- A `PUT` with `paid` on an order that isn't held changes nothing.

## 8. Outbox and retries

Anything that can't complete in the moment goes in an outbox, so nothing is lost when BWMS is away.

**Table**, for example `bwms_outbox`: `id, order_ref, order_id (nullable), method, path, idempotency_key, body, attempts, next_attempt_at, last_error`.

**Rules:**
- Store the **exact body** and the key. Re-sign on every attempt ([2](#2-signing-both-directions)).
- **Keep the order of calls per `order_ref`:** one at a time, oldest first, so a cancel can't overtake its reservation.
- Queue after the database commit. Never call BWMS inside a transaction.
- Schedule: 10s, 30s, 1m, 5m, then every 15m, with no limit while the order is open.
- Timeouts for outbox calls can be longer: 5 seconds to connect, 15 total.

| Response | Action |
|---|---|
| **2xx** | Delete the row. A reservation sets `reservation_status = 'confirmed'`. |
| Timeout, connection error, **5xx**, **503** | Retry on schedule, same key and body. |
| **429** | Retry after `Retry-After`. |
| **401** | Keep retrying and alert staff: a bad key or clock must not lose orders. |
| **400** | Stop and alert: a bug. |
| **409**, reservation | Set `reservation_status = 'reservation_failed'`, delete the row, email staff, show an admin banner. BWMS lists it too. A person decides. **No automatic cancellation.** |
| **409**, update | Delete the row and alert staff. BWMS kept its previous state. |
| **422** `unknown_product` | Keep the row, marked as blocked, and alert staff. Once it's mapped, retry with a **new key** ([3](#3-idempotency-keys)). |
| **422**, other | Delete the row and alert staff, except a cancel ([7](#7-cancel)). |
| **404** on a cancel | Delete the row. |

Idempotency records last 30 days. An outbox row older than that re-runs for real, which is safe: BWMS ignores a version it has already applied.

## 9. Reading from BWMS

For reconciliation and support. Both calls are signed, with an empty body and no `Idempotency-Key`.

**`GET /api/portal/v1/orders/{ref}`.** The source of truth for an order. `404 {"status":"unknown_order"}` if BWMS never saw it.

```json
{"status":"confirmed","bwms_status":"open","reservation_status":"confirmed","reservation_id":1,"lines":[{"line_ref":"501","reserved_qty":2,"dispatched_qty":0}]}
```

| `bwms_status` | Meaning |
|---|---|
| `open` | Reserved, waiting to be picked |
| `picking` | On a pick list |
| `partially_dispatched` | Some has left. The rest is still reserved. Portal orders don't normally reach this: see [11](#11-post-apibwmsv1order-events-order-events). |
| `dispatched` | Finished |
| `cancelled` | Cancelled |

`dispatched_qty` is cumulative and in sell units. It can be a decimal if BWMS shipped part of a pack, for example `0.5`.

**`GET /api/portal/v1/stock?product_ids=501,999`.** Comma-separated, up to 500. Unmapped IDs are left out.

```json
[{"portal_product_id":501,"available":3}]
```

## 10. `PUT /api/bwms/v1/stock`: stock from BWMS

BWMS calls the portal after stock changes, debounced by a few seconds.

```
PUT {PORTAL}/api/bwms/v1/stock
Content-Type: application/json
X-BWMS-Key-Id: …   X-BWMS-Timestamp: …   X-BWMS-Signature: …
```

The body is a bare JSON array, up to 100 items:

```json
[{"portal_product_id":501,"available":3,"as_of":"2026-11-06T11:31:20.123456Z"}]
```

- `available` is in sell units, and never below 0.
- `as_of` is a UTC timestamp with microseconds. Add `products.stock_as_of` and update `stock_qty = available` only when `as_of` is newer than the stored value, so a late delivery can't overwrite a fresher one.
- **Skip an ID you don't know and still answer 200.** A 4xx fails the whole batch.

| Your answer | BWMS does |
|---|---|
| 2xx | Done. An unchanged figure isn't resent for up to 24 hours. |
| 5xx or timeout | Retries the batch at 10s, 30s, 1m, 5m, 15m, for up to 12 hours |
| 4xx | **Doesn't retry.** It shows the error on BWMS's Sync screen. |

BWMS's timeouts are 5 seconds to connect and 15 total.

## 11. `POST /api/bwms/v1/order-events`: order events

BWMS calls the portal when goods leave, once per order, per pick list, and when staff cancel an order in BWMS.

```
POST {PORTAL}/api/bwms/v1/order-events
Content-Type: application/json
Idempotency-Key: bwms:order-event:ORD-7K2M9XQ4LP1A:12
X-BWMS-Key-Id: …   X-BWMS-Timestamp: …   X-BWMS-Signature: …
```

```json
{"order_ref":"ORD-7K2M9XQ4LP1A","event":"short_shipped","lines":[{"line_ref":"501","qty":1}],"at":"2026-11-06T11:31:20Z"}
```

| Field | Meaning |
|---|---|
| `event` | `dispatched`, or `short_shipped` if less was picked than was planned on this pick list. `cancelled` and `substituted` have their own rules: [cancelled](#cancelled-staff-cancelled-in-bwms), [substituted](#substituted-the-customer-accepted-another-product). |
| `lines[].qty` | Sell units that left **in this dispatch**. Can be a decimal, or 0. |
| `at` | When it left, UTC |

**Answer 200.** Dedupe on the `Idempotency-Key`: a redelivered event must change nothing, and above all must never credit twice.

| Your answer | BWMS does |
|---|---|
| 2xx | Done |
| 5xx or timeout | Retries at 30s, 1m, 5m, 15m, 1h, for up to 24 hours |
| 4xx | **Doesn't retry.** The event is logged in BWMS and lost to you. Use [9](#9-reading-from-bwms) to recover. |

> **Work out what shipped from the quantities, not the event name.**
> - **A `dispatched` event doesn't prove the order is complete.** It compares picked against what was planned, and a line BWMS couldn't find any stock for may be listed with `qty` 0, or not at all.
> - **A line missing from the event counts as 0 dispatched.**
> - **The exception:** a product BWMS doesn't stock-track, such as a service, never appears in events even though it ships. Don't map one to a portal product.

**On each `dispatched` or `short_shipped` event:**
1. Add each line's `qty` to that order line's `dispatched_qty`.
2. Work out **ordered − `dispatched_qty`** for every line of the order, including lines missing from the event.
3. **Nothing short:** set the order to `dispatched`. Done.
4. **Anything short:** credit it, as below. Set the order to `dispatched`.

**BWMS doesn't send events for:** a delivery date changed in BWMS, or delivery itself. Run a daily job over open orders that calls `GET /orders/{ref}` and flags any difference for staff.

### Short shipments (decided 2026-11-07)

**Credit at dispatch, from the dispatch event, for ordered − `dispatched_qty` per line.** Don't wait to see whether the rest ships. This replaces the 2026-10-27 decision on timing. The portal still invoices shipped quantities only.

For an order of 3 with `{"line_ref":"501","qty":1}` in the event, credit 2. A line of 4 missing from the event credits 4.

- **Not yet charged** (invoice, COD, wallet not yet captured): invoice and charge the dispatched quantities only.
- **Already charged** (Stripe captured, or a Xero invoice raised): issue a Xero credit note and/or a Stripe partial refund for (ordered − `dispatched_qty`) × unit price, including GST, and email the customer what was short and what was credited.
- Add what you credited to `order_items.credited_qty`, and record the credit note or refund reference on the order.
- It runs **once per event**: a redelivered event must never credit twice. Dedupe on the `Idempotency-Key` first.

**The remainder is already gone.** BWMS sets every portal order to cancel the rest of a short pick, so at dispatch it cancels what didn't leave and releases that stock. There's no backorder and no second event, and **the portal sends no cancel after a credit**. `GET /orders/{ref}` then shows `bwms_status: dispatched`, with `reserved_qty` 0.

This covers lines with no stock when the pick list was made too: they're cancelled in the same step, which is why a line missing from the event is credited in full.

### `cancelled`: staff cancelled in BWMS

**Decided 2026-11-07.** When staff use **Cancel order** or **Cancel rest** on a portal order in BWMS, BWMS tells the portal what was cancelled, and the portal credits from it.

```
POST {PORTAL}/api/bwms/v1/order-events
Idempotency-Key: bwms:order-event:ORD-7K2M9XQ4LP1A:cancelled
```

```json
{"order_ref":"ORD-7K2M9XQ4LP1A","event":"cancelled","reason":"No stock","lines":[{"line_ref":"501","qty":3}],"at":"2026-11-08T23:00:00Z"}
```

| Field | Meaning |
|---|---|
| `reason` | What staff entered. For staff, not for the customer. |
| `lines[].qty` | Sell units cancelled **by this cancel**, not the line's total. Can be a decimal. Lines with nothing cancelled are left out. |
| `at` | When it was cancelled, UTC |

Delivery and retries are the same as the table in [11](#11-post-apibwmsv1order-events-order-events). A retry sends exactly the same body. An order is only cancelled once, keyed `bwms:order-event:{ref}:cancelled`.

**Part of one line** (added 2026-11-15): staff can also cancel some of a single line, typically an oversold one the customer no longer wants. That sends the same `cancelled` body with just that line, under a key of its own, `bwms:order-event:{ref}:cancelled:line-{id}-{time}`, so an order can have several. Handle each the same way; the cap below stops any double credit.

**On a `cancelled` event:**
1. Dedupe on the `Idempotency-Key`.
2. For each line, work out **credit = the smaller of `qty` and (ordered − `dispatched_qty` − `credited_qty`)**. The cap stops a second credit on anything already credited at dispatch, which can happen with an order placed before portal orders cancelled the rest.
3. **Not yet charged:** invoice the dispatched quantities only, and credit nothing. If nothing was dispatched, there's no invoice.
4. **Already charged:** credit as in [Short shipments](#short-shipments-decided-2026-11-07).
5. **Unpaid and held:** nothing to credit. Close the order and stop any payment link, so the customer can't pay for it.
6. Set the order to `cancelled` if nothing was dispatched, otherwise `dispatched`, and email the customer.

**Don't send a cancel back.** BWMS has already cancelled it, and would answer `422`. Skip the [7](#7-cancel) `PUT` on this path. If one of the portal's own cancels crosses with this event, its `422` still means done.

**BWMS sends no `cancelled` event for:** the portal's own cancel, or the rest of a short pick cancelled at dispatch, which the dispatch event already covers.

### `substituted`: the customer accepted another product

**Added 2026-11-14.** When a line is short, staff may call the customer and agree a different product instead. In BWMS the replaced quantity of the original line is cancelled ("substituted", not short), and a new line is added for the substitute, at its own price. BWMS tells the portal both, and the portal credits or charges the difference.

```
POST {PORTAL}/api/bwms/v1/order-events
Idempotency-Key: bwms:order-event:ORD-7K2M9XQ4LP1A:substituted:42
```

```json
{
  "order_ref": "ORD-7K2M9XQ4LP1A",
  "event": "substituted",
  "substitution_id": 42,
  "original": { "line_ref": "501", "qty": 12, "unit_price": "5.0000" },
  "substitute": {
    "line_ref": "bwms-sub-42", "portal_product_id": 702, "sku": "COF", "name": "Coffee",
    "unit": "Carton", "unit_quantity": 6, "qty": 2, "unit_price": "24.0000"
  },
  "price_difference": "-12.00",
  "at": "2026-11-13T23:00:00Z"
}
```

| Field | Meaning |
|---|---|
| `substitution_id` | BWMS's id for this substitution. The same id comes back on an undo. |
| `original.qty` | Sell units of the original line cancelled **by this substitution**. Can be a decimal. The rest of the line, if any, is still ordered. |
| `original.unit_price`, `substitute.unit_price` | Per sell unit, ex GST, as BWMS holds them. Null when BWMS has no price for the line. Staff may have kept the original total (then `price_difference` is `0.00`). |
| `substitute.line_ref` | A new line, made by BWMS, always starting `bwms-sub-`. From now on it appears in `dispatched` / `short_shipped` / `cancelled` events and in `GET /orders/{ref}` like any other line. |
| `substitute.portal_product_id` | The portal product listed for the substitute, or null if it isn't sold on the portal. Use `sku` and `name` to show it either way. |
| `price_difference` | Substitute value − original value, ex GST. Positive: the customer pays more. Null if either price is unknown. |

**On a `substituted` event:**
1. Dedupe on the `Idempotency-Key`.
2. Add `original.qty` to that order item's `credited_qty`. It is not a short: don't credit it again at dispatch. Work out what's short at dispatch as **ordered − `dispatched_qty` − `credited_qty`** (see below).
3. Add an order item for the substitute: `line_ref`, product, `qty`, `unit_price`. Its dispatch arrives in the dispatch events like any line.
4. **Not yet charged:** invoice what ships, at the new line's price. Nothing else to do.
5. **Already charged:** if `price_difference` is negative, refund it (Xero credit note and/or Stripe partial refund, including GST); if positive, charge it or add it to the next invoice, as for any order change. Email the customer the change.

**`substitution_undone`.** Staff can undo a substitution until the substitute is picked. BWMS then sends the same body with `"event": "substitution_undone"` and `at` set to the undo time, keyed `bwms:order-event:{ref}:substitution_undone:{id}`. Reverse it: take `original.qty` off `credited_qty`, remove the `bwms-sub-` item, and reverse any refund or charge made for the difference.

**Re-sends.** A `PUT /orders/{ref}` repeats the original line as the customer ordered it; BWMS keeps the substitution, so don't change your line to match. Never send `bwms-sub-` lines back in a `PUT`: BWMS owns them, and a line left out of a `PUT` does not cancel them.

**Short at dispatch** now means **ordered − `dispatched_qty` − `credited_qty`** for each line, so a substituted quantity is never credited twice.

## 12. Changes in the portal code

Line numbers are as of commit `6bccbc2`.

**`app/Services/CheckoutService.php`, `placeOrder()`:**
1. **Send `payment_status` and `pay_by`** whenever `$paymentMethod` is `stripe` ([held until paid](#unpaid-orders-held-until-paid)). This covers `confirm()` and `placeOnBehalf()`.
2. **Mint `reference` before the transaction.** Today it's created inside `Order::create()` (~line 519), but BWMS needs it first.
3. **Call BWMS after every local check and before `DB::transaction()` (~line 505).** Both `confirm()` and `placeOnBehalf()` go through here, so staff-entered orders are covered.
4. Act on the response ([5](#5-handling-the-response-at-checkout)). Add `orders.reservation_status`, nullable: `confirmed`, `unconfirmed`, `reservation_failed`.
5. Keep the `stock_qty` decrement. It's now only a display cache, and BWMS's push overwrites it. If it throws after a 201, queue a cancel ([5](#5-handling-the-response-at-checkout)).
6. Add `orders.bwms_version`, default 0.
7. Set the Stripe session's `expires_at` ([Stripe orders](#stripe-orders-the-30-minute-window)).

**`order_items`:** add `dispatched_qty` and `credited_qty`, decimal, default 0, for [11](#11-post-apibwmsv1order-events-order-events).

**Send a cancel ([7](#7-cancel)) from every path that ends an order:**

| Where | Line |
|---|---|
| `OrderAdminService::cancel()` | ~130 |
| `Api/OrderController` | ~88 |
| `StripeOrderPaymentService::release()`, which covers `ReleaseAbandonedStripeOrders`, `PaymentLinkService` and the Stripe webhooks | ~83 |

Check for any other move to `cancelled`, `payment_failed` or an expired state.

**Send `paid` from `StripeOrderPaymentService::confirmPaid()`** for every Stripe order, once the order is confirmed. Both the webhook and the sweep go through it.

**Add two routes** in `routes/api.php`, outside the `auth` and `throttle` groups, stateless, with a middleware that verifies the signature ([2](#2-signing-both-directions)):
- `PUT /api/bwms/v1/stock` ([10](#10-put-apibwmsv1stock-stock-from-bwms))
- `POST /api/bwms/v1/order-events` ([11](#11-post-apibwmsv1order-events-order-events))

**The HTTP client:** one service, used for checkout and the outbox. It signs, sets the timeouts, and never logs secrets.

## 13. Go-live

1. **Exchange keys.** The portal's key goes into BWMS's `PORTAL_API_KEYS`. BWMS's `PORTAL_OUTBOUND_KEY_ID` and `PORTAL_OUTBOUND_SECRET` go into `BWMS_INBOUND_KEYS`.
2. **Map every portal product.** Export `id` as `portal_item_id`, with `sku`, `name` and `is_active`, plus `pack_name` and `pack_size` chosen by a person: the BWMS pack equal to **one portal sell unit**. Don't derive them from `unit_quantity`: the same sell unit can be written "1 × carton" or "12 × bottle", and `ImportRealProducts` wrote every product as `carton / 1`.
3. **Turn on BWMS's API** (`PORTAL_API_ENABLED`), then `BWMS_ENABLED`. The portal starts reserving.
4. **Turn on stock and events** in BWMS (`PORTAL_PUSH_ENABLED`, `PORTAL_EVENTS_ENABLED`, then "Enable all portal listings" on its Sync screen).
5. **Turn off the portal's own Ordermentum sync,** so BWMS is the only stock writer: set `ORDERMENTUM_SYNC_MODE=off` and stop `PushProductStockToOrdermentum`.

## 14. Acceptance checks

Build tests for these against a stub of BWMS:

- [ ] The signature matches both test vectors in [2](#2-signing-both-directions).
- [ ] 201 places the order. `reservation_status = 'confirmed'`.
- [ ] 409 shows `available` per line and saves nothing.
- [ ] 422 shows a plain message, saves nothing, and alerts staff.
- [ ] BWMS down (timeout, 5xx, 503) still places the order within about 3 seconds, as `unconfirmed`, with an outbox row.
- [ ] An outbox retry uses the same key and body with a new timestamp and signature, and a 201 sets `confirmed`.
- [ ] An outbox 409 sets `reservation_failed`, alerts staff and doesn't cancel.
- [ ] A 201 followed by a local failure queues a cancel.
- [ ] A refused shopper's next try uses a new `reference`.
- [ ] Every cancel path sends a `PUT` with a higher version, the customer, the date and `lines: []`.
- [ ] Every `PUT` includes `customer` and `requested_delivery_date`.
- [ ] A bad postcode is left out, never sent.
- [ ] No BWMS call happens inside a database transaction.
- [ ] Calls to the two inbound routes without a valid signature get 401.
- [ ] A stock push with an older `as_of` is ignored, and an unknown product ID still gets 200.
- [ ] A redelivered order event changes nothing and credits nothing.
- [ ] A `cancelled` event credits `qty` per line, and sends no cancel back.
- [ ] A `cancelled` event on a line already credited at dispatch credits nothing more.
- [ ] A `cancelled` event on an unpaid order credits nothing and stops its payment link.
- [ ] A short event credits ordered − `dispatched_qty` − `credited_qty` per line and sends nothing back to BWMS.
- [ ] A `substituted` event adds the `bwms-sub-` line, adds `original.qty` to the original's `credited_qty`, and credits or charges `price_difference` once.
- [ ] A dispatch after a substitution doesn't credit the substituted quantity again.
- [ ] A `substitution_undone` event reverses both the line and the money.
- [ ] A `PUT` after a substitution never includes `bwms-sub-` lines.
- [ ] A `dispatched` event missing a line credits that line in full.
- [ ] An unpaid checkout order is released and cancelled in BWMS about 30 minutes after placing.
- [ ] A `payment_failed` order is cancelled in BWMS.
- [ ] A payment-link order isn't released at 30 minutes.
- [ ] Every Stripe order is sent as `awaiting_payment` with `pay_by`, and paying it sends `paid`.
- [ ] A checkout order's `pay_by` matches its session's `expires_at`.
- [ ] An invoice order sends no `payment_status`.
- [ ] Reaching pay-by unpaid sends the cancel.
