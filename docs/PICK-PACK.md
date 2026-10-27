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
- **How an order gets its run:**
  1. **Area:** the customer's area override, otherwise the postcode/suburb of the order's delivery address (Ordermentum and portal orders), otherwise the customer's.
  2. **Date:** the requested delivery date if one is given, otherwise the **next eligible run after the cutoff**. An area's weekdays, or any weekday for next-day areas.
  3. **No match:** an address that matches no area leaves the order **unassigned**. These orders are listed at the bottom of the pick queue; set the run on the order page ("Change"), or add the postcode to an area.
- **Re-assignment:**
  - Runs are re-worked when an order changes, and when a customer's address or area changes.
  - A run **set by hand** sticks.
  - An order already on a pick list keeps its run.

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

1. **Pick queue:**
   - Runs for the coming days, each with order counts and its open pick lists.
   - **"Pick N new orders"** creates a pick list for the run's orders that are waiting, claimed by you.
   - **Continue** resumes your list. **Take over** reassigns another picker's list, and is audited.
2. **Picking**, one line at a time:
   - The screen shows the **bin** in large type, the product (with a **CHILLED** badge), lot and best-before (earliest expiry first), and the quantity in packs.
   - **Scan the bin label, then the product.** Each product scan adds that pack's quantity, and the line confirms itself when the count matches.
   - Both scans are **checked by the server** against the bin's label code and the product's barcodes.
   - A wrong bin or wrong product shows a red message and a double buzz.
   - **Enter quantity** (no scan) needs a reason: label missing, label damaged, scanner not working, or other. It's audited.
   - **Short / not found:** enter what you found. The rest follows the order's backorder/cancel rule at dispatch, and the bin gets a **count task** on the dashboard.
3. **Pack**, per order:
   - Tick each line as it goes in the carton(s), enter the **carton count**, then **Packed** (records who and when).
   - **Packing slip** opens an A5 PDF to print and put in the carton.
4. **Dispatch:**
   - The **run manifest** lists every order of the run with its address, carton count and packed state.
   - **Dispatch run** needs every order on the run's open pick lists to be packed. It deducts the stock, completes the orders, notifies the portal for portal orders, and closes the run when nothing is left.

## Later

- The TSP App run manifest API (`docs/tsp-manifest-api.md`).
- Driver screens on the same handhelds.
- Public holidays in the cutoff rules.
- Batch-picking several runs at once.
