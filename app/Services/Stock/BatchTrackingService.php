<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\PickListLine;
use App\Models\Product;
use App\Models\ReasonCode;
use App\Models\StockBalance;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns on batch tracking for a product that already holds stock. Each bin's
 * unbatched on hand moves into an UNKNOWN batch, with the best-before the user
 * entered or none: an UNKNOWN batch without a date never expires and is picked
 * first (FefoAllocator), so the old stock clears before anything received later.
 *
 * Two adjustments per bin, out of the unbatched slot and into the batch, so the
 * net change is zero and the ledger stays the only writer of stock.
 *
 * Refused while a pick list or stocktake holds the product: those recorded
 * quantities against the unbatched stock, and it would no longer be there.
 */
class BatchTrackingService
{
    public const REASON = 'BATCH_ENABLE';

    public const REASON_OFF = 'BATCH_DISABLE';

    public function __construct(private StockLedger $ledger) {}

    /** Why this product can't be turned on now, or null. */
    public function blocker(Product $product): ?string
    {
        return match (true) {
            $product->is_batch_tracked => 'Already batch-tracked.',
            ! $product->is_stock_tracked => 'Not stock-tracked.',
            $this->balances($product)->contains(fn (StockBalance $b) => $b->on_hand < 0) => 'A bin is below zero. Count it first.',
            PickListLine::where('product_id', $product->id)->where('no_stock', false)->whereHas('pickList', fn ($q) => $q->where('status', 'open'))->exists() => 'On an open pick list. Dispatch or cancel it first.',
            StocktakeLine::where('product_id', $product->id)->whereHas('stocktake', fn ($q) => $q->where('status', Stocktake::STATUS_COUNTING))->exists() => 'On a stocktake in progress.',
            default => null,
        };
    }

    /** @return list<array{location_id: int, bin: string, qty: int}> */
    public function bins(Product $product): array
    {
        return $this->balances($product)->map(fn (StockBalance $b) => [
            'location_id' => $b->location_id, 'bin' => $b->location->path(), 'qty' => $b->on_hand,
        ])->values()->all();
    }

    /**
     * @param  array<int, ?string>  $bestBefore  location_id => Y-m-d, or null for unknown. Must name every bin holding the product.
     *
     * @throws InvalidMovement
     */
    public function enable(Product $product, array $bestBefore, User $actor): Product
    {
        return DB::transaction(function () use ($product, $bestBefore, $actor) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            if ($blocker = $this->blocker($product)) {
                throw new InvalidMovement("{$product->sku}: {$blocker}");
            }

            $balances = $this->balances($product);
            if ($balances->pluck('location_id')->sort()->values()->all() !== collect(array_keys($bestBefore))->map(fn ($id) => (int) $id)->sort()->values()->all()) {
                throw new InvalidMovement("{$product->sku}: its bins changed since the preview. Check them again.");
            }

            $product->forceFill(['is_batch_tracked' => true])->save();

            $reason = ReasonCode::where('code', self::REASON)->firstOrFail();
            $run = Str::uuid();
            $drafts = [];
            $bins = [];
            foreach ($balances as $balance) {
                $date = $bestBefore[$balance->location_id] ?: null;
                $batch = Batch::findOrCreateFor($product, Batch::UNKNOWN_LOT, $date, BusinessDate::today());
                $common = ['product' => $product, 'location' => $balance->location, 'type' => MovementType::Adjustment, 'reason' => $reason, 'sourceType' => 'batch_enable', 'sourceId' => $product->id];
                $drafts[] = new MovementDraft(...$common, qty: -$balance->on_hand, idempotencyKey: "batch-enable:{$run}:{$balance->location_id}:out", leavesUnbatchedStock: true);
                $drafts[] = new MovementDraft(...$common, qty: $balance->on_hand, idempotencyKey: "batch-enable:{$run}:{$balance->location_id}:in", batch: $batch);
                $bins[] = ['bin' => $balance->location->path(), 'qty' => $balance->on_hand, 'lot' => $batch->lot_code, 'best_before' => $date];
            }
            if ($drafts !== []) {
                $this->ledger->post($drafts, $actor);
            }

            Audit::record('batch_tracking_enabled', $product, ['is_batch_tracked' => false], ['is_batch_tracked' => true], ['bins' => $bins], $actor->id);

            return $product;
        });
    }

    /** Why batch tracking can't be turned off for this product now, or null. Same rules as turning it on. */
    public function offBlocker(Product $product): ?string
    {
        return match (true) {
            ! $product->is_batch_tracked => 'Not batch-tracked.',
            StockBalance::where('product_id', $product->id)->where('on_hand', '<', 0)->exists() => 'A bin is below zero. Count it first.',
            PickListLine::where('product_id', $product->id)->where('no_stock', false)->whereHas('pickList', fn ($q) => $q->where('status', 'open'))->exists() => 'On an open pick list. Dispatch or cancel it first.',
            StocktakeLine::where('product_id', $product->id)->whereHas('stocktake', fn ($q) => $q->where('status', Stocktake::STATUS_COUNTING))->exists() => 'On a stocktake in progress.',
            default => null,
        };
    }

    /**
     * What turning it off would merge, per bin: each batch and the bin's total.
     *
     * @return list<array{location_id: int, bin: string, qty: int, batches: list<array{batch_id: ?int, lot: ?string, best_before: ?string, qty: int, expired: bool}>}>
     */
    public function batchBins(Product $product): array
    {
        $today = BusinessDate::today();

        return $this->allBalances($product)->groupBy('location_id')->map(fn ($g) => [
            'location_id' => $g->first()->location_id,
            'bin' => $g->first()->location->path(),
            'qty' => (int) $g->sum('on_hand'),
            'batches' => $g->map(fn (StockBalance $b) => [
                'batch_id' => $b->batch_id,
                'lot' => $b->batch?->lot_code,
                'best_before' => $b->batch?->best_before?->toDateString(),
                'qty' => $b->on_hand,
                'expired' => (bool) ($b->batch?->best_before && $b->batch->best_before->toDateString() < $today),
            ])->values()->all(),
        ])->values()->all();
    }

    /** @return list<array{bin: string, lot: ?string, best_before: ?string, qty: int}> stock in batches past their best-before */
    public function expired(Product $product): array
    {
        return collect($this->batchBins($product))->flatMap(fn ($bin) => collect($bin['batches'])->where('expired', true)
            ->map(fn ($b) => ['bin' => $bin['bin'], 'lot' => $b['lot'], 'best_before' => $b['best_before'], 'qty' => $b['qty']]))->values()->all();
    }

    /**
     * Turns batch tracking off: in each bin, every batch of the product merges into one unbatched
     * balance (out of each batch, one in to the unbatched slot, net zero), then the flag is cleared.
     * Expired stock merges too only with $includeExpired; otherwise write it off first.
     *
     * @throws InvalidMovement
     */
    public function disable(Product $product, bool $includeExpired, User $actor): Product
    {
        return DB::transaction(function () use ($product, $includeExpired, $actor) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            if ($blocker = $this->offBlocker($product)) {
                throw new InvalidMovement("{$product->sku}: {$blocker}");
            }
            $expired = $this->expired($product);
            if ($expired !== [] && ! $includeExpired) {
                $list = collect($expired)->map(fn ($e) => "{$e['qty']} in {$e['bin']} (lot {$e['lot']}, best-before {$e['best_before']})")->implode('; ');
                throw new InvalidMovement("{$product->sku}: has expired stock ({$list}). Tick \"include expired\" or write it off first.");
            }

            $bins = $this->batchBins($product);
            $product->forceFill(['is_batch_tracked' => false])->save();

            $reason = ReasonCode::where('code', self::REASON_OFF)->firstOrFail();
            $run = Str::uuid();
            $drafts = [];
            $locations = $this->allBalances($product)->pluck('location', 'location_id');
            foreach ($bins as $bin) {
                $common = ['product' => $product, 'location' => $locations[$bin['location_id']], 'type' => MovementType::Adjustment, 'reason' => $reason,
                    'sourceType' => 'batch_disable', 'sourceId' => $product->id];
                $merged = 0;
                foreach ($bin['batches'] as $b) {
                    if ($b['batch_id'] === null) {
                        continue; // already unbatched
                    }
                    $drafts[] = new MovementDraft(...$common, qty: -$b['qty'], idempotencyKey: "batch-disable:{$run}:{$bin['location_id']}:{$b['batch_id']}",
                        batch: Batch::find($b['batch_id']), leavesBatch: true);
                    $merged += $b['qty'];
                }
                if ($merged > 0) {
                    $drafts[] = new MovementDraft(...$common, qty: $merged, idempotencyKey: "batch-disable:{$run}:{$bin['location_id']}:in");
                }
            }
            if ($drafts !== []) {
                $this->ledger->post($drafts, $actor);
            }

            Audit::record('batch_tracking_disabled', $product, ['is_batch_tracked' => true], ['is_batch_tracked' => false],
                ['bins' => array_map(fn ($b) => ['bin' => $b['bin'], 'qty' => $b['qty'], 'batches' => array_map(fn ($x) => array_intersect_key($x, array_flip(['lot', 'best_before', 'qty', 'expired'])), $b['batches'])], $bins),
                    'expired_included' => $expired !== []], $actor->id);

            return $product;
        });
    }

    /** Every non-zero balance of the product, any batch. */
    private function allBalances(Product $product)
    {
        return StockBalance::where('product_id', $product->id)->where('on_hand', '!=', 0)->with(['location.parent', 'batch'])->orderBy('location_id')->orderBy('batch_key')->get();
    }

    /** Unbatched stock by bin. */
    private function balances(Product $product)
    {
        return StockBalance::where('product_id', $product->id)->where('batch_key', 0)->where('on_hand', '!=', 0)->with('location.parent')->orderBy('location_id')->get();
    }
}
