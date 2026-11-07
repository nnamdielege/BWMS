# Pick & pack (MVP)

Guided picking and packing for the warehouse, on cheap **Android handhelds with built-in scanners in keyboard-wedge mode**, with the phone camera as a fallback. Open it from the sidebar under **Pick & pack** (`/pick`). It needs the `picking.perform` permission.

## Delivery runs

- **A run = a delivery date + a delivery area** (from one site).
- **Areas and weekdays** (seeded, then edited in **Settings → Delivery areas**):

| Area | Weekday | Next-day |
|---|---|---|
| North of Brisbane | Mon | |
| Zone 1 · Peninsula | Mon | ✓ any weekday |
| Zone 2 · Moreton Bay inner | Mon | ✓ |
| Zone 3 · Moreton Bay outer | Mon | ✓ |
| Gold Coast | Tue | |
| Sunshine Coast | Wed | |
| Ipswich & western suburbs | Thu | |
| CBD, Valley, West End, south, Redlands, Wynnum, Carindale | Fri | |

- **The postcode lists are a starter set.** Review them in Settings → Delivery areas, one entry per line: `4019` (the whole postcode) or `4019 Clontarf` (one suburb, for postcodes split across areas). A suburb entry beats a whole-postcode entry.
- **Cutoff:** 1:00 pm (`BWMS_DELIVERY_CUTOFF`) on the **business day before** delivery. Business days are Mon–Fri. Public holidays aren't modelled yet.
- **Every order has one `delivery_date`:**
  1. **From its source:** Ordermentum `deliveryDate` (`ORDERMENTUM_DELIVERY_DATE_FIELDS`), the portal's `requested_delivery_date`, or the date on the manual order form, which is required and defaults to the customer's next delivery day. A timestamp takes its Brisbane date.
  2. **Fallback:** no date, or one that can't be read, takes the **next eligible run after the cutoff** and flags the order "No delivery date from <source>".
  3. `requested_delivery_date` keeps what the source last sent. `delivery_date_origin` says where the effective date came from: `source`, `fallback` or `manager`.
- **Run = `delivery_date` + area.** The area is the customer's override, otherwise the postcode/suburb of the order's delivery address, otherwise the customer's. An address that matches no area leaves the order **unassigned**, listed at the bottom of the pick queue: set the run on the order page ("Change"), or add the postcode in Settings → Delivery areas.
- **A date the area doesn't run** stays, and is flagged "Not a delivery day for <area>". It is never moved.
- **Moves:**
  - Only a *change* in the source's date moves an order, so re-sending an unchanged order leaves a manager's date.
  - Not yet on a pick list: moves to the new run.
  - On a pick list (open or dispatched), or on a run set by hand: stays, flagged with both dates. A cancelled pick list doesn't count.
  - A customer's new address or area moves their waiting orders to the right run on the same date.
- **Managers** change the date on the order page, under the same rules. Both outcomes go in the audit log.
- **Review reasons** are kept per order (`review_notes`). One that stops being true clears itself. One marked handled isn't raised again unless it changes.
- **Pick lists never mix days.**
- **Payment hold.** A portal order that arrives `awaiting_payment` reserves stock but stays out of "Pick N new orders" and can't go on a desktop pick list, with a HELD badge on the order and run. The portal's `paid` lifts it, its cancel releases it, or a manager uses **Release to pick** (audited). A hold is only set when the order is created, so a re-send can't undo a manager's release.
- **Consolidation** (Orders → Consolidate) moves orders not yet on a pick list onto an earlier day, keeping their area: `delivery_date_origin = manager`, `delivery_date_reason = consolidated`, with the original date and origin kept for Undo. These orders aren't flagged "Not a delivery day". The same source date re-sent changes nothing. A different one flags all three dates and moves nothing. A manager's later date change or hand-set run on another day ends the consolidation. `orders:backfill-delivery-dates` leaves consolidated orders alone.
- **Trips** (`delivery_runs.trip_label`) group runs on the driver's manifest (`/pick/trips/{date}`) only. Dispatch is still per run.- **Backfill:** `php artisan orders:backfill-delivery-dates --dry-run` shows open orders not yet on a pick list, with their current and source dates and what would move. Ordermentum dates are read again from the API (GET only). `--yes` applies the moves and audits them. Run it once after deploying.

## Setup before go-live

1. **Bin labels:**
   - Every bin gets a label code (`B000042`, Code 128) automatically.
   - Print them from **Locations → (site) → Bin labels**: an A4 PDF in walk order, 90 × 38 mm labels.
   - Stick each one on its bin.
2. **Walk order:**
   - Set **Walk order** on each bin (Locations → Edit) to the order a picker walks the warehouse.
   - Chilled lines are always picked **last**, whatever their bin, because there's no refrigerated transport.
3. **Chilled products:** tick **Chilled** on milks, juices and kombucha (product form).
4. **Product barcodes:** add the pack barcodes (each and carton) on the product form. A pack barcode scan counts that pack's quantity.
5. **Handhelds:**
   - Set the scanner to keyboard-wedge mode with an **Enter** suffix and no prefix.
   - Open BWMS over **HTTPS**, which the camera fallback needs, and add it to the home screen.
6. **Customers:** check the delivery postcode and suburb on each customer. Legacy customers were imported with their postcode and city.

## Flow

**Moving around:** every `/pick` screen has a one-row bar: Back goes up one level (pack → pick list → queue → dashboard, manifest → queue), and the menu links to the Dashboard, Pick queue, Pick lists and Orders, as the user's permissions allow, plus Log out. Leaving with unpicked lines on your own list asks first. Leaving never releases the claim; the list stays yours until someone takes it over. The Android/browser Back button goes straight back without asking; picked lines and pack ticks are already saved, so nothing is lost.

1. **Pick queue:**
   - Grouped by delivery day: OVERDUE in red (before today in Brisbane, only runs with active orders), Today, Tomorrow, then each later day. Within a day, runs in their existing order.
   - Each run lists its orders with a source badge, plus its open pick lists.
   - **"Pick N new orders"** creates a pick list for the run's orders that are waiting, claimed by you.
   - **Continue** resumes your list. **Take over** reassigns another picker's list, and is audited.
2. **Picking**, one line at a time. **Company settings → Require bin scan when picking** is on by default. With it off, the product scan alone confirms a line, and the bin still shows in large letters. A bin scanned anyway is still checked.
   - The screen shows the **bin** in large type, the product (with a **CHILLED** badge), lot and best-before (earliest expiry first), and the quantity in packs.
   - **Scan the bin label, then the product.** Each product scan adds that pack's quantity, and the line confirms itself when the count matches.
   - Both scans are **checked by the server** against the bin's label code and the product's barcodes.
   - A wrong bin or wrong product shows a red message and a double buzz.
   - **Enter quantity** (no scan) needs a reason: label missing, label damaged, scanner not working, or other. It's audited.
   - **Short / not found:** enter what you found. The rest follows the order's backorder/cancel rule at dispatch, and the bin gets a **count task** on the dashboard.
3. **Pack**, per order:
   - Tick each line as it goes in the carton(s). Each tick saves as it's made (who and when), so you can leave and come back; untick to reverse it. Changing a line's picked quantity clears its tick.
   - Enter the **carton count**, which saves the same way, then **Packed** (records who and when). Packed is final: ticks and the carton count can't be changed afterwards.
   - **Packing slip** opens an A5 PDF to print and put in the carton.
4. **Dispatch:**
   - The **run manifest** lists every order of the run with its address, carton count and packed state.
   - **Dispatch run** needs every order on the run's open pick lists to be packed. It deducts the stock, completes the orders, notifies the portal for portal orders, and closes the run when nothing is left.

## Later

- The TSP App run manifest API (`docs/tsp-manifest-api.md`).
- Driver screens on the same handhelds.
- Public holidays in the cutoff rules.
- Batch-picking several runs at once.
