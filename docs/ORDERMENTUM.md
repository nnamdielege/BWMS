# Ordermentum sync

This doc covers how BWMS pushes stock to Ordermentum and takes in Ordermentum orders (spec B3.2). Status and actions are on the **Sync** screen (`/sync`).

## Switches

All three are **off** by default. Turn them on only at cutover.

| Setting | Effect |
|---|---|
| `ORDERMENTUM_PUSH_ENABLED` | Lets BWMS push stock. Each listing must also be switched on (Sync screen → Listings, or "Enable all"). |
| `ORDERMENTUM_INGEST_ENABLED` | Lets BWMS process Ordermentum orders. While off, webhooks are stored as `held` and can be replayed later. |
| `ORDERMENTUM_WEBHOOK_TOKEN` | A long random string, used as the secret in the webhook path. Without it the endpoint returns 404. |

Credentials are `ORDERMENTUM_API_KEY`, or username/password until the API key is issued (deprecated 1 Jan 2027). Also set `ORDERMENTUM_SUPPLIER_ID` and `ORDERMENTUM_LOCATION_ID`. See `.env.example`.

## How it works

**Stock push**
- Any stock or reservation change runs this sequence:
  1. `StockChanged` fires after the transaction commits.
  2. One `PushChannelStock` job is queued per product, delayed 10s. A burst of changes becomes one push.
  3. The job sends `PUT /v1/items/{variantId}`.
- Each push sets `available = max(0, floor(available_base / pack) − safety buffer)`, `tracked: true`, `allowOversell: false`.
- An unchanged figure is skipped for up to 24h. 5xx and timeouts retry with backoff. A 429 waits for `Retry-After`. A 4xx is shown on the Sync screen until fixed.
- Requests are capped client-side at 40/min. Ordermentum doesn't document stock API limits, so the cap is set by `ORDERMENTUM_STOCK_RPM`.
- **Nightly check (03:00).** Compares Ordermentum's figure with BWMS and re-pushes any mismatch. BWMS always wins (D-9).

**Orders**
- **Inbound path:**
  1. Ordermentum posts the `order_created` / `order_updated` webhook to `POST /webhooks/ordermentum/{token}`.
  2. The raw payload is stored in `integration_inbox`. A redelivery is stored only once, matched on event + order id + updatedAt.
  3. BWMS replies 200 straight away.
  4. A queued job fetches the order from the API (`GET /v1/orders/{id}?includeLines=true`). The webhook body is never trusted.
- **Every 10 minutes**, a poll of `GET /v2/orders` catches missed webhooks.
- Each order is applied as a full-state upsert, versioned by `updatedAt` in milliseconds:
  - Replays and late deliveries are harmless.
  - A `cancelledAt` value releases the reservation.
  - Orders are always accepted (D-3). One that exceeds stock is flagged oversold.
- A line whose variant has no listing holds the whole order (`needs_mapping`), and nothing is reserved. Mapping the variant on the Sync screen replays the order.
- Unknown retailers become customers named "Ordermentum retailer …". Rename them as needed.

**Line item fields.** Line items and `lineItem.quantity` are confirmed (support, 2026-10-26). **The variant ID field is not confirmed.**
- `config/bwms.php` → `ordermentum.order_mapping` tries `variantId`, `variant.id`, `variant_id` and `productVariantId`, in that order.
- If none is present, the order fails with the line's actual keys in the error.
- After `php artisan om:probe order {id}` shows the real field, set `ORDERMENTUM_LINE_VARIANT_FIELDS=<path>`, run `php artisan config:cache`, and replay the failed events from the Sync screen.

## Cutover

1. Legacy migration committed (docs/MIGRATION.md). Listings exist, all off.
2. Confirm the variant field with `om:probe` (docs/om-probe.md), and set `ORDERMENTUM_LINE_VARIANT_FIELDS`.
3. Turn off the portal's own Ordermentum sync (D-6), so BWMS is the only stock writer.
4. Register the webhook in Ordermentum (`POST /v1/webhooks`, events `order_created` and `order_updated`), with URL `https://<host>/webhooks/ordermentum/<ORDERMENTUM_WEBHOOK_TOKEN>`.
5. Set `ORDERMENTUM_INGEST_ENABLED=true`, then watch the Sync screen's inbound events.
6. After the stocktake opening balances are posted:
   1. Set `ORDERMENTUM_PUSH_ENABLED=true`.
   2. Click "Enable all" on the Sync screen.
   3. Run `php artisan channels:ordermentum push` (a forced full push, rate-limited).

## Not done yet

- **Bulk updates.** Bulk `PATCH /v1/items` for large pushes isn't built. Pushes go one per product. At 40/min, a full push of about 120 listings takes about 3 minutes.
- **Alerts.** Email/Slack alerts on sustained failure are Phase 2. For now the Sync screen and dashboard show problems.
- **Order-status mapping.** No status mapping beyond cancel; the status enum is unconfirmed.
- **Health endpoint.** A health endpoint reporting queue lag (NFR-12) isn't built. Queue depth and failures are on the Sync screen.
