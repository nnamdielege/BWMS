# Ordermentum sync

This doc covers how BWMS pushes stock to Ordermentum and takes in Ordermentum orders (spec B3.2). Status and actions are on the **Sync** screen (`/sync`).

## What Ordermentum does (confirmed by Ordermentum support, 2026-10-27)

- **Authentication** is an API token in `x-api-key` (`ORDERMENTUM_API_KEY`). Username/password is not supported in BWMS. The token is never logged.
- **Stock tracking.** For items with stock tracking on, Ordermentum **decrements its own stock when an order is placed**, and stock can't go negative there (it blocks the order instead). Items with tracking off don't decrement: `om:probe tracking` and the Sync screen list them.
- **Stock API limits:** PUT 20/min and POST 12/min. **Bulk `PATCH /v1/items` has no rate limit**, but BWMS keeps it polite: batches of about 50, at most one call every 5 seconds.
- **Webhooks are signed:** header `x-ordermentum-signature` = HMAC-SHA256 of the raw body. The secret is `sharedSecret` from `GET /v1/suppliers/{supplierId}`.
- **An order edit** makes Ordermentum adjust its own stock. **A cancel does not restore Ordermentum's stock.** BWMS's absolute push puts it back.
- **There are no open questions with Ordermentum.**

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
- **Bulk preferred.** One pending item goes by `PUT /v1/items/{variantId}`. More go by **bulk `PATCH /v1/items`** in chunks of 50, spaced 5s apart, with up to 3 calls per run. `om:probe write-test` proves live that `UPDATE` sets the quantity, and the nightly check would catch it if not.
- **Changes mid-push.** A change that lands during a push stays pending for the next run.
- **Errors.** An unchanged figure is skipped for up to 24h. 5xx and timeouts retry with the items still pending. 429 honours `Retry-After`. A 4xx is shown on the Sync screen.
- **Edits and cancellations.** BWMS re-fetches the order, applies the reservation delta, then force-pushes the absolute figure for every SKU on the order.
  - **Edit:** Ordermentum has already adjusted its own stock, so the push confirms the figure.
  - **Cancel:** Ordermentum does not restore stock. BWMS releases the reservation, and the push is what gives Ordermentum the stock back.
- **Nightly check (03:00).** Compares Ordermentum's figure **and tracking flag** with BWMS, and re-pushes mismatches (D-9).

**Rate limits.** The shared client keeps a budget per endpoint (`services.ordermentum.rate_limits`): PUT 20/min, POST 12/min. Bulk PATCH has no budget, but there is a minimum gap between calls (`ORDERMENTUM_PATCH_INTERVAL_SECONDS`, default 5). A short wait is slept out. A longer one makes the job release itself and retry later.

**Full push time (about 120 listings):**
- **Bulk (default):** 3 PATCH calls of up to 50, 5s apart: about 10–15 seconds.
- **PUT only** (`ORDERMENTUM_BULK_PUSH=false`): 120 ÷ 20/min, about 6 minutes.
- **Day to day:** a burst of changes becomes one run, normally a single PUT or PATCH.

**Inbound orders**
1. The webhook arrives at `POST /webhooks/ordermentum/{token}`, or at `/webhooks/ordermentum` once the token is made optional. BWMS checks the token (if required) and **enforces** the signature (see below).
2. It logs the headers only (never the body or the token), and stores the raw payload in `integration_inbox`. A redelivery is stored only once (event + order id + updatedAt).
3. It replies 200 straight away.
4. A queued job fetches the order from the API (`GET /v1/orders/{id}?includeLines=true`).

Other inbound behaviour:
- **Polling.** Every 10 minutes, a poll of `GET /v2/orders?updatedAt[gte]=…` catches missed webhooks.
  - The filter was verified live on 2026-10-27: 185 orders matched since 1 October, out of 13,303. Results are not sorted, and the API accepts no sort parameter.
  - The checkpoint only advances once every page has been read.
- **Upserts.** Each order is a full-state upsert, versioned by `updatedAt` in ms.
- **Unmapped variants.** A line with an unmapped variant holds the whole order until it's mapped on the Sync screen.

**Webhook signature** (enforced)
- **Secret:** run `php artisan om:probe secret --write-env`. It writes `ORDERMENTUM_WEBHOOK_SECRET`, and prints found/length only.
- **Check:** `hash_equals` on HMAC-SHA256 of the raw body against `x-ordermentum-signature`.
  - Hex or base64 is accepted (optionally `sha256=`-prefixed).
  - The inbox row records `valid_hex` or `valid_base64`, and the log line says which matched.
  - A missing or wrong signature, or a missing secret, is **401** and isn't stored.
- **Path token:** once one real event has verified, `ORDERMENTUM_WEBHOOK_REQUIRE_TOKEN=false` makes it optional. This only applies while enforcement is on. A token that is supplied must still match.
- **Fetch-back:** every order is still re-fetched from the API before BWMS acts on it.

**Line item fields** (confirmed on a live order, 2026-10-27)
- `lineItems[].quantity` is an integer.
- `lineItems[].variantId` (also `variant.id`) is the stock API item ID and matches the legacy mapping.
- `ORDERMENTUM_LINE_VARIANT_FIELDS` can re-point the field without a code change. An order whose lines don't match fails with the keys it found; fix the setting, then replay it.

## Cutover

1. Legacy migration committed (docs/MIGRATION.md). Listings exist, all off.
2. Re-run `om:probe tracking` and turn tracking on in Ordermentum for anything it lists. On 2026-10-27 all 120 mapped variants were tracked.
3. Turn off the portal's own Ordermentum sync (D-6). Switch the portal to token auth before 1 Jan 2027 if it's still running (docs/portal-om-token.md).
4. Run `php artisan om:probe secret --write-env`, then `php artisan config:cache`.
5. Register the webhook in Ordermentum (`order_created`, `order_updated`) at `https://<host>/webhooks/ordermentum/<ORDERMENTUM_WEBHOOK_TOKEN>`.
6. Set `ORDERMENTUM_INGEST_ENABLED=true`, then watch the Sync screen's inbound events. The signature column should show `valid_hex` or `valid_base64`. Then (optionally) set `ORDERMENTUM_WEBHOOK_REQUIRE_TOKEN=false`.
7. After the stocktake opening balances are posted:
   1. Set `ORDERMENTUM_PUSH_ENABLED=true`.
   2. Click "Enable all". This queues a forced push of every listing it enables.
   3. Optionally, run `php artisan channels:ordermentum push` to re-send everything.

## Not done yet

- **Alerts.** Email/Slack alerts on sustained failure are Phase 2.
- **Order-status mapping.** No status mapping beyond cancel.
- **Health endpoint.** A health endpoint (NFR-12) isn't built. Queue depth and failures are on the Sync screen.
