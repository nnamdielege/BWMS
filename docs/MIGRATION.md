# Legacy data migration

`php artisan bwms:migrate-legacy` imports the old BWMS data from a **restored copy** of the production dump (spec B9, audit §9.3). It never connects to production.

## What it imports

| Legacy | New | Notes |
|---|---|---|
| `product_categories` | categories | A category with the same name and no legacy ID is linked, not duplicated. |
| `products` | products, one `Unit` pack (base qty 1), barcode if a valid GTIN | SKU is uppercased and trimmed. Cost goes to `avg_cost`, price to `sell_price`, reorder point/qty kept. Soft-deleted products are imported soft-deleted. |
| `products.ordermentum_*` | `channel_listings` (channel `ordermentum`) | **`sync_enabled = false`**: nothing is pushed to Ordermentum until listings are switched on at cutover. |
| `suppliers`, `customers` | suppliers, customers | Address lines are joined. Mobile, tax ID, payment terms and credit limit go to notes. |
| `purchase_orders` + items | purchase orders + lines | History only. `received` → closed, `cancelled` → cancelled, `draft`/`pending` → closed (or draft/sent with `--open-legacy-pos`). Stored totals are kept as recorded; mismatches are reported. **No receipts and no stock movements** (legacy receiving never moved stock, audit C-1). |
| `inventory` | nothing | Shown in the report only. Legacy figures are unusable: the negatives are likely legacy BWMS writes or untracked products (audit C-3, re-classified 2026-10-27). |
| cutover stocktake CSV | `opening_balance` movements | The only source of stock (D-7). |
| `users` | nothing | Listed in the report. Invite them from Admin > Users. |

**Base unit and packs.** Each product starts with base unit "unit" and one pack, "Unit" (= the Ordermentum sell unit, D-1). Pack quantities cannot be changed once stock exists, so set up real packs on **Products → Pack setup** (CSV: `sku, base_unit, pack_name, qty_per_pack, legacy_unit`) **before** the stocktake import. The stocktake import warns about products in the file that are still on the default pack.

**Decisions (2026-10-27)**
- All legacy POs are imported as closed history (the default). None are kept open; `--open-legacy-pos` exists but is not used.
- **No bins are created from legacy bin labels** (A-1-1 …). They are only in the report CSV (`legacy_bin`) to guide the count. A new site starts with STOCK and QUARANTINE only.
- An SKU that is still invalid after uppercasing (one contains a space) is imported **unchanged** and listed under "SKUs to fix by hand". Rename it in BWMS.

## Setup

1. Restore the dump into its own database (e.g. `bwms_legacy_prod`) and create a SELECT-only user:
   ```sql
   CREATE USER 'bwms_legacy_ro'@'localhost' IDENTIFIED BY '…';
   GRANT SELECT ON bwms_legacy_prod.* TO 'bwms_legacy_ro'@'localhost';
   ```
   The command refuses a user with write grants. It also sets its session to read-only.
2. Set `LEGACY_DB_HOST`, `LEGACY_DB_DATABASE`, `LEGACY_DB_USERNAME` and `LEGACY_DB_PASSWORD` in `.env`.
3. `php artisan migrate --force && php artisan db:seed --force` creates the site, bins and reason codes.

## Run

```
php artisan bwms:migrate-legacy --dry-run                     # everything, then roll back
php artisan bwms:migrate-legacy --commit
php artisan bwms:migrate-legacy --commit --opening-balances=stocktake.csv --cutover="2026-11-30 18:00"
```

| Option | Effect |
|---|---|
| `--open-legacy-pos` | Draft/pending legacy POs stay open (draft/sent) so they can be received against. |
| `--with-ordermentum` | Adds Ordermentum's current `available` per variant to the report. One read-only GET per variant; needs `ORDERMENTUM_API_KEY`. |
| `--portal=<connection>` | Adds the portal's `stock_qty` per SKU (configure the `portal` connection with a read-only user). |
| `--site=CODE` | Site for POs and opening balances (default: the configured default site). |

Each run writes `storage/app/migration/legacy-<mode>-<timestamp>.md` and `.csv`. These contain business data, so keep them out of git (the folder is ignored). The report covers:
- row counts (source, created now, already imported, skipped)
- conflicts
- products without Ordermentum mapping
- SKUs changed or needing a fix
- PO total mismatches
- legacy draft/pending POs
- legacy users

The CSV gives per product: legacy on-hand, legacy bin label, opening balance, Ordermentum and portal figures, and flags.

**Re-runs** are safe. Rows with a legacy ID are skipped, and nothing already imported is updated. A target row with the same SKU/code but no legacy ID is reported as a conflict and left alone (its dependent POs are skipped). An opening balance row that was already posted is skipped. If its quantity changed, the run fails and is rolled back: correct it with a stocktake in the app instead.

## Stocktake CSV

Include a header row. Use one row per product × bin × lot. `qty` is in base units.

```
sku,qty,bin,site,lot,best_before
DASH-VAN,30,STOCK,,,
DASH-CAR,12,QUARANTINE,,,
SYRUP-X,48,STOCK,,L123,2027-03-31
```

- `bin` defaults to `STOCK` and `site` to the default site.
- `lot` and `best_before` are required for batch-tracked products.
- Rows with qty 0 are accepted and post nothing.
- If any row is invalid, nothing is imported.

## Cutover order

1. `--dry-run` and review the report (conflicts, SKUs to fix).
2. `--commit` without opening balances. Rename the flagged SKU by hand.
3. **Pack setup:** download the template from Products → Pack setup, set base units and packs, then preview and apply. Do this before any stock exists.
4. Count the warehouse. Fill in the stocktake CSV in base units (the report CSV's `legacy_bin` column can guide the count).
5. `--commit --opening-balances=… --cutover=…` and check the default-pack warning.
6. Run `php artisan stock:verify`, then switch on the Ordermentum listings (docs/ORDERMENTUM.md).
