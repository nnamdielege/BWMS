<?php

namespace App\Services\Reports;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Orders\AvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stock value at cost and at sell, per product, grouped by range.
 *
 * Quantities come from the ledger: all on hand, or available (sellable bins less
 * reservations). A product missing a cost or a sell price is listed and flagged but
 * left out of every total, so one gap can't make the totals or the margin look better
 * or worse than they are.
 */
class StockValueReport
{
    public const ON_HAND = 'on_hand';

    public const AVAILABLE = 'available';

    public function __construct(private AvailabilityService $availability) {}

    /** Stock-tracked products worth showing, with what PackPricing needs loaded. */
    public static function products(bool $sellableOnly): Collection
    {
        return Product::query()
            ->with(['units', 'range:id,name', 'channelListings' => fn ($q) => $q->where('channel', 'ordermentum')])
            ->where('is_stock_tracked', true)
            ->when($sellableOnly, fn ($q) => $q->where('is_active', true)->where('sell_channels', '!=', 'none'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{rows: list<array>, groups: list<array>, totals: array, excluded: array}
     */
    public function build(string $basis = self::ON_HAND, bool $sellableOnly = false): array
    {
        $products = self::products($sellableOnly);
        $ids = $products->pluck('id')->all();
        $onHand = StockBalance::whereIn('product_id', $ids)->groupBy('product_id')
            ->selectRaw('product_id, SUM(on_hand) as qty')->pluck('qty', 'product_id');
        $available = $ids === [] ? [] : $this->availability->availableMany($ids);
        $received = StockMovement::where('type', MovementType::Receipt)->whereIn('product_id', $ids)
            ->groupBy('product_id')->selectRaw('product_id, MAX(occurred_at) as at')->pluck('at', 'product_id');

        $rows = $products
            // An inactive product only matters while it still holds stock.
            ->filter(fn (Product $p) => $p->is_active || (int) ($onHand[$p->id] ?? 0) !== 0)
            ->map(fn (Product $p) => $this->row(
                $p,
                $basis === self::AVAILABLE ? (int) ($available[$p->id] ?? 0) : (int) ($onHand[$p->id] ?? 0),
                (int) ($available[$p->id] ?? 0),
                $received[$p->id] ?? null,
            ))
            ->values();

        $groups = $rows->groupBy('range')
            ->sortKeys()
            ->sortBy(fn ($rows, $range) => $range === '' ? 1 : 0) // "No range" last
            ->map(fn (Collection $rows, $range) => ['range' => $range === '' ? 'No range' : $range, 'rows' => $rows->values()->all(), 'totals' => self::totals($rows)])
            ->values()->all();

        $missing = $rows->filter(fn ($r) => $r['missing'] !== []);

        return [
            'rows' => $rows->all(),
            'groups' => $groups,
            'totals' => self::totals($rows),
            'excluded' => [
                'count' => $missing->count(),
                'with_stock' => $missing->where('qty_base', '!=', 0)->count(),
                'no_cost' => $missing->filter(fn ($r) => in_array('cost', $r['missing'], true))->count(),
                'no_sell' => $missing->filter(fn ($r) => in_array('sell', $r['missing'], true))->count(),
            ],
        ];
    }

    private function row(Product $p, int $qtyBase, int $availableBase, ?string $lastReceived): array
    {
        $price = PackPricing::for($p);
        $missing = array_values(array_filter([$price->costPerPack === null ? 'cost' : null, $price->sellPerPack === null ? 'sell' : null]));
        $valueCost = $price->costPerPack === null ? null : round($qtyBase * (float) $p->avg_cost, 2);
        $valueSell = $price->sellPerPack === null ? null : round($qtyBase / $price->packQty * $price->sellPerPack, 2);

        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'range' => $p->range?->name ?? '',
            'is_active' => $p->is_active,
            'pack' => $price->packName,
            'pack_qty' => $price->packQty,
            'base_unit' => $p->base_unit,
            'qty_base' => $qtyBase,
            'packs' => round($qtyBase / $price->packQty, 2),
            'cost_per_pack' => $price->costPerPack === null ? null : round($price->costPerPack, 4),
            'sell_per_pack' => $price->sellPerPack === null ? null : round($price->sellPerPack, 2),
            'sell_source' => $price->sellSource,
            'value_cost' => $valueCost,
            'value_sell' => $valueSell,
            'gm_percent' => MarginMath::gmPercent($price->sellPerPack, $price->costPerPack),
            'reorder_packs' => self::reorderPacks($p, $availableBase, $price->packQty),
            'last_received' => $lastReceived
                ? Carbon::parse($lastReceived, config('app.timezone'))->setTimezone(config('bwms.business_timezone'))->toDateString()
                : null,
            'missing' => $missing,
        ];
    }

    /**
     * At or under the reorder point (on available stock): reorder_qty, or enough to get back
     * to the point when no quantity is set. In whole packs, rounded up. Null when not due.
     */
    public static function reorderPacks(Product $p, int $availableBase, int $packQty): ?int
    {
        if ($p->reorder_point === null || $availableBase > $p->reorder_point) {
            return null;
        }
        $base = $p->reorder_qty ?: max(0, $p->reorder_point - $availableBase);

        return $base > 0 ? (int) ceil($base / max(1, $packQty)) : null;
    }

    /** Over rows with both a cost and a sell price only. */
    private static function totals(Collection $rows): array
    {
        $counted = $rows->filter(fn ($r) => $r['missing'] === []);
        $cost = round($counted->sum('value_cost'), 2);
        $sell = round($counted->sum('value_sell'), 2);

        return [
            'value_cost' => $cost,
            'value_sell' => $sell,
            'gp' => round($sell - $cost, 2),
            'gm_percent' => MarginMath::weightedGmPercent($sell, $cost),
            'products' => $rows->count(),
            'excluded' => $rows->count() - $counted->count(),
        ];
    }
}
