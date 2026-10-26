# Ordermentum probe

`php artisan om:probe` makes **read-only** calls to the live Ordermentum account. It only sends GET requests and never writes anything to Ordermentum. (`tracking` saves what it reads on BWMS's own listings.)

Ordermentum confirmed on 2026-10-27 that it decrements tracked stock at order placement. The decrement test is therefore no longer needed. The probe still answers three questions:

| Question | Command |
|---|---|
| Which line item field holds the variant ID? (`lineItems` and `lineItem.quantity` are confirmed.) | `om:probe order {id} --structure --headers=both` |
| Which stock API supplier header works: `context-*` or `x-context-*`? | Same command (or `om:probe stock {variantId} --headers=plain` / `--headers=x`) |
| Which mapped products have stock tracking off? (Ordermentum only decrements tracked items.) | `om:probe tracking` |

## Commands

```
php artisan om:probe recent [--limit=5]                       # recent order IDs, status and dates only
php artisan om:probe order {orderId} --structure --headers=both
php artisan om:probe order {orderId}                          # full raw JSON (contains customer details: do not share)
php artisan om:probe stock {variantId} [--headers=plain|x|both] [--location=…]
php artisan om:probe tracking                                 # every mapped listing; result also shown on the Sync screen
```

**`--structure`** prints each field path and its type. It prints values only for ID and quantity fields inside order lines. Retailer names, addresses, notes and prices are never printed, so its output is safe to share.

**`--headers=both`** on an order works as follows:
1. It takes every UUID-looking field on the first line.
2. It looks each one up on the stock API, once with `context-*` headers and once with `x-context-*`.
3. The field that returns 200 is the stock item ID, which tells you the variant field. The header style that returns 200 is the one to use.

## Setup

In the new app's `.env`, set:
- `ORDERMENTUM_API_KEY` (the API token; username/password is not supported)
- `ORDERMENTUM_SUPPLIER_ID`
- `ORDERMENTUM_LOCATION_ID`

Leave `ORDERMENTUM_BASE_URL` unset. Requests are rate-limited client-side (`services.ordermentum.rate_limits`).

## Procedure

1. **Pick an order.** Run `php artisan om:probe recent` and choose a recent order that isn't cancelled.
2. **Inspect it.** Run `php artisan om:probe order {id} --structure --headers=both`. From the output, note:
   - the line array path and the quantity field (expected: `lineItems`, `quantity`)
   - which line field returned HTTP 200 from the stock API: that's the variant ID field
   - which header style returned 200
3. **Wire it in:**
   - `ORDERMENTUM_LINE_VARIANT_FIELDS=<field path>`, for example `variantId`
   - `ORDERMENTUM_CONTEXT_HEADERS=plain` (or `x`)
   - then `php artisan config:cache`
4. **Tracking.** Run `php artisan om:probe tracking`, then turn tracking on in Ordermentum for every product it lists. Untracked items don't decrement on orders, so BWMS stock would be the only control.

## Send back

- The `--structure` table for one order: field names and line ID/quantity values only.
- The header-check table from step 2.
- The `tracking` summary (counts, plus the SKUs listed as untracked or missing).
