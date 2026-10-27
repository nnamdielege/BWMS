# BWMS (rebuild)

Warehouse management for Local Selection. BWMS is the single source of truth
for stock; Ordermentum and the B2B portal receive stock levels from it.
Spec: `docs/02-requirements.md` on the `rebuild/docs` branch of this repo.

Laravel 12 · Inertia · Vue 3 (TypeScript) · MySQL/MariaDB.

## Local layout (Windows / XAMPP)

| Path | What | Branch |
|---|---|---|
| `C:\xampp\htdocs\bwms` | **This app** (git worktree) | `rebuild/app` (becomes `main` at cutover) |
| `C:\xampp\htdocs\bwms-legacy` | Retired legacy app, kept for reference (its `.env` too). **Holds the repository's `.git`**: do not delete it while the worktree above depends on it. | `rebuild/docs` (spec, audit) |

Both are working trees of one repository (`origin` = github.com/nnamdielege/BWMS). Run the app with `php artisan serve --port=8001` (port 8000 is used by another local project).

## Setup (local)

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
cp .env.testing.example .env.testing   # then set APP_KEY (php artisan key:generate --show)
# create databases bwms_next (dev) and bwms_test (tests) (utf8mb4)
php artisan migrate --seed          # roles, default site + bins, reason codes
php artisan bwms:invite-admin you@example.com "Your Name"   # prints a one-time link
npm run dev && php artisan serve --port=8001
```

There is no registration and no default password: every user is invited.

**Database safety.** `migrate:fresh/refresh/reset/rollback`, `db:wipe`, `RefreshDatabase` and `DatabaseTruncation` refuse unless the database name ends in `_test` (`App\Support\DatabaseSafety`). Tests run on `bwms_test` (forced in `phpunit.xml`, also in `.env.testing`). On any other database, restore a backup instead of rolling back.

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
| 3 | Stock ledger, batches/best-before, FEFO, adjustments, transfers, stocktakes | Done (reviewed) |
| — | Review changes: ranges, admin 2FA, trigger check + deploy docs, cost back-fill | Done |
| 4 | Suppliers, POs, receiving with discrepancies, PO PDF (mPDF) + queued mail | Done |
| 5 | Customers, orders, reservations, pick lists (FEFO), dispatch | Done |
| — | Legacy data migration (`bwms:migrate-legacy`, dry-run + report, opening balances from the stocktake CSV) | Done (`docs/MIGRATION.md`) |
| 6 | Ordermentum stock push, order ingestion (webhook + poll), sync status | Done (`docs/ORDERMENTUM.md`; live write test passed 2026-10-27) |
| 7 | Portal integration API | Done (`docs/PORTAL.md`) |
| — | Pick & pack MVP: delivery runs, guided scanning, pack and dispatch by run | Done (`docs/PICK-PACK.md`; suite passes on MariaDB 10.5.29) |
| — | Go-live | Runbook in `docs/CUTOVER.md` |

## Concurrency rules (read before touching stock code)

- Lock order is always **products → stock balances**. Reservations, receiving and
  dispatch lock the product rows (`FOR UPDATE`, id order) before anything else.
- Any check-then-write inside a transaction must use **locking reads**
  (`sharedLock()` / `lockForUpdate()`). MariaDB's REPEATABLE READ snapshot
  otherwise hides rows committed by concurrent transactions. This caused an
  oversell in testing before it was fixed.
- Never `INSERT IGNORE` and then `SELECT … FOR UPDATE` the same row inside a
  transaction (S→X upgrade deadlock). Lock first; insert only if missing.
- `php artisan test --group=concurrency` runs real multi-process races.
