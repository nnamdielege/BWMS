<?php

namespace App\Services\Stock;

use App\Models\Batch;
use App\Models\Location;
use App\Models\Product;
use App\Models\QuarantineEntry;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;

/**
 * Everything on hand in quarantine bins, one line per product × bin × batch, valued at
 * today's average cost (ex GST).
 *
 * "In quarantine since" is when the line last went from nothing to something, read
 * from the ledger, so stock that arrived in several moves counts from the first of
 * them. The reason is the latest one recorded since then (moving stock in asks for it;
 * damaged on receipt records "Damaged").
 */
class QuarantineRegister
{
    /** @return array{rows: list<array>, totals: array{lines: int, units: int, value: float}} */
    public function build(): array
    {
        $tz = config('bwms.business_timezone');
        $today = Carbon::parse(BusinessDate::today(), $tz);
        $binIds = Location::bins()->where('is_quarantine', true)->pluck('id');

        $balances = StockBalance::whereIn('location_id', $binIds)->where('on_hand', '>', 0)
            ->with(['location.parent.parent'])->orderBy('location_id')->orderBy('product_id')->orderBy('batch_key')->get();
        if ($balances->isEmpty()) {
            return ['rows' => [], 'totals' => ['lines' => 0, 'units' => 0, 'value' => 0.0]];
        }

        $products = Product::withTrashed()->with('supplier:id,name')->whereIn('id', $balances->pluck('product_id')->unique())->get()->keyBy('id');
        $batches = Batch::whereIn('id', $balances->pluck('batch_id')->filter()->unique())->get()->keyBy('id');
        $batchSuppliers = Supplier::whereIn('id', $batches->pluck('supplier_id')->filter()->unique())->get(['id', 'name'])->keyBy('id');
        $since = $this->since($balances->pluck('location_id')->unique()->all(), $balances->pluck('product_id')->unique()->all());
        $entries = QuarantineEntry::whereIn('location_id', $balances->pluck('location_id')->unique())
            ->whereIn('product_id', $balances->pluck('product_id')->unique())
            ->orderBy('id')->get()->groupBy(fn ($e) => self::slot($e->product_id, $e->location_id, $e->batch_key));

        $rows = $balances->map(function (StockBalance $b) use ($products, $batches, $batchSuppliers, $since, $entries, $today, $tz) {
            $slot = self::slot($b->product_id, $b->location_id, $b->batch_key);
            $product = $products[$b->product_id];
            $batch = $b->batch_id ? $batches[$b->batch_id] ?? null : null;
            $from = $since[$slot] ?? null;
            // The latest reason given since the line was last empty.
            $entry = ($entries[$slot] ?? collect())->filter(fn ($e) => $from === null || $e->created_at->gte($from->copy()->subMinute()))->last();
            $supplier = $batch?->supplier_id ? $batchSuppliers[$batch->supplier_id] ?? null : $product->supplier;
            $cost = (float) $product->avg_cost;

            return [
                'key' => $slot,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'base_unit' => $product->base_unit,
                'location_id' => $b->location_id,
                'bin' => $b->location->path(),
                'batch_id' => $b->batch_id,
                'batch' => $batch?->lot_code,
                'best_before' => $batch?->best_before?->toDateString(),
                'expired' => (bool) $batch?->isExpired(),
                'qty' => (int) $b->on_hand,
                'unit_cost' => $cost > 0 ? $cost : null,
                'value' => round($b->on_hand * $cost, 2),
                'since' => $from?->setTimezone($tz)->toDateString(),
                'days' => $from ? (int) Carbon::parse($from->setTimezone($tz)->toDateString(), $tz)->diffInDays($today) : null,
                'reason' => QuarantineEntry::label($entry?->reason),
                'reason_note' => $entry?->note,
                'supplier_id' => $supplier?->id,
                'supplier' => $supplier?->name,
            ];
        })->sortByDesc('days')->values()->all();

        return ['rows' => $rows, 'totals' => [
            'lines' => count($rows),
            'units' => array_sum(array_column($rows, 'qty')),
            'value' => round(array_sum(array_column($rows, 'value')), 2),
        ]];
    }

    /**
     * Per slot: the first movement after the running balance was last at or below zero.
     *
     * @param  list<int>  $locationIds
     * @param  list<int>  $productIds
     * @return array<string, Carbon>
     */
    private function since(array $locationIds, array $productIds): array
    {
        $since = [];
        $running = [];
        StockMovement::whereIn('location_id', $locationIds)->whereIn('product_id', $productIds)
            ->orderBy('occurred_at')->orderBy('id')
            ->select(['id', 'product_id', 'location_id', 'batch_id', 'qty', 'occurred_at'])
            ->cursor()
            ->each(function (StockMovement $m) use (&$since, &$running) {
                $slot = self::slot($m->product_id, $m->location_id, $m->batch_id ?? 0);
                $before = $running[$slot] ?? 0;
                $running[$slot] = $before + $m->qty;
                if ($before <= 0 && $running[$slot] > 0) {
                    $since[$slot] = $m->occurred_at;
                }
            });

        return $since;
    }

    public static function slot(int $productId, int $locationId, int $batchKey): string
    {
        return "{$productId}-{$locationId}-{$batchKey}";
    }
}
