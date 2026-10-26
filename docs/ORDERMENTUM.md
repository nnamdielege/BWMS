# Ordermentum sync

This doc covers how BWMS pushes stock to Ordermentum and takes in Ordermentum orders (spec B3.2). Status and actions are on the **Sync** screen (`/sync`).

## What Ordermentum does (confirmed by Ordermentum support, 2026-10-27)

- **Authentication** is an API token in `x-api-key` (`ORDERMENTUM_API_KEY`). Username/password is not supported in BWMS. The token is never logged.
- **Stock tracking.** For items with stock tracking on, Ordermentum **decrements its own stock when an order is placed**, and stock can't go negative there (it blocks the order instead). Items with tracking off don't decrement: `om:probe tracking` and the Sync screen list them.
- **Stock API limits:** PUT 20/min and POST 12/min. The PATCH limit isn't confirmed (asked), so BWMS treats it like POST.
- **Webhooks are signed** with a header, but the header name and algorithm aren't confirmed yet (asked).
- **Still open with Ordermentum:**
  - the signature details
  - the PATCH limit
  - whether a cancel or edit restores Ordermentum's stock

## Switches

All three are **off** by default. Turn them on only at cutover.

| Setting | Effect |
|---|---|
| `ORDERMENTUM_PUSH_ENABLED` | Lets BWMS push stock. Each listing must also be switched on (Sync screen → Listings, or "Enable all"). |
| `ORDERMENTUM_INGEST_ENABLED` | Lets BWMS process Ordermentum orders. While off, webhooks are stored as `held` and can be replayed later. |
| `ORDERMENTUM_WEBHOOK_TOKEN` | A long random string, used as the secret in the webhook path. Without it the endpoint returns 404. |

Also set `ORDERMENTUM_SUPPLIER_ID` and `ORDERMENTUM_LOCATION_ID`. See `.env.example`.

With the API token, the stock API ignores the supplier context headers on reads (even a wrong supplier ID returns 200): the token scopes access to the supplier. BWMS still sends `context-*` (`ORDERMENTUM_CONTEXT_HEADERS=plain`), as the docs and the portal do. Writes have not been tested against this, because the probe is read-only.

## How it works

**Orders become reservations only**
- Ordermentum has already taken the stock off its own count at placement. BWMS records the order as a **reservation** and never deducts in Ordermentum again.
- The next push sends BWMS's absolute figure (available − safety buffer), which then matches Ordermentum's decremented count.
- Orders are still always accepted (D-3) as a safety net for sync lag. One that exceeds BWMS stock is flagged oversold.

**Order processing comes before stock pushes**
- Stock changes only flag listings (`push_requested_at`) and queue one debounced run of `PushOrdermentumStock`.
- **Pending orders first.** While any Ordermentum order event is still waiting to be processed, the run waits. Without this, a push of a stale BWMS figure could overwrite a decrement Ordermentum has made but BWMS hasn't seen yet. An event older than `ORDERMENTUM_INGEST_WAIT_MINUTES` (10) counts as stuck: it's logged and no longer blocks.
- **Held orders.** SKUs on an order held for mapping (`needs_mapping`) aren't pushed until it is mapped. The Sync screen shows them as "Held".
- **Bulk preferred.** One pending item goes by `PUT /v1/items/{variantId}`. More go by **bulk `PATCH /v1/items`** in chunks of 50. `UPDATE` is assumed to set the quantity (unconfirmed); the nightly check would expose it if not.
- **Changes mid-push.** A change that lands during a push stays pending for the next run.
- **Errors.** An unchanged figure is skipped for up to 24h. 5xx and timeouts retry with the items still pending. 429 honours `Retry-After`. A 4xx is shown on the Sync screen.
- **Edits and cancellations.** BWMS re-fetches the order and updates the reservation. It then force-pushes the absolute figure for every SKU on the order, because it's unknown whether Ordermentum restored its own stock.
- **Nightly check (03:00).** Compares Ordermentum's figure **and tracking flag** with BWMS, and re-pushes mismatches (D-9).

**Rate limits.** The shared client keeps a budget per endpoint (`services.ordermentum.rate_limits`). A short wait is slept out. A longer one makes the job release itself and retry later.

**Inbound orders**
1. The webhook arrives at `POST /webhooks/ordermentum/{token}`. BWMS checks the path token, then checks the signature (see below).
2. It logs the headers only (never the body or the token), and stores the raw payload in `integration_inbox`. A redelivery is stored only once (event + order id + updatedAt).
3. It replies 200 straight away.
4. A queued job fetches the order from the API (`GET /v1/orders/{id}?includeLines=true`).

Other inbound behaviour:
- **Polling.** Every 10 minutes, a poll of `GET /v2/orders?updatedAt[gte]=…` catches missed webhooks.
  - The filter was verified live on 2026-10-27: 185 orders matched since 1 October, out of 13,303. Results are not sorted, and the API accepts no sort parameter.
  - The checkpoint only advances once every page has been read.
- **Upserts.** Each order is a full-state upsert, versioned by `updatedAt` in ms.
- **Unmapped variants.** A line with an unmapped variant holds the whole order until it's mapped on the Sync screen.

**Webhook signature** (`ORDERMENTUM_WEBHOOK_SIGNATURE_*`)
- **What's checked:** an HMAC of the raw body against a configurable header, in hex or base64, with an optional `sha256=` prefix.
- **Mode `report` (default):** records valid / invalid / missing / unconfigured on the inbox row, and never rejects.
- **After Ordermentum confirms the scheme:** set the header, secret and algorithm, then switch to `enforce`. Until then, the path token and fetching the order back from the API are the guard.
- **Finding the header:** the logged headers show which one Ordermentum sends.

**Line item fields** (confirmed on a live order, 2026-10-27)
- `lineItems[].quantity` is an integer.
- `lineItems[].variantId` (also `variant.id`) is the stock API item ID and matches the legacy mapping.
- `ORDERMENTUM_LINE_VARIANT_FIELDS` can re-point the field without a code change. An order whose lines don't match fails with the keys it found; fix the setting, then replay it.

## Cutover

1. Legacy migration committed (docs/MIGRATION.md). Listings exist, all off.
2. Re-run `om:probe tracking` and turn tracking on in Ordermentum for anything it lists. On 2026-10-27 all 120 mapped variants were tracked.
3. Turn off the portal's own Ordermentum sync (D-6). Switch the portal to token auth before 1 Jan 2027 if it's still running (docs/portal-om-token.md).
4. Register the webhook in Ordermentum (`order_created`, `order_updated`) at `https://<host>/webhooks/ordermentum/<ORDERMENTUM_WEBHOOK_TOKEN>`.
5. Set `ORDERMENTUM_INGEST_ENABLED=true`, then watch the Sync screen's inbound events and signature column.
6. After the stocktake opening balances are posted:
   1. Set `ORDERMENTUM_PUSH_ENABLED=true`.
   2. Click "Enable all".
   3. Run `php artisan channels:ordermentum push`.

## Not done yet

- **Alerts.** Email/Slack alerts on sustained failure are Phase 2.
- **Order-status mapping.** No status mapping beyond cancel.
- **Health endpoint.** A health endpoint (NFR-12) isn't built. Queue depth and failures are on the Sync screen.
