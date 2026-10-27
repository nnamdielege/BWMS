<!-- markdownlint-disable MD013 -->
# Run manifest API for TSP App (spec; build after the pick & pack MVP)

TSP App is the route-optimisation and driver-management app: Laravel, with a React Native driver app on its `/api`. It **pulls** run manifests from BWMS. A run = delivery date + delivery area (`docs/PICK-PACK.md`). BWMS doesn't sequence stops; TSP optimises the route.

## Transport and auth

- Server-to-server HTTPS. On the same VPS, use a private hostname.
- **Signed like the portal API** (spec B4.4):
  - headers `X-BWMS-Key-Id`, `X-BWMS-Timestamp` (unix seconds) and `X-BWMS-Signature` = hex HMAC-SHA256 of `timestamp + "." + raw body` (an empty body for GET)
  - 300 seconds of clock skew is allowed
  - two keys can be active at once (`TSP_API_KEYS`)
- **Read-only.** Every response is cacheable. Calls are throttled to 120 per minute.
- **Versioned:** `/api/tsp/v1`. Fields are only ever added within v1.

## Endpoints

### `GET /api/tsp/v1/runs?date=YYYY-MM-DD[&area=CODE][&updated_since=ISO8601]`

`date` defaults to today. Response:

```json
[
  {
    "run_id": 812,
    "date": "2026-11-03",
    "area": { "code": "ZONE1", "name": "Zone 1 · Peninsula" },
    "site": { "code": "CLONTARF", "name": "Clontarf Warehouse" },
    "status": "open",
    "orders": 14,
    "packed_orders": 9,
    "cartons": 37,
    "chilled_orders": 4,
    "updated_at": "2026-11-02T22:41:10Z"
  }
]
```

`status` is one of `open` or `dispatched`. Use `updated_since` to poll for changes (a carton count changed, an order was added or removed).

### `GET /api/tsp/v1/runs/{run_id}`

```json
{
  "run_id": 812,
  "date": "2026-11-03",
  "area": { "code": "ZONE1", "name": "Zone 1 · Peninsula" },
  "status": "open",
  "dispatched_at": null,
  "stops": [
    {
      "order_id": 5531,
      "order_number": "SO-2026-00412",
      "channel": "ordermentum",
      "external_ref": "2d5c0e82-…",
      "status": "packed",
      "customer": { "id": 77, "name": "Cafe One", "contact": "Cathy", "phone": "07 2222 2222" },
      "address": { "street": "12 Oxley Ave", "suburb": "Woody Point", "postcode": "4019", "lat": -27.26, "lng": 153.10 },
      "instructions": "Rear door",
      "cartons": 3,
      "chilled": true,
      "packed_at": "2026-11-02T21:05:00Z",
      "items": [{ "sku": "MILK-1", "name": "Milk 2L", "packed": "2 Carton", "chilled": true }]
    }
  ],
  "totals": { "orders": 14, "cartons": 37, "chilled_orders": 4 }
}
```

**Per-stop `status`:**
- `waiting`: not yet on a pick list
- `picking`
- `packed`
- `dispatched`
- `short`: dispatched with shortfalls

**Address:** the order's own delivery address if it has one (Ordermentum and portal orders), otherwise the customer's. `lat`/`lng` come from Ordermentum when present, otherwise `null`; TSP geocodes.

**Items:** packed quantities in sell packs. Shown to drivers for checking only, never for billing.

**Errors:**
- 401 for a missing or wrong signature
- 404 for an unknown run
- 422 for a bad date

## Later (not in v1)

- **Proof of delivery:** `POST /api/tsp/v1/stops/{order_id}/delivered` `{delivered_at, received_by, photo_url?, exceptions:[{sku, qty, reason}]}`, idempotent per stop. It would close the loop for short or refused deliveries (portal credit notes, Ordermentum adjustments).
- **Dispatch push:** BWMS notifies TSP on dispatch (`run.dispatched`), signed. Until then TSP polls `updated_since`.
- **Driver handhelds:** drivers will use the same Android handhelds (keyboard-wedge scanners). The BWMS `ScanInput` component is designed to be reused for carton scanning at load-out and delivery.

## Build notes (BWMS side)

- A stateless route file signed with the same middleware pattern as `routes/portal-api.php` (separate key set).
- Read models built from `delivery_runs`, `orders`, `order_packs` and `pick_list_lines`.
- No writes in v1, so no idempotency store is needed.
