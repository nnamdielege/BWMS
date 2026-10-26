# Task: switch the portal's Ordermentum client to API-token auth

**Repo:** `localSelection` (shop.localselection.com.au). This is a standalone task for whoever maintains the portal. BWMS does not change the portal repo.
**Deadline:** before **1 January 2027**, when Ordermentum stops accepting the username/password login (spec risk R-1).
**Applies** for as long as the portal still calls Ordermentum. At BWMS go-live the portal's Ordermentum sync is retired (D-6). If go-live happens before 1 Jan 2027 this task becomes moot, but do it anyway in case go-live slips.

## Background

On 2026-10-27, Ordermentum (Michael, support) confirmed that authentication is by **API token**, sent as the `x-api-key` header. The token is created in the Ordermentum supplier platform under *Settings → Integrations*. The same token works for the core API and the stock API.

Today the portal does a `password` grant against `{base_url}/auth`, then stores a 24h bearer token in `ordermentum_connections` and sends it as `Authorization: Bearer …`.

## Change

Files are in `localSelection`; line numbers are as of 2026-10-27.

1. **`config/services.php`** (`ordermentum`, ~line 59)
   - Add `'api_key' => env('ORDERMENTUM_API_KEY')`.
   - Remove `username` and `password`.
2. **`app/Services/Ordermentum/OrdermentumClient.php`**
   - Delete `getValidConnection()` and `authenticate()`, and the `OrdermentumConnection` import.
   - Change `request()` to:
     ```php
     return Http::withHeaders(['x-api-key' => $this->apiKey()])
         ->timeout(15)->connectTimeout(5)
         ->baseUrl(config('services.ordermentum.base_url'));
     ```
   - Change `stockRequest()` the same way, keeping its `context-entity` / `context-id` headers and `stock_base_url`.
   - Add `apiKey()`. It should throw a `RuntimeException('ORDERMENTUM_API_KEY is not set.')` when the key is empty.
   - Update the class docblock, which currently says the account uses username/password and that x-api-key was wrong. Ordermentum has now confirmed the token.
3. **`app/Services/Ordermentum/OrdermentumStatusService.php`** (~line 17)
   - Set `credentials_set` from `api_key`.
   - Drop `token_valid` and `token_expires_at`, and the `OrdermentumConnection` query.
   - Remove the matching fields from the admin status page.
4. **`.env` on the server:** set `ORDERMENTUM_API_KEY` and delete `ORDERMENTUM_USERNAME` and `ORDERMENTUM_PASSWORD`. Run `php artisan config:cache`.
5. **Optional cleanup, in a follow-up release:** drop the `ordermentum_connections` table (migration `2026_09_05_100000_create_ordermentum_connections_table.php`) and the `OrdermentumConnection` model. The table holds old bearer tokens: truncate it after the switch.

Callers don't change. They all go through `request()` or `stockRequest()`:
- `OrdermentumMatchingService:197`
- `OrdermentumStockEventService:153`
- `OrdermentumStockPullService:190`
- `OrdermentumStockService:51`

## Rules

- **Never log the token.**
  - Don't log request headers.
  - Don't include the token in exception messages.
  - Don't add it to the admin status payload. A boolean "set" is enough.
- Keep `ORDERMENTUM_SYNC_MODE` as it is (`log_only` today). This task changes authentication only, not sync behaviour.

## Tests

- **`tests/Feature/Ordermentum/*`:**
  - Remove the fakes for the `/auth` call.
  - Assert that requests carry `x-api-key` and no `Authorization` header.
  - Add a test that a missing key fails clearly.
- **Manual check after deploy:** open the admin Ordermentum status page and confirm credentials show as set. Then run one read: the admin stock-pull preview (`OrdermentumStockPullController`, which queues the `RunOrdermentumStockPullPreview` job) only GETs items.
