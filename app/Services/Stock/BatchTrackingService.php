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

    public function __construct(private StockLedger $ledger) {}

    /** Why this product can't be turned on now, or null. */
    public function blocker(Product $product): ?string
    {
        return match (true) {
            $product->is_batch_tracked => 'Already batch-tracked.',
            ! $product->is_stock_tracked => 'Not stock-tracked.',
            $this->balances($product)->contains(fn (StockBalance $b) => $b->on_hand < 0) => 'A bin is below zero. Count it first.',
            PickListLine::where('product_id', $product->id)->whereHas('pickList', fn ($q) => $q->where('status', 'open'))->exists() => 'On an open pick list. Dispatch or cancel it first.',
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

    /** Unbatched stock by bin. */
    private function balances(Product $product)
    {
        return StockBalance::where('product_id', $product->id)->where('batch_key', 0)->where('on_hand', '!=', 0)->with('location.parent')->orderBy('location_id')->get();
    }
}
