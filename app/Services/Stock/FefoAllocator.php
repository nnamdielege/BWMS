<?php

namespace App\Services\Stock;

use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockBalance;
use App\Support\BusinessDate;

/**
 * First-expired-first-out allocation of sellable stock. Used for pick
 * suggestions (step 5). Never allocates from quarantine, non-sellable or
 * inactive bins, or expired batches. UNKNOWN batches with no date come first, so
 * stock from before batch tracking clears first; untracked stock comes last.
 */
class FefoAllocator
{
    public function __construct(private StockQuery $stock) {}

    /**
     * $packSize > 1 (an order line in Cases of 12, say) takes whole packs from each
     * bin: a bin with less than a pack free gives nothing, so it stays waiting for
     * stock rather than going out loose. The one exception is $qty that isn't whole
     * packs, a line that already shipped part of a pack: the loose rest may come
     * from any bin, to finish that pack.
     *
     * @param  array<string, int>  $alreadyAllocated  "{location_id}-{batch_key}" => qty already promised
     *                                                to other open pick lists; subtracted from on hand
     * @return array{lines: list<array{balance: StockBalance, qty: int}>, shortfall: int}
     */
    public function allocate(Product $product, int $qty, ?Location $within = null, array $alreadyAllocated = [], int $packSize = 1): array
    {
        $query = StockBalance::query()
            ->select('stock_balances.*')
            ->join('locations', 'locations.id', '=', 'stock_balances.location_id')
            ->leftJoin('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('stock_balances.product_id', $product->id)
            ->where('stock_balances.on_hand', '>', 0)
            ->where('locations.type', Location::TYPE_BIN)
            ->where('locations.is_sellable', true)
            ->where('locations.is_quarantine', false)
            ->where('locations.is_active', true)
            ->where(fn ($q) => $q->whereNull('batches.best_before')->orWhere('batches.best_before', '>=', BusinessDate::today()))
            // Undated UNKNOWN stock, then earliest best-before; other batches with no date and untracked stock last.
            ->orderByRaw('CASE WHEN batches.lot_code = ? AND batches.best_before IS NULL THEN 0 ELSE 1 END', [Batch::UNKNOWN_LOT])
            ->orderByRaw('batches.best_before IS NULL, batches.best_before ASC')
            ->orderBy('batches.received_on')
            ->orderBy('batches.id')
            ->orderBy('locations.sort_order')
            ->orderBy('locations.code')
            ->with(['location', 'batch']);

        if ($within) {
            $query->whereIn('stock_balances.location_id', $this->stock->binIdsUnder($within));
        }

        $pack = max(1, $packSize);
        $loose = max(0, $qty) % $pack;     // only on a line that already shipped part of a pack
        $whole = max(0, $qty) - $loose;
        $lines = [];
        foreach ($query->get() as $balance) {
            if ($whole + $loose <= 0) {
                break;
            }
            $free = $balance->on_hand - ($alreadyAllocated["{$balance->location_id}-{$balance->batch_key}"] ?? 0);
            if ($free <= 0) {
                continue;
            }
            $packs = intdiv(min($whole, $free), $pack) * $pack;
            $rest = min($loose, $free - $packs);
            if ($packs + $rest <= 0) {
                continue;
            }
            $lines[] = ['balance' => $balance, 'qty' => $packs + $rest];
            $whole -= $packs;
            $loose -= $rest;
        }

        return ['lines' => $lines, 'shortfall' => $whole + $loose];
    }
}
