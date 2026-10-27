<?php

namespace App\Services\Stock;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Stocktake;
use App\Models\StocktakeLine;
use App\Models\User;
use App\Services\Stock\Exceptions\InvalidMovement;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Full or partial (zone/bin) stocktakes.
 *
 * Operations may continue while counting: each count records the system
 * quantity at the moment it was entered, and the posted variance is
 * counted - system_qty_at_count, applied as a delta to the live balance.
 * Re-counting a line replaces both values.
 */
class StocktakeService
{
    public function __construct(private StockLedger $ledger, private StockQuery $stock, private CountTaskService $countTasks) {}

    public function create(User $actor, Location $scope, ?string $note = null): Stocktake
    {
        return DB::transaction(function () use ($actor, $scope, $note) {
            $stocktake = Stocktake::create([
                'number' => 'TMP-'.Str::random(12),
                'scope_location_id' => $scope->id,
                'status' => Stocktake::STATUS_COUNTING,
                'snapshot_at' => now(),
                'note' => $note,
                'created_by' => $actor->id,
            ]);
            $stocktake->forceFill(['number' => sprintf('STK-%05d', $stocktake->id)])->saveQuietly();

            StockBalance::whereIn('location_id', $this->stock->binIdsUnder($scope))
                ->where('on_hand', '!=', 0)
                ->whereHas('product', fn ($q) => $q->where('is_stock_tracked', true))
                ->get()
                ->each(fn (StockBalance $b) => $stocktake->lines()->create([
                    'product_id' => $b->product_id,
                    'location_id' => $b->location_id,
                    'batch_id' => $b->batch_id,
                    'batch_key' => $b->batch_key,
                    'snapshot_qty' => $b->on_hand,
                ]));

            return $stocktake;
        });
    }

    /** Add a line for stock found that wasn't in the snapshot. */
    public function addLine(Stocktake $stocktake, Product $product, Location $bin, ?Batch $batch): StocktakeLine
    {
        $this->assertOpen($stocktake);
        if (! $product->is_stock_tracked) {
            throw new InvalidMovement("{$product->sku} is not stock-tracked and is not counted.");
        }

        if (! in_array($bin->id, $this->stock->binIdsUnder($stocktake->scope), true)) {
            throw new InvalidMovement("Bin {$bin->code} is outside this stocktake's scope.");
        }
        if ($product->is_batch_tracked !== ($batch !== null)) {
            throw new InvalidMovement($product->is_batch_tracked ? "{$product->sku} needs a batch." : "{$product->sku} is not batch-tracked.");
        }

        return $stocktake->lines()->firstOrCreate(
            ['product_id' => $product->id, 'location_id' => $bin->id, 'batch_key' => $batch?->id ?? 0],
            ['batch_id' => $batch?->id, 'snapshot_qty' => 0],
        );
    }

    public function recordCount(StocktakeLine $line, int $counted, User $actor): StocktakeLine
    {
        $this->assertOpen($line->stocktake);
        if ($counted < 0) {
            throw new InvalidMovement('A count cannot be negative.');
        }

        $line->forceFill([
            'counted_qty' => $counted,
            'system_qty_at_count' => $this->currentOnHand($line),
            'counted_by' => $actor->id,
            'counted_at' => now(),
        ])->save();

        return $line;
    }

    /**
     * Post all variances atomically. Uncounted lines block posting unless
     * $zeroUncounted is set, in which case they are counted as 0 now.
     */
    public function post(Stocktake $stocktake, User $actor, bool $zeroUncounted = false): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $actor, $zeroUncounted) {
            $stocktake = Stocktake::whereKey($stocktake->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($stocktake);

            $lines = $stocktake->lines()->with(['product', 'location', 'batch'])->get();
            $uncounted = $lines->whereNull('counted_qty');

            if ($uncounted->isNotEmpty() && ! $zeroUncounted) {
                throw new InvalidMovement("{$uncounted->count()} line(s) have not been counted. Count them, or post with uncounted lines treated as zero.");
            }
            foreach ($uncounted as $line) {
                $this->recordCount($line, 0, $actor);
            }

            $drafts = [];
            foreach ($lines as $line) {
                $variance = $line->variance();
                $line->forceFill(['posted_variance' => $variance])->save();

                if ($variance !== 0) {
                    $drafts[] = new MovementDraft(
                        product: $line->product,
                        location: $line->location,
                        qty: $variance,
                        type: MovementType::StocktakeVariance,
                        idempotencyKey: "stk:{$stocktake->id}:line:{$line->id}",
                        batch: $line->batch,
                        sourceType: 'stocktake',
                        sourceId: $stocktake->id,
                        note: $stocktake->number,
                        // The count is the physical truth; never block it.
                        allowNegative: true,
                    );
                }
            }

            if ($drafts !== []) {
                $this->ledger->post($drafts, $actor);
            }

            $stocktake->forceFill(['status' => Stocktake::STATUS_POSTED, 'posted_by' => $actor->id, 'posted_at' => now()])->save();
            $closedTasks = $this->countTasks->closeForStocktake($stocktake, $actor);
            Audit::record('stocktake_posted', $stocktake, meta: ['lines' => $lines->count(), 'adjusted_lines' => count($drafts), 'count_tasks_closed' => $closedTasks]);

            return $stocktake;
        });
    }

    public function cancel(Stocktake $stocktake): Stocktake
    {
        $this->assertOpen($stocktake);
        $stocktake->forceFill(['status' => Stocktake::STATUS_CANCELLED])->save();

        return $stocktake;
    }

    private function currentOnHand(StocktakeLine $line): int
    {
        // Locking read so the figure is current even inside post()'s transaction.
        return (int) StockBalance::where('product_id', $line->product_id)
            ->where('location_id', $line->location_id)
            ->where('batch_key', $line->batch_key)
            ->sharedLock()
            ->value('on_hand');
    }

    private function assertOpen(Stocktake $stocktake): void
    {
        if (! $stocktake->isOpen()) {
            throw new InvalidMovement("Stocktake {$stocktake->number} is {$stocktake->status}.");
        }
    }
}
