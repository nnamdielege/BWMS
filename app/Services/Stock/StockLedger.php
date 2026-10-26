<?php

namespace App\Services\Stock;

use App\Events\StockChanged;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\Exceptions\IdempotencyConflict;
use App\Services\Stock\Exceptions\InsufficientStock;
use App\Services\Stock\Exceptions\InvalidMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of stock. Posts one or more movements atomically:
 *
 *  - validates each draft (sign, bin, batch, reason code)
 *  - is idempotent: re-posting the same keys returns the original movements;
 *    reusing a key for different content throws IdempotencyConflict
 *  - locks the affected balance rows in a fixed order (product, location,
 *    batch) so concurrent posts serialise without deadlocking
 *  - rejects a result below zero unless every withdrawing draft allows it
 *  - updates the balance projection in the same transaction
 *  - fires StockChanged after commit
 */
class StockLedger
{
    private const MAX_ATTEMPTS = 6;

    /**
     * @param  list<MovementDraft>  $drafts
     * @return Collection<int, StockMovement> movements in draft order
     */
    public function post(array $drafts, ?User $actor = null): Collection
    {
        if ($drafts === []) {
            throw new InvalidMovement('Nothing to post.');
        }
        foreach ($drafts as $draft) {
            $this->validate($draft);
        }
        $keys = array_map(fn (MovementDraft $d) => $d->idempotencyKey, $drafts);
        if (count($keys) !== count(array_unique($keys))) {
            throw new InvalidMovement('Idempotency keys must be unique within one posting.');
        }

        $slots = $this->slots($drafts);

        // Create any missing balance rows in short autocommit statements before
        // the transaction, so inside it we only take exclusive row locks in
        // slot order (INSERT IGNORE inside the transaction takes a shared lock
        // on existing rows and the S->X upgrade deadlocks under contention).
        if (DB::transactionLevel() === 0) {
            $this->ensureBalanceRows($slots);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->apply($drafts, $slots, $actor));
            } catch (QueryException $e) {
                $retryable = $this->isDuplicateIdempotencyKey($e) || $this->isLockConflict($e);
                // Retry only at the outermost level: a nested call cannot restart
                // its caller's transaction. A duplicate key means a concurrent post
                // won; the retry finds and returns it (or raises a conflict).
                if ($retryable && $attempt < self::MAX_ATTEMPTS && DB::transactionLevel() === 0) {
                    usleep(random_int(5_000, 25_000) * $attempt);

                    continue;
                }
                throw $e;
            }
        }
    }

    /** Convenience for a single movement. */
    public function postOne(MovementDraft $draft, ?User $actor = null): StockMovement
    {
        return $this->post([$draft], $actor)->first();
    }

    /**
     * @param  list<MovementDraft>  $drafts
     * @param  array<string, list<MovementDraft>>  $slots
     * @return Collection<int, StockMovement>
     */
    private function apply(array $drafts, array $slots, ?User $actor): Collection
    {
        $keys = array_map(fn (MovementDraft $d) => $d->idempotencyKey, $drafts);
        $existing = StockMovement::whereIn('idempotency_key', $keys)->get()->keyBy('idempotency_key');

        if ($existing->isNotEmpty()) {
            return $this->replay($drafts, $existing);
        }

        $balances = $this->lockBalances($slots);

        foreach ($slots as $slotKey => $slot) {
            $balance = $balances[$slotKey];
            $delta = array_sum(array_map(fn (MovementDraft $d) => $d->qty, $slot));
            $newOnHand = $balance->on_hand + $delta;

            $withdrawals = array_filter($slot, fn (MovementDraft $d) => $d->qty < 0);
            $mayGoNegative = $withdrawals !== [] && array_reduce($withdrawals, fn ($ok, MovementDraft $d) => $ok && $d->allowNegative, true);

            if ($newOnHand < 0 && $withdrawals !== [] && ! $mayGoNegative) {
                $first = $slot[0];
                throw InsufficientStock::for($first->product->sku, $first->location->path(), $balance->on_hand, -$delta);
            }

            $balance->forceFill(['on_hand' => $newOnHand, 'updated_at' => now()])->save();
        }

        $userId = $actor?->id ?? Auth::id();
        $movements = collect($drafts)->map(fn (MovementDraft $d) => StockMovement::create([
            'product_id' => $d->product->id,
            'location_id' => $d->location->id,
            'batch_id' => $d->batch?->id,
            'qty' => $d->qty,
            'type' => $d->type,
            'reason_code_id' => $d->reason?->id,
            'source_type' => $d->sourceType,
            'source_id' => $d->sourceId,
            'idempotency_key' => $d->idempotencyKey,
            'payload_hash' => $d->payloadHash(),
            'unit_cost' => $d->unitCost,
            'occurred_at' => $d->occurredAt ?? now(),
            'created_by' => $userId,
            'note' => $d->note,
        ]));

        StockChanged::dispatch(array_values(array_unique(array_map(fn (MovementDraft $d) => $d->product->id, $drafts))));

        return $movements;
    }

    /**
     * @param  list<MovementDraft>  $drafts
     * @param  Collection<string, StockMovement>  $existing
     * @return Collection<int, StockMovement>
     */
    private function replay(array $drafts, Collection $existing): Collection
    {
        if ($existing->count() !== count($drafts)) {
            throw new IdempotencyConflict('Some of these movements were already posted and some were not; refusing to post a partial duplicate.');
        }

        return collect($drafts)->map(function (MovementDraft $d) use ($existing) {
            $movement = $existing[$d->idempotencyKey];
            if ($movement->payload_hash !== $d->payloadHash()) {
                throw new IdempotencyConflict("Idempotency key {$d->idempotencyKey} was already used for a different movement.");
            }

            return $movement;
        });
    }

    /**
     * Group drafts by balance slot, sorted for deterministic lock order.
     *
     * @param  list<MovementDraft>  $drafts
     * @return array<string, list<MovementDraft>>
     */
    private function slots(array $drafts): array
    {
        $slots = [];
        foreach ($drafts as $d) {
            $slots[$this->slotKey($d->product->id, $d->location->id, $d->batchKey())][] = $d;
        }
        ksort($slots, SORT_STRING);

        return $slots;
    }

    /** @param  array<string, list<MovementDraft>>  $slots */
    private function ensureBalanceRows(array $slots): void
    {
        foreach ($slots as $slot) {
            $this->insertBalanceRowIfMissing($slot[0]);
        }
    }

    private function insertBalanceRowIfMissing(MovementDraft $d): void
    {
        DB::table('stock_balances')->insertOrIgnore([
            'product_id' => $d->product->id,
            'location_id' => $d->location->id,
            'batch_id' => $d->batch?->id,
            'batch_key' => $d->batchKey(),
            'on_hand' => 0,
            'updated_at' => now(),
        ]);
    }

    /**
     * SELECT ... FOR UPDATE each balance row in slot order. Rows normally exist
     * already (ensureBalanceRows); when called inside a caller's transaction
     * a missing row is created on the spot.
     *
     * @param  array<string, list<MovementDraft>>  $slots
     * @return array<string, StockBalance>
     */
    private function lockBalances(array $slots): array
    {
        $locked = [];
        foreach ($slots as $slotKey => $slot) {
            $d = $slot[0];
            $find = fn () => StockBalance::where('product_id', $d->product->id)
                ->where('location_id', $d->location->id)
                ->where('batch_key', $d->batchKey())
                ->lockForUpdate()
                ->first();

            $balance = $find();
            if (! $balance) {
                $this->insertBalanceRowIfMissing($d);
                $balance = $find();
            }
            $locked[$slotKey] = $balance;
        }

        return $locked;
    }

    /** Zero-padded so string sort equals numeric (product, location, batch) order. */
    private function slotKey(int $productId, int $locationId, int $batchKey): string
    {
        return sprintf('%012d-%012d-%012d', $productId, $locationId, $batchKey);
    }

    private function validate(MovementDraft $d): void
    {
        $sku = $d->product->sku;

        if ($d->qty === 0) {
            throw new InvalidMovement("Quantity for {$sku} cannot be zero.");
        }
        if ($d->type->sign() !== 0 && ($d->qty > 0 ? 1 : -1) !== $d->type->sign()) {
            throw new InvalidMovement("A {$d->type->label()} movement must be ".($d->type->sign() > 0 ? 'positive' : 'negative').'.');
        }
        if (trim($d->idempotencyKey) === '' || strlen($d->idempotencyKey) > 191) {
            throw new InvalidMovement('A movement needs an idempotency key of at most 191 characters.');
        }
        if (! $d->location->isBin() || ! $d->location->is_active) {
            throw new InvalidMovement("Stock can only be held in an active bin ({$d->location->code} is not).");
        }

        if ($d->product->is_batch_tracked && ! $d->batch) {
            throw new InvalidMovement("{$sku} is batch-tracked: a batch (lot / best-before) is required.");
        }
        if (! $d->product->is_batch_tracked && $d->batch) {
            throw new InvalidMovement("{$sku} is not batch-tracked; remove the batch.");
        }
        if ($d->batch && $d->batch->product_id !== $d->product->id) {
            throw new InvalidMovement("Batch {$d->batch->lot_code} belongs to a different product.");
        }

        if ($d->type->requiresReason()) {
            if (! $d->reason) {
                throw new InvalidMovement('A reason code is required for '.strtolower($d->type->label()).'s.');
            }
            if ($d->reason->movement_type !== $d->type || ! $d->reason->is_active) {
                throw new InvalidMovement("Reason {$d->reason->code} cannot be used for this movement.");
            }
            if (! $d->reason->allowsQty($d->qty)) {
                throw new InvalidMovement("Reason {$d->reason->code} only allows stock ".($d->reason->direction === 'in' ? 'increases' : 'decreases').'.');
            }
            if ($d->reason->requires_note && trim((string) $d->note) === '') {
                throw new InvalidMovement("Reason {$d->reason->code} requires a note.");
            }
        } elseif ($d->reason) {
            throw new InvalidMovement('Reason codes apply only to adjustments and write-offs.');
        }
    }

    /** Deadlock (1213) or lock wait timeout (1205). */
    private function isLockConflict(QueryException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, [1213, 1205], true);
    }

    private function isDuplicateIdempotencyKey(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && str_contains($e->getMessage(), 'idempotency_key');
    }
}
