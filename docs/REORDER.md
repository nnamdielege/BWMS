# Reorder suggestions

**Purchasing → Reorder suggestions** recommends what to order, per supplier. It only suggests: **Create draft PO** makes a draft on the PO page, and nothing is sent until someone sends it there.

## Set-up

- **Supplier:** lead time (days), **order days** (e.g. Mon/Thu) or a **review period** (days), **minimum order** ($ ex GST, at cost) and **MOQ** per line (in supplier packs).
- **Product:** **preferred supplier** (without one, the supplier of its last PO is used), **lead-time override**, and **supplier pack / order multiple** (base units; without one, the default purchase pack).
- **Settings** (on the screen, purchase-order managers): safety stock as days of demand (default 3) or z × std-dev × √lead time; the lead time and review period used when a supplier has none (7 days each); the **7am email** to managers and admins (default off).

Only active, stock-tracked products sold on a channel are included.

## The working (hover a line to see it)

- **Demand** per day: a weighted average of the last 8 weeks to yesterday (Brisbane). The latest week counts 8, the oldest 1. Days that began with nothing on hand are left out, where the ledger goes back that far.
- **Sales history** is BWMS dispatches (`bwms`) plus imported history (`ordermentum_history`; `portal_history` later, by CSV). Each line shows how much came from each.
- **Cover days** = lead time + days to the next order (next order day, else the review period).
- **Need** = demand × cover days + safety stock − (available + on open POs). Open POs include drafts, so a draft made here takes the suggestion off.
- **Suggest** = need rounded **up** to the supplier pack, at least the MOQ; 0 when the need is 0 or less.
- Also shown: days of stock left, the date it runs out (red when before the next delivery could arrive), last 4 weeks sold with a trend arrow, and on order.

The dashboard tile counts products that run out before their next delivery.

## Importing Ordermentum history

```
php artisan channels:ordermentum-history --dry-run   # read and count; writes nothing
php artisan channels:ordermentum-history             # [--months=12]
```

- **Read-only on Ordermentum.** It only writes `sales_history`: never orders, reservations or inbox entries.
- **Dates:** orders delivered from 12 months ago up to the day before go-live (`ORDERMENTUM_INGEST_SINCE`), so nothing BWMS dispatched itself is counted twice.
- **Statuses:** only those in `ORDERMENTUM_HISTORY_STATUSES` count. The default is `paid,invoiced,delivered,complete,completed`; Ordermentum calls finished orders "Paid". Cancelled orders never count.
- **Re-running:** one row per order line, so a re-run updates in place, and an order cancelled since is taken out.
- **Unmapped variants:** lines for variants not mapped to a product are listed and skipped. Map them on Sync, then run it again.
