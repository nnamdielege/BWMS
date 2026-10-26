# Ordermentum probe: test procedure

`php artisan om:probe` makes read-only calls to the live Ordermentum account. Use it to close three open spec items (requirements B11):

| Item | Question | How the probe answers it |
|---|---|---|
| 1 | Which field on an order line holds the variant ID? (`lineItems` and `lineItem.quantity` were confirmed by support on 2026-10-26.) | `om:probe order {id}` |
| 2 | Does Ordermentum decrement its own stock when an order is placed, and at which stage? | The decrement test below |
| 4 | Which stock API context headers work: `context-*` or `x-context-*`? | `om:probe stock {id} --headers=…` |

The probe only sends GET requests to Ordermentum. The exception is the login POST, made while we are still on username/password auth. It never writes stock, orders or anything else. The test order in step 3 is placed by hand in Ordermentum, not by the probe.

## Commands

```
php artisan om:probe order {orderId}                # raw order JSON + line item field table
php artisan om:probe order {orderId} --json > f     # raw body only, for saving
php artisan om:probe order {orderId} --no-include-lines
php artisan om:probe stock {itemId} --label="..."   # stock reading with timestamp, appended to storage/logs/om-probe.jsonl
php artisan om:probe stock {itemId} --headers=plain # send only context-entity/context-id
php artisan om:probe stock {itemId} --headers=x     # send only x-context-entity/x-context-id
php artisan om:probe stock {itemId} --location={id} # override ORDERMENTUM_LOCATION_ID
```

`{itemId}` is the Ordermentum **variant ID**. That this is the right ID for `/v1/items` is likely but not confirmed (spec B3.1).

## Setup

Set these in the `.env` of whichever copy of the new app you run it from (your machine is fine):

| Variable | Value |
|---|---|
| `ORDERMENTUM_USERNAME`, `ORDERMENTUM_PASSWORD` | Same as the old app's `.env` |
| `ORDERMENTUM_SUPPLIER_ID`, `ORDERMENTUM_LOCATION_ID` | Same as the old app's `.env` |
| `ORDERMENTUM_BASE_URL` | Leave unset (default `https://api.ordermentum.com`). **Do not copy the old app's value**: it may end in `/v1`, and the probe adds `/v1` itself. |
| `ORDERMENTUM_API_KEY` | Leave empty until the key is issued. Once set, the probe uses `x-api-key` and stops logging in. |

Check the setup with `php artisan om:probe stock {itemId}`. A `Fetched … | HTTP 200` header means it works. `Ordermentum login failed (HTTP 401)` means the credentials are wrong.

## Procedure

**Before you start**
- Pick a cheap, tracked product with stock above 5 that is unlikely to be ordered by anyone else during the test. Note its variant ID. You can get it from the Ordermentum supplier platform or from `products.ordermentum_variant_id` in the legacy data.
- Place the order from an **internal/test retailer account**, not a real customer.
- Confirm that nothing else writes stock to Ordermentum during the test:
  - The old BWMS is offline, and its cron is stopped.
  - The portal's Ordermentum sync must be in `log_only` mode.
- ⚠ Find out whether invoicing in Ordermentum pushes to accounting. If it does, plan to void the invoice afterwards, or stop at "accepted".

**Steps.** Record the clock time of each Ordermentum action, so it can be matched against the probe readings.

1. **Headers (item 4).** Run each of these and note which return HTTP 200:
   - `om:probe stock {itemId} --headers=plain --label="headers plain"`
   - `om:probe stock {itemId} --headers=x --label="headers x"`
   - `om:probe stock {itemId} --headers=both --label="headers both"`

   For the rest of the test, use one that worked.
2. **Baseline.** `om:probe stock {itemId} --label="baseline"`. Note `available` and `tracked`. If `tracked` is false, Ordermentum is not counting this item, so choose another.
3. **Place the order.** In Ordermentum, as the test retailer, order **1 unit** of the item. Note the order ID.
4. **Placed.** Wait 1 minute, then run `om:probe stock {itemId} --label="placed"`.
5. **Line items (item 1).** Save the response, then read its field table:
   - `om:probe order {orderId} --json > om-order.json` (save it outside the repo: it contains retailer details)
   - `om:probe order {orderId}` and read the "Product / variant / SKU / quantity fields" table. Note which path carries the variant ID and whether it matches `{itemId}`. If no line array appears, repeat with `--no-include-lines` and compare.
6. **Accepted.** Accept the order in Ordermentum. Wait 1 minute, then run `om:probe stock {itemId} --label="accepted"`.
7. **Invoiced.** Invoice it (see ⚠ above). Wait 1 minute, then run `om:probe stock {itemId} --label="invoiced"`.
8. **Cancel.** Cancel or credit the test order. Wait 1 minute, then run `om:probe stock {itemId} --label="cancelled"`. This shows whether Ordermentum gives the stock back.
9. **Recheck.** Run `om:probe stock {itemId} --label="final +10min"` 10 minutes later, to catch delayed decrements.

## Reading the result

| `available` compared with baseline | Conclusion |
|---|---|
| −1 at "placed" | Ordermentum decrements at placement. This matches the legacy negative-stock evidence. |
| unchanged at "placed", −1 at "accepted" or "invoiced" | Ordermentum decrements at that stage. |
| unchanged throughout | Ordermentum does not decrement; tracked stock only moves when we push. |
| back to baseline at "cancelled" | Ordermentum restores stock on cancel. |
| any other change | Someone else ordered the item, or something else wrote stock. Repeat the test with a different item. |

A change of 1 means one Ordermentum unit of that variant. That is often a carton, not a single item (spec D-1).

## Send back

- The lines from `storage/logs/om-probe.jsonl` for this test. They contain stock figures only.
- The variant ID path from step 5, plus the line item key names. Leave out retailer names, addresses and prices.
- Which header style returned 200.

The spec (B3.1) is then updated to mark items 1, 2 and 4 confirmed from a live test.
