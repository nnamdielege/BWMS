# Local end-to-end testing

How to test order flows on your own PC with real catalogue data. Nothing here touches the VPS, and **nothing writes to Ordermentum**.

- [0. Set up once](#0-set-up-once)
- [Every session](#every-session)
- [Reset](#reset)
- [Handheld over your Wi-Fi](#handheld-over-your-wi-fi)
- Flows: [A. Manual order to dispatch](#a-manual-order--pick--pack--dispatch) · [B. Real Ordermentum orders](#b-real-ordermentum-orders-via-the-poll) · [C. Ordermentum webhooks](#c-ordermentum-webhooks-simulated) · [D. Portal](#d-portal-simulated) · [E. PO receiving](#e-po-receive--stock-up) · [F. Stocktake](#f-stocktake-with-variance)

## Safety

| Guard | What it does |
|---|---|
| `ORDERMENTUM_READ_ONLY=true` | The Ordermentum client refuses everything except GET: no stock push, no `om:probe write-test`, whatever other switches say. |
| `ORDERMENTUM_PUSH_ENABLED=false` | Stock pushes don't even start. |
| Ingestion | Only reads (GET order, GET order list). Checked by `LocalToolkitTest`: ingesting with push off sends one GET and queues no push. |
| `bwms:dev-reset` | Only with `APP_ENV=local`, on a database listed in `BWMS_DEV_RESET_DATABASES` (default `bwms_next`), on this machine. Your `bwms_test`, `bwms_legacy_prod` and any VPS database are never touched. |
| Portal | BWMS only calls `PORTAL_BASE_URL`. Point it at the local receiver (`127.0.0.1:8099`), never at the real portal. |
| Email | `MAIL_MAILER=log`: PO emails and invitations go to `storage/logs/laravel.log`, not to anyone. |

Real Ordermentum orders contain real café names and addresses. They stay in your local database; don't share screenshots of them.

## 0. Set up once

1. **`.env`** (local only; `.env` is never committed). Generate the random values with `php -r "echo bin2hex(random_bytes(24));"`.

   ```ini
   APP_ENV=local
   APP_URL=http://127.0.0.1:8001
   DB_DATABASE=bwms_next
   MAIL_MAILER=log
   QUEUE_CONNECTION=database

   # Ordermentum: read real orders, never write
   ORDERMENTUM_READ_ONLY=true
   ORDERMENTUM_PUSH_ENABLED=false
   ORDERMENTUM_INGEST_ENABLED=true
   ORDERMENTUM_LOCAL_FIXTURES=true
   ORDERMENTUM_WEBHOOK_TOKEN=<random>
   # ORDERMENTUM_WEBHOOK_SECRET is already set (om:probe secret --write-env); any value works locally.

   # Portal: inbound API on, outbound only to the local receiver
   PORTAL_API_ENABLED=true
   PORTAL_API_KEYS=local:<random>
   PORTAL_BASE_URL=http://127.0.0.1:8099
   PORTAL_OUTBOUND_KEY_ID=bwms-local
   PORTAL_OUTBOUND_SECRET=<random>
   PORTAL_PUSH_ENABLED=true
   PORTAL_EVENTS_ENABLED=true
   ```

   Then run `php artisan config:clear`. Don't run `config:cache` locally: it freezes `.env`.

2. **Data:** `php artisan bwms:dev-reset` ([Reset](#reset)).
3. **Admin:** the reset keeps your existing users. If it says "No admin yet", run `php artisan bwms:invite-admin you@example.com "Your Name"` and open the printed link.
4. **Assets:** `npm run build`. Without it, `npm run dev` must be running, and a phone can't reach that.

## Every session

Open three terminals in `C:\xampp\htdocs\bwms`:

```bash
php artisan serve --host=0.0.0.0 --port=8001     # the app (the LAN host is for the handheld)
php artisan queue:work                           # processes webhooks, polls, pushes and emails
PORTAL_RECEIVER_SECRET=<PORTAL_OUTBOUND_SECRET> php -S 127.0.0.1:8099 docs/testing/portal-receiver.php   # flow D only
```

On Windows PowerShell, set the variable for the receiver first: `$env:PORTAL_RECEIVER_SECRET='<PORTAL_OUTBOUND_SECRET>'; php -S 127.0.0.1:8099 docs/testing/portal-receiver.php`.

Open http://127.0.0.1:8001 on the PC.

## Reset

```bash
php artisan bwms:dev-reset          # asks first; --force skips the question
```

It takes about a minute and:

1. Rebuilds `bwms_next` from the migrations and seeders.
2. Keeps your user accounts (passwords, 2FA, roles). The first time, it also adds `manager@local.test` (warehouse manager) and `picker@local.test` (warehouse staff), and prints their passwords once.
3. Imports the real products, suppliers, customers and PO history from your local `bwms_legacy_prod` (read-only).
4. Sets default packs: when a product name states a case size ("x 12", "12x260ml", "(500ml X 24)"), base unit "each" plus a "Case ×N" pack, which is what the old "Unit" was. Other products keep their single "Unit" pack.
5. Marks kombucha, juice and smoothie products as chilled and batch-tracked, and milks as batch-tracked. These are test assumptions, not real product settings.
6. Adds pick bins A-01 to A-04 (walk order 10–40) and COOL-01 (50) at Clontarf.
7. Adds a portal listing for every stock-tracked product, with portal product ID = 10000 + the BWMS product ID, sync off.
8. Posts a fake opening stocktake:
   - 20 of each product's sale pack, spread over the A bins, with chilled products in COOL-01;
   - the first three batch-tracked products have best-before dates 1, 2 and 3 days away, the rest 60 days;
   - one product is at zero.
9. Clears the simulated Ordermentum orders, and checks the balances against the ledger.

**Every flow below ends with "Reset": run `php artisan bwms:dev-reset --force`.**

`--packs=50` posts 50 packs of each product instead of 20. `--skip-legacy` gives empty catalogue tables (migrations and seeders only).

## Handheld over your Wi-Fi

1. Find the PC's address: `ipconfig` → "IPv4 Address" of the Wi-Fi adapter, for example `192.168.1.23`.
2. Check the Wi-Fi network is **Private**: `Get-NetConnectionProfile` (PowerShell). If it shows Public, change it in Settings → Network & internet → Wi-Fi → (network) → Private.
3. Allow the port once (PowerShell **as administrator**):

   ```powershell
   New-NetFirewallRule -DisplayName "BWMS dev 8001" -Direction Inbound -Protocol TCP -LocalPort 8001 -Action Allow -Profile Private
   ```

   Remove it later with `Remove-NetFirewallRule -DisplayName "BWMS dev 8001"`.
4. Run the app with `php artisan serve --host=0.0.0.0 --port=8001` (see [Every session](#every-session)), after `npm run build`. Delete `public/hot` if it exists; it is left behind by `npm run dev`.
5. On the handheld, open `http://192.168.1.23:8001` and log in as `picker@local.test`.

What to test on the LAN:

- **Keyboard-wedge scanning** (the built-in scanner): set it to keyboard mode with an Enter suffix. Scan the bin labels and product barcodes.
  - Print the labels from **Locations → Clontarf → Bin labels**. An imaging scanner can also read them off the PC screen.
  - Product barcodes are the real ones imported from the legacy data, on the Case pack.
  - Products without a barcode: use **Enter quantity**.
- **Not on the LAN:** camera scanning. The browser only allows the camera over HTTPS, so the 📷 button doesn't appear on `http://`. Test it on the VPS (HTTPS) later.

---

## A. Manual order → pick → pack → dispatch

As `manager@local.test` on the PC, then `picker@local.test` on the handheld.

| # | Do | Expect |
|---|---|---|
| 1 | Note one product's figures on **Stock on hand** (search its SKU), for example a cold brew with a Case ×15. | On hand, Sellable, Reserved 0, Available. |
| 2 | **Orders → New order**: a customer whose postcode is in a delivery area, **If short at picking = Backorder the rest**, 2 cases of that product plus 1 of another. **Create order & reserve stock**. | Order **Open**, with a run "‹date› · ‹area›". On **Stock on hand**, Reserved is up and Available down by 2 cases' worth of units. |
| 3 | Handheld: **Pick & pack** → that run → **Pick 1 new order(s)**. | The pick list opens on the first line, in walk order (chilled lines last). |
| 4 | Scan a **wrong** bin label. | Red message "That is bin …; this line is in …", double buzz. |
| 5 | Scan the right bin, then the product barcode once per case. | "Bin ✓", then the count goes up; the line confirms itself when it matches. |
| 6 | On the second line, tap **Short / not found** and enter fewer than asked. | Next line, or "All lines picked". **Dashboard → Bins to count** lists that bin. |
| 7 | **Go to packing**: tick every line, Cartons = 1, **Packed**. Tap **Packing slip**. | Green "Packed: 1 carton(s) by …"; ticks stay on. An A5 PDF opens. |
| 8 | **To dispatch** → **Dispatch run** → OK. | The run's manifest shows the order **Partially dispatched**. |
| 9 | Check on the PC. | **Stock on hand**: On hand down by what was picked. Reserved now only the short quantity (backorder); Available accordingly. **Movement history**: "Sale" rows for the order. Order page: Dispatched and Reserved per line. |
| 10 | `php artisan stock:verify` | "Stock balances match the ledger." |

Variations:

- **Cancel the rest:** set **If short at picking = Cancel the rest** in step 2. After dispatch the order is **Dispatched**, with the short quantity under Cancelled and nothing left reserved.
- **No run:** a customer with postcode `4870`. The order shows under **Orders without a run** in the pick queue.

**Reset.**

## B. Real Ordermentum orders via the poll

Needs `ORDERMENTUM_INGEST_ENABLED=true`, `ORDERMENTUM_PUSH_ENABLED=false`, `ORDERMENTUM_READ_ONLY=true`, and the queue worker running.

| # | Do | Expect |
|---|---|---|
| 1 | `php artisan channels:poll-ordermentum --since="3 days ago"` | "Queued N order(s)." (GET requests only.) The worker then processes them. |
| 2 | **Sync → Inbound events** | One "poll" row per order, status **processed**. Orders with an unmapped item are **needs_mapping**. |
| 3 | **Orders**, channel Ordermentum | The real orders. The order page shows the Ordermentum order ID next to the channel. Each has a run if its address matches an area. |
| 4 | Open one | Lines show the BWMS product and pack, and **Reserved** = ordered (in base units). Oversold orders carry the red banner: the fake stock is only 20 packs. |
| 5 | **Stock on hand** for one of its products | Reserved up, **Available** down by the same amount. |
| 6 | **Sync → Channel items needing mapping** | Items no listing knows. **Map** one (confirm). Its held orders are processed. |
| 7 | Run the poll again | "Queued 0 order(s)" for orders that haven't changed since. |

`php artisan schedule:work` would also run the poll every 10 minutes. With the switches above it still only reads.

**Reset.**

## C. Ordermentum webhooks (simulated)

`om:simulate-webhook` builds the webhook body, signs it like Ordermentum (HMAC-SHA256 of the raw body with `ORDERMENTUM_WEBHOOK_SECRET`, in `x-ordermentum-signature`), and POSTs it to `APP_URL/webhooks/ordermentum/<token>`. It then runs the queue and shows the inbox status, the order and its reservations.

The order itself is a local copy (`storage/app/private/ordermentum-fixtures/`), and the app uses it instead of fetching from Ordermentum. Edits and cancellations change only that copy.

```bash
# a real order: fetched once from Ordermentum with a GET, then kept locally
php artisan om:simulate-webhook <ordermentum-order-id>
# or a made-up one: any ID, real SKUs that have an Ordermentum listing
php artisan om:simulate-webhook SIM-1001 --lines=283939:2,MLKOAT:1 --customer=<customer code>
```

| # | Do | Expect |
|---|---|---|
| 1 | `php artisan om:simulate-webhook SIM-1001 --lines=<SKU>:2` | HTTP 200. Inbox **processed** (signature `valid_hex`). The order is **Open** with Reserved = 2 cases. |
| 2 | `… SIM-1001 --event=updated --lines=<SKU>:3` (for a real order, without `--lines`, the first line goes up by 1) | Same order; Reserved = 3 cases. Available drops by one more case. |
| 3 | `… SIM-1001 --event=cancelled` | Order **Cancelled**; Reserved 0; Cancelled = 3 cases. Available is back. |
| 4 | `… SIM-1001 --bad-signature` | HTTP **401** `invalid_signature`; nothing stored ("Rejected as expected"). |
| 5 | `… SIM-1002 --lines=<SKU>:1 --unmapped` | Inbox **needs_mapping**; no order. **Sync** lists the simulated item. Mapping it processes the order. |
| 6 | **Sync → Inbound events → Replay** on a processed event (confirm) | Processed again; nothing changes, because the version is the same. |

Without `--customer`, a fake order uses the first customer that has an Ordermentum retailer ID and a delivery postcode.

**Reset** (it also clears the local order copies).

## D. Portal (simulated)

`portal:simulate` signs requests with the first key in `PORTAL_API_KEYS` (headers `X-BWMS-Key-Id`, `X-BWMS-Timestamp` and `X-BWMS-Signature`, HMAC-SHA256 of `timestamp.body`), sends them to `APP_URL/api/portal/v1`, and prints each response.

Quantities are portal packs. Without `--lines`, it picks the first portal listing with at least 2 packs available.

| # | Do | Expect |
|---|---|---|
| 1 | `php artisan portal:simulate reserve --order=SIM-P1 --retry` | **201** `confirmed` with `reserved_qty`. The second send has the same Idempotency-Key: **201** again with `Idempotent-Replay: true`, and only one order. |
| 2 | `… reserve --order=SIM-P2 --scenario=short` | **409** `insufficient_stock` with `requested` and `available` per line. Nothing reserved; no order. |
| 3 | `… reserve --order=SIM-P3 --scenario=unknown` | **422** `unknown_product`. **Sync → Channel items needing mapping** lists portal product 999999. |
| 4 | `… update --order=SIM-P1 --lines=<portal id>:3` | **200**; `reserved_qty` 3 packs. Available drops. |
| 5 | `… update --order=SIM-P1 --stale` | **200**, unchanged: version 1 is older than what BWMS has. |
| 6 | `… cancel --order=SIM-P1 --order-version=3` | **200** `cancelled`. Reservation released; Available back. |
| 7 | `… status --order=SIM-P1` | **200** with the order's status and per-line reserved/dispatched. |
| 8 | `… status --order=SIM-P1 --bad-signature` | **401** `bad_signature`. |

`reservation_status` in BWMS's answers is always `confirmed`: it means BWMS accepted the reservation. The portal's own `unconfirmed` and `reservation_failed` states live on the portal side.

**Timeout fallback** (what the portal does when BWMS can't answer):

1. Stop `php artisan serve`, or set `PORTAL_API_ENABLED=false` and run `config:clear`.
2. Run `… reserve --order=SIM-P4 --key=retry-test-1`. Expect a connection error, or **503** `portal_api_disabled`. The portal would take the order as "unconfirmed" and keep the request in its outbox.
3. Start BWMS again (or switch the API back on) and send **the same command with the same `--key`**. Expect **201**: that's the portal's outbox replay.

**Short shipment notice** (BWMS → portal, seen in the receiver window):

1. `… reserve --order=SIM-P5 --lines=<portal id>:2`. The simulated café is in Clontarf 4019, so the order lands on a Zone 1 run.
2. On the handheld, pick that run. Short-pick the line (1 of 2 packs), pack, dispatch.
3. The receiver prints `POST /api/bwms/v1/order-events` with `"event": "short_shipped"` and the line quantities, signature **valid**. With a full pick it says `"dispatched"`.
4. After **Sync → Enable all portal listings** (confirm), any stock change also shows a `PUT /api/bwms/v1/stock` in the receiver.

**Reset.**

## E. PO receive → stock up

| # | Do | Expect |
|---|---|---|
| 1 | Note a product's On hand, Sellable and Available. | |
| 2 | **Purchase orders → New purchase order**: supplier, the product, 2 cases, a cost. **Create draft** → **Mark as sent**. (**Email supplier** only writes to `laravel.log`.) | PO **Sent**. |
| 3 | **Receive** → **Fill all outstanding**. For a batch-tracked product, enter a lot and best-before. Put 1 unit under **Damaged**. **Post receipt**. | PO **Received**. The receipt is listed with the docket and who received it. |
| 4 | **Stock on hand** for the product | On hand up by everything received; Sellable and **Available** up by the accepted units; the damaged unit sits in QUARANTINE (on hand, not sellable). |
| 5 | **Movement history** | "Receipt" rows, plus one into QUARANTINE. |
| 6 | **Product costs** | The cost per base unit has moved toward the PO cost (weighted average). |

Also try receiving more than ordered as `picker@local.test`: the receipt is refused until a manager ticks **Accept the over-receipt**.

**Reset.**

## F. Stocktake with variance

| # | Do | Expect |
|---|---|---|
| 1 | **Stocktakes → Count what: A-01 → Start stocktake** (manager). | Lines for every product and batch in A-01, with the Snapshot quantity. |
| 2 | As `picker@local.test`, enter counts: one line 2 lower, one line 1 higher, the rest as shown. | Variance −2 (red) and +1 (green). |
| 3 | As manager, **Review done — post variances** (confirm; uncounted lines are posted as zero if you say so). | Status **posted**. |
| 4 | **Stock on hand** and **Movement history** (type Stocktake variance) | On hand −2 and +1 on those products. |
| 5 | If A-01 had a count task (for example from a short pick there before posting) | It's gone from the dashboard: posting a stocktake that covers the bin closes it. |
| 6 | `php artisan stock:verify` | Balances match the ledger. |

**Reset.**
