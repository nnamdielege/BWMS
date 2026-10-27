# Go-live runbook (cutover)

This is the step-by-step runbook for taking BWMS live at Clontarf. Each stage ends with a **check**. Don't start the next stage until it passes.

Detail lives in the linked docs:
- `DEPLOY.md`: server and deploy
- `MIGRATION.md`: legacy import and stocktake CSV
- `ORDERMENTUM.md`: Ordermentum
- `PORTAL.md`: portal, BWMS side
- `portal-bwms-integration.md`: portal team

**Roles:** an **ops lead** (runs commands), a **warehouse lead** (count, packs, test picks) and the **portal maintainer** (portal side).

**Golden rules**
- Take a database backup before every stage (§0).
- Never run `migrate:rollback`, `migrate:fresh` or `db:wipe` on the server. BWMS refuses them on any database not named `*_test`. To go back, **restore a backup**.
- All sync switches stay **off** until their stage.
- Keep the old app's files, vhost (disabled) and database **untouched**. They're only for reference.

## Timeline

| When | Stages |
|---|---|
| T−7 days or earlier | §1 VPS prep, §2 deploy, §3 legacy import and invites, §4 pack setup, Ordermentum and portal preparation (§5.1) |
| T−1 day | Final checks (§5.2) |
| **T: cutover day** (e.g. Saturday, after the last dispatch run) | §6 freeze and count → §7 opening balances → §8 Ordermentum ingestion → §9 Ordermentum push → §10 portal → §11 verification → resume work |
| T+1 / T+7 | §12 after go-live |

## 0. Backups (before every stage)

```bash
mysqldump --single-transaction --routines --triggers bwms | gzip > /var/backups/bwms/bwms-$(date +%F-%H%M)-<stage>.sql.gz
```
- **Check:** the file exists and isn't empty.
- Do one trial restore into a scratch database (`bwms_restore_test`) before T.

## 1. VPS preparation

**PHP 8.2+** (FPM)
- Extensions: `bcmath`, `mbstring`, `xml`, `curl`, `zip`, `gd`, `intl`, `pdo_mysql`, `opcache`.
- Composer 2. Node 22 only for building assets (or build elsewhere and upload `public/build`).

**MariaDB 10.5** (see `DEPLOY.md` §1)
- `log_bin_trust_function_creators = 1` in `[mysqld]`, then restart.
- Database `bwms` (utf8mb4) and user `bwms`, with schema-scoped grants **including `TRIGGER`**.
- A separate **read-only** user for the legacy copy: `GRANT SELECT ON bwms_legacy_prod.*`.

**Apache vhost + SSL**
- Enable the modules: `a2enmod proxy_fcgi setenvif rewrite headers ssl` and `a2enconf php8.2-fpm`.
- Vhost:
  ```apache
  <VirtualHost *:443>
      ServerName bwms.<domain>
      DocumentRoot /var/www/bwms/public
      <Directory /var/www/bwms/public>
          AllowOverride All
          Require all granted
      </Directory>
      <FilesMatch \.php$>
          SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"
      </FilesMatch>
      Header always set Strict-Transport-Security "max-age=31536000"
      ErrorLog ${APACHE_LOG_DIR}/bwms-error.log
      CustomLog ${APACHE_LOG_DIR}/bwms-access.log combined
  </VirtualHost>
  <VirtualHost *:80>
      ServerName bwms.<domain>
      Redirect permanent / https://bwms.<domain>/
  </VirtualHost>
  ```
- Get the certificate with `certbot --apache -d bwms.<domain>`, then `apachectl configtest && systemctl reload apache2`.
- The old app's vhost stays **disabled**.

**Processes**
- Supervisor queue worker, as in `DEPLOY.md` §3 (`--max-jobs=500 --max-time=3600`).
- Cron: `* * * * * cd /var/www/bwms && php artisan schedule:run >> /dev/null 2>&1`.
- A local caching DNS resolver (systemd-resolved or unbound) is on.
- `storage/` and `bootstrap/cache/` are writable by the PHP-FPM user.

**Private hostname for the portal:** on the same VPS, BWMS and the portal call each other over localhost or a private name (`/etc/hosts`), never public DNS.

**Check:** `php -m` lists the extensions; `curl -I https://bwms.<domain>` returns 200 or 302 with a valid certificate; `supervisorctl status` shows the worker running.

## 2. Deploy

1. Deploy the approved release to `/var/www/bwms`.
2. Write `.env` from `.env.example`:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, DB credentials, `MAIL_*`, `SESSION_SECURE_COOKIE=true`.
   - `ORDERMENTUM_API_KEY`, `ORDERMENTUM_SUPPLIER_ID`, `ORDERMENTUM_LINE_VARIANT_FIELDS=variantId`.
   - `ORDERMENTUM_LOCATION_ID` = **Clontarf Warehouse** (`fbdbaf1b-3777-462b-8a40-07ff16c9ef5b`). Don't copy the old app's value, which is an unused "Default" location.
   - `ORDERMENTUM_WEBHOOK_TOKEN`: a long random string, e.g. `openssl rand -hex 32`.
   - `LEGACY_DB_*`: the read-only user.
   - **All switches false:** `ORDERMENTUM_PUSH_ENABLED`, `ORDERMENTUM_INGEST_ENABLED`, `PORTAL_API_ENABLED`, `PORTAL_PUSH_ENABLED`, `PORTAL_EVENTS_ENABLED`.
3. Run the "every deploy" block in `DEPLOY.md` §2:
   - `composer install --no-dev`
   - `npm ci && npm run build`
   - `migrate --force`
   - `db:seed --force`
   - **`bwms:check-triggers` (must say OK)**
   - `stock:verify`
   - the caches
   - `queue:restart`
4. Run `php artisan bwms:invite-admin <email> "<name>"`. Accept the invitation and set up 2FA.

**Check:**
- You can log in with 2FA.
- Locations shows Clontarf Warehouse with **STOCK and QUARANTINE**.
- `bwms:check-triggers` and `stock:verify` both pass.
- The Sync screen shows every switch off.

## 3. Legacy data (any time before T; the legacy data is frozen)

1. Dump the legacy database (the old app has been offline since 2026-10-22). Restore it as `bwms_legacy_prod`, with the read-only user.
2. Run `php artisan bwms:migrate-legacy --dry-run` and review the report in `storage/app/migration/`:
   - conflicts
   - SKUs changed or to fix by hand
   - products without an Ordermentum mapping
3. Run `php artisan bwms:migrate-legacy --commit`.
4. Rename the SKU containing a space (flagged in the report).
5. Invite the legacy users the report lists (Admin → Users).

**Check:** the report shows 123 products, 14 suppliers, 20 customers and 60 POs (all closed history), 0 stock movements, and 120 Ordermentum listings, all with sync off.

## 4. Pack setup (before T; packs are fixed once stock exists)

1. Go to Products → Pack setup and download the template.
2. The warehouse lead sets each product's base unit and packs.
3. Upload the file, check the preview, and apply.

**Check:** the "still on the default single Unit pack" list contains only products that really are a single unit.

## 4b. Warehouse setup for pick & pack (before T)

See `PICK-PACK.md`.
1. **Delivery areas:** in Settings → Delivery areas, review the starter postcode lists against your real suburbs.
2. **Customers:** check every customer's delivery postcode and suburb. Any order without a matching area shows as "Orders without a run" in the pick queue.
3. **Bins:** set **Walk order** on each bin. Print **bin labels** (Locations → site → Bin labels) and stick them on.
4. **Products:** tick **Chilled** on milks, juices and kombucha. Add the pack barcodes.
5. **Handhelds:** set the scanners to keyboard-wedge mode with an Enter suffix, and open BWMS over HTTPS. Do a test pick of one line per handheld.

**Check:** a test order for a known customer lands on the expected run (date + area).

## 5. Preparation for Ordermentum and the portal

**5.1 (T−7 days)**
- **Ordermentum:**
  - Run `php artisan om:probe locations`: `ORDERMENTUM_LOCATION_ID` should be marked against **Clontarf Warehouse**.
  - Run `php artisan om:probe tracking`: every mapped item should be tracked.
  - Run `php artisan om:probe write-test <quiet variantId> --confirm`: every step should be `unchanged`.
  - Run `php artisan om:probe secret --write-env`, then `php artisan config:cache`.
- **Portal:**
  - The portal maintainer builds `portal-bwms-integration.md` behind a switch.
  - Exchange keys: BWMS `PORTAL_API_KEYS` ↔ portal `BWMS_KEY_ID`/`BWMS_SECRET`, and BWMS `PORTAL_OUTBOUND_*` ↔ portal `BWMS_INBOUND_KEYS`.
  - Set `PORTAL_BASE_URL`.
- **Portal product map:** prepare the CSV (`portal_product_id, sku, pack_qty`), then run `channels:portal-map --dry-run`.

**5.2 (T−1 day):** re-run the dry-run of §3. Agree the count sheet (bins STOCK and QUARANTINE, quantities in **base units**) and the time T.

## 6. Cutover day: freeze and count

1. Take a backup (§0).
2. **Portal:** turn off its own Ordermentum stock sync (D-6), so BWMS will be the only stock writer.
3. Finish the **last dispatch run** under the old process. Write down the time **T**. From now until §11, nothing physically leaves or arrives.
4. Count the warehouse into the stocktake CSV (`sku,qty,bin,site,lot,best_before`, quantities in base units).

**Check:** the CSV is complete. A second person spot-checks 10 lines.

## 7. Opening balances

```bash
php artisan bwms:migrate-legacy --dry-run --opening-balances=stocktake.csv --cutover="<T>"
php artisan bwms:migrate-legacy --commit  --opening-balances=stocktake.csv --cutover="<T>"
php artisan stock:verify
```

**Check:**
- No errors, and `stock:verify` passes.
- Review the default-pack warning.
- Spot-check 10 products on Stock on hand against the count.

## 8. Ordermentum ingestion (orders become reservations)

1. Take a backup.
2. Register the webhook in Ordermentum, in the supplier platform or with the API:
   ```bash
   curl -X POST https://api.ordermentum.com/v1/webhooks -H "x-api-key: $ORDERMENTUM_API_KEY" -H "Content-Type: application/json" \
     -d '{"name":"BWMS","url":"https://bwms.<domain>/webhooks/ordermentum/<ORDERMENTUM_WEBHOOK_TOKEN>","entityType":"supplier","entityId":"<supplierId>","events":["order_created","order_updated"]}'
   ```
   ⚠ Check the `entityType` value against Ordermentum's create-webhook reference (spec B3.1), or register it in their UI.
3. Set `ORDERMENTUM_INGEST_ENABLED=true`, then `config:cache` and `queue:restart`.
4. **Catch up on open orders:** `php artisan channels:poll-ordermentum --since="<start of the open-order window, e.g. T − 7 days>"`.
5. On the Orders screen, **cancel in BWMS any Ordermentum order that physically left before T**, with the reason "shipped before cutover". The count already excludes those goods.

**Check:**
- On the Sync screen's inbound events, new events show `processed`, with the signature column `valid_hex` or `valid_base64`.
- There are no `failed` or `needs_mapping` events, or they've been mapped and replayed.
- Open Ordermentum orders appear as reservations.
- Optional, after one verified real event: set `ORDERMENTUM_WEBHOOK_REQUIRE_TOKEN=false`.

## 9. Ordermentum stock push, in stages

1. Take a backup.
2. Set `ORDERMENTUM_PUSH_ENABLED=true`, then `config:cache` and `queue:restart`.
3. **Pilot:** on the Sync screen → Listings, switch on **3 listings**. Each is pushed. Compare `php artisan om:probe stock <variantId>` with BWMS available (in packs, minus any buffer).
4. Click **Enable all**. This queues a forced push of the rest: about 3 bulk calls, under a minute.
5. Run `php artisan channels:ordermentum reconcile` and let the queue drain.

**Check:**
- Sync screen: no push failures, no reconciliation mismatches, no untracked items.
- Spot-check 5 products in the Ordermentum supplier platform.

## 10. Portal switch-over

1. Take a backup.
2. Run `php artisan channels:portal-map products.csv` (for real this time).
3. Set `PORTAL_API_ENABLED=true`. The portal maintainer switches on the portal's BWMS integration.
4. Set `PORTAL_PUSH_ENABLED=true` and `PORTAL_EVENTS_ENABLED=true`, then `config:cache` and `queue:restart`. Click **Enable all portal listings**; it queues a full push.
5. **Test order:**
   1. Place an order from an internal portal account. BWMS should show a reservation (Orders, channel portal), and the portal should show `reservation_status = confirmed`.
   2. Cancel it on the portal. The reservation should be released.
   3. Try an order beyond stock. The portal should refuse it with the available quantity.

**Check:** the Sync screen's Portal section shows no push failures; the portal's `stock_qty` matches BWMS for 5 products.

## 11. Verification, then resume operations

- [ ] `php artisan bwms:check-triggers` and `php artisan stock:verify` both pass.
- [ ] The Sync screen shows no failures, mismatches, held orders, untracked items, or refused portal calls that haven't been dealt with.
- [ ] The dashboard shows no negative bins, and no unexpected count tasks.
- [ ] Test pick and dispatch of one real order: stock goes down, the reservation is released, the Ordermentum and portal figures update, and the portal gets a `dispatched` event.
- [ ] `supervisorctl status` shows the worker running, the `jobs` table drains, and the scheduler has run (`schedule:list`).

**Then resume picking (Pick & pack, by run) and receiving in BWMS.**

## 12. After go-live

- **T+1:**
  - The nightly jobs ran: `check-triggers` 02:10, `stock:verify` 02:15, Ordermentum reconcile 03:00.
  - Review the Sync screen and the count tasks.
  - Review oversold orders (Ordermentum orders accepted beyond stock).
- **T+7:**
  - Do a cycle count of the 20 fastest SKUs.
  - Review the audit log for adjustments.
  - Confirm the portal's short-shipment credits work (`portal-bwms-integration.md`).
- **Before 1 Jan 2027:** if any portal Ordermentum code still runs, switch it to the API token (`portal-om-token.md`).

## Rollback

Decide at each stage's check. **Pausing never loses data:**
- Ordermentum keeps selling against its own counts; it self-decrements.
- The poll's `--since` catches up any missed orders later.
- The portal keeps taking orders, marked "unconfirmed", and replays its outbox when BWMS comes back (D-5).

| Level | When | Do |
|---|---|---|
| **1. Pause** | A sync misbehaves (wrong figures, errors) | Set the affected switches to false (`ORDERMENTUM_PUSH_ENABLED`, `ORDERMENTUM_INGEST_ENABLED`, `PORTAL_*`), then `config:cache` and `queue:restart`. Fix it, then resume from that stage. |
| **2. Restore** | BWMS data is wrong (bad import, bad pack setup, bad opening balances) | Pause (level 1). Restore the backup taken at the start of the stage. Fix the input file and redo the stage. Never `migrate:rollback`. |
| **3. Abandon the cutover** | Go-live can't continue today | All switches off. Portal: switch its BWMS integration off, so it uses its own `stock_qty` as before. Ordermentum: its figures stay as they are (if pushes had started, re-set the stocktake figures by hand in the supplier platform, or leave BWMS's last push). Remove the webhook in Ordermentum. The **old BWMS is not brought back**: it overwrote stock with Ordermentum figures (audit C-3). Reschedule T and repeat from §6 after restoring the pre-§6 backup. |
