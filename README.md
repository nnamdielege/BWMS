# BWMS (rebuild)

Warehouse management for Local Selection. BWMS is the single source of truth
for stock; Ordermentum and the B2B portal receive stock levels from it.
Spec: `docs/02-requirements.md` on the `rebuild/docs` branch of this repo.

Laravel 12 · Inertia · Vue 3 (TypeScript) · MySQL/MariaDB.

## Setup (local)

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
# create databases bwms_next and bwms_next_testing (utf8mb4)
php artisan migrate --seed          # roles, default site + bins, reason codes
php artisan bwms:invite-admin you@example.com "Your Name"   # prints a one-time link
npm run dev && php artisan serve --port=8001
```

There is no registration and no default password: every user is invited.

## Quality gates

```bash
php artisan test                      # MySQL/MariaDB, never SQLite (row locks)
php artisan test --group=concurrency  # multi-process ledger contention tests
vendor/bin/pint --test
npx vue-tsc --noEmit && npx eslint resources/js
```

`tests/Feature/RouteAuthorizationTest.php` fails if any route lacks
authentication plus a `can:` check, unless it is explicitly allowlisted.

## Stock model

- `stock_movements` is the append-only ledger: signed integers in the
  product's base unit, with a unique `idempotency_key`. Updates and deletes are
  rejected by the model *and* by DB triggers.
- `stock_balances` is a projection (product × bin × batch) updated in the same
  transaction as each movement. `stock:verify` (scheduled nightly) checks it
  against the ledger; `stock:rebuild-balances` repairs it.
- All writes go through `App\Services\Stock\StockLedger`. Never write stock
  tables directly.
- Sellable = sellable, active, non-quarantine bins, excluding batches past
  best-before (Brisbane date). FEFO allocation: `FefoAllocator`.

## Deploying (production: MariaDB 10.5.29)

Full checklist: **[docs/DEPLOY.md](docs/DEPLOY.md)**.

> **Database requirement:** the ledger triggers need
> `log_bin_trust_function_creators = 1` in the MariaDB server config (binary
> logging is normally on) **and** `GRANT TRIGGER ON <schema>.*` for the app user.
> Without the first, migrations fail with MariaDB error 1419 (verified on
> 10.5.29). After every deploy, run `php artisan bwms:check-triggers`. It exits
> non-zero, prints the fix and logs a critical error if the triggers are missing.
> It also runs daily from the scheduler.

- Scheduler: `* * * * * php artisan schedule:run`. Queue workers under Supervisor.
- Local development uses MariaDB 10.4. The suite has also been run on MariaDB
  10.5.29 (Docker, binary logging on). Avoid MySQL 8-only SQL.
- Admins must set up TOTP two-factor before they can use the app (D-8).

## Build status

| Step | Scope | Status |
|---|---|---|
| 1 | Scaffold, invite-only auth, roles/permissions, audit log | Done |
| 2 | Products, pack sizes, barcodes, locations | Done |
| 3 | Stock ledger, batches/best-before, FEFO, adjustments, transfers, stocktakes | Done (review checkpoint) |
| 4 | Suppliers, POs, receiving, PO PDF/mail | Not started |
| 5 | Reservations, pick lists, dispatch | Not started |
| 6 | Ordermentum stock push + sync status | Not started |
| 7 | Portal integration API | Not started |
| — | Ordermentum order ingestion, legacy data migration | Blocked (interfaces/stubs only) |
