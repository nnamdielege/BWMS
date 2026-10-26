# Deploy checklist

Target: VPS, **MariaDB 10.5.29**, PHP 8.2+, Nginx + PHP-FPM, Supervisor.
Run every step on the first deploy; steps marked **(every deploy)** each time.

## 1. Database (first deploy, needs a DB admin)

The stock ledger (`stock_movements`) is protected by two `BEFORE UPDATE/DELETE`
triggers. On MariaDB with binary logging enabled, a non-SUPER user cannot create
triggers (verified on 10.5.29: `ERROR 1419 You do not have the SUPER privilege
and binary logging is enabled`).

- [ ] Server config (`/etc/mysql/mariadb.conf.d/50-server.cnf`, `[mysqld]`):
      ```ini
      log_bin_trust_function_creators = 1
      ```
      Restart MariaDB, or apply live with `SET GLOBAL log_bin_trust_function_creators = 1;`
      (a live change is lost on restart unless it is also in the config file).
- [ ] App user privileges (schema-scoped, never global):
      ```sql
      CREATE DATABASE bwms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE USER 'bwms'@'localhost' IDENTIFIED BY '<secret>';
      GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES,
            CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER
        ON bwms.* TO 'bwms'@'localhost';
      ```
- [ ] If the server setting cannot be changed: a DB admin runs the trigger
      migration once (`php artisan migrate --force` with admin credentials), then the
      app runs with the normal user. Record this in the ops log.
- [ ] `BWMS_LEDGER_DB_TRIGGERS=false` is a **last resort only**, and
      `bwms:check-triggers` will keep failing while it is set.

## 2. Application

- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, DB credentials,
      `MAIL_*`, `QUEUE_CONNECTION=database` (or `redis` if available),
      `SESSION_SECURE_COOKIE=true`.
- [ ] **(every deploy)**
      ```bash
      composer install --no-dev --optimize-autoloader
      npm ci && npm run build
      php artisan migrate --force
      php artisan db:seed --force          # idempotent: roles, default site, reason codes
      php artisan bwms:check-triggers      # MUST print OK; abort the deploy if it fails
      php artisan stock:verify             # balances = ledger
      php artisan config:cache && php artisan route:cache && php artisan view:cache
      php artisan queue:restart
      ```
- [ ] First admin: `php artisan bwms:invite-admin <email> "<name>"`, open the
      printed link, set a password, set up two-factor (required for admins).

## 3. Processes

- [ ] Cron: `* * * * * cd /var/www/bwms && php artisan schedule:run >> /dev/null 2>&1`
      (runs `bwms:check-triggers` 02:10 and `stock:verify` 02:15 Brisbane time daily).
- [ ] Supervisor queue worker. `retry_after` (config/queue.php) must stay greater
      than every job's `$timeout`:
      ```ini
      [program:bwms-worker]
      command=php /var/www/bwms/artisan queue:work --sleep=3 --tries=3 --max-jobs=500 --max-time=3600
      numprocs=2
      autostart=true
      autorestart=true
      stopwaitsecs=120
      ```
- [ ] Local caching DNS resolver enabled on the VPS (systemd-resolved or unbound).
      See spec NFR-14 and audit §7 (previous DNS/FD incidents).

## 4. Smoke test

- [ ] Log in as admin (2FA challenge appears).
- [ ] Locations shows Clontarf Warehouse with RECEIVING / STOCK / QUARANTINE.
- [ ] `php artisan bwms:check-triggers` and `php artisan stock:verify` both OK.
