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

## Ops notes

- The DB user needs the `TRIGGER` privilege (and on MySQL with binlog,
  `log_bin_trust_function_creators=1`) for the ledger triggers. If that
  can't be granted, set `BWMS_LEDGER_DB_TRIGGERS=false` and record why.
- Scheduler: `* * * * * php artisan schedule:run`. Queue workers under
  Supervisor (configured in later steps).
- Developed against MariaDB 10.4 locally. Confirm the production engine
  and version.

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
