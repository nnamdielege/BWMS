<?php

namespace App\Services\Reports;

use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Collection;

/**
 * Is a delivery worth making? Two views.
 *
 * Modelled: a drop of N packs of one product, against the delivery cost per drop.
 * Real: each dispatched order delivered in a date range is one drop. Sales are the
 * dispatched packs at the order line's price (the default/Ordermentum price when the
 * line has none). Cost of goods is what each sale movement was posted at, or today's
 * avg_cost for movements posted before dispatch recorded it.
 */
class DropReport
{
    /** @return array{rows: list<array>, missing: list<array>} */
    public function modelled(int $units, float $deliveryCost, bool $sellableOnly = true): array
    {
        $rows = [];
        $missing = [];
        foreach (StockValueReport::products($sellableOnly)->where('is_active', true) as $p) {
            $price = PackPricing::for($p);
            $base = ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'range' => $p->range?->name ?? 'No range', 'pack' => $price->packName];
            if ($price->costPerPack === null || $price->sellPerPack === null) {
                $missing[] = $base + ['missing' => array_values(array_filter([$price->costPerPack === null ? 'cost' : null, $price->sellPerPack === null ? 'sell' : null]))];

                continue;
            }
            $rows[] = $base + [
                'sell_per_pack' => round($price->sellPerPack, 2),
                'cost_per_pack' => round($price->costPerPack, 4),
                'sell_source' => $price->sellSource,
            ] + MarginMath::drop($units, $price->sellPerPack, $price->costPerPack, $deliveryCost);
        }

        return ['rows' => $rows, 'missing' => $missing];
    }

    /**
     * @return array{orders: list<array>, areas: list<array>, totals: array}
     */
    public function real(string $from, string $to, float $deliveryCost, float $freeDeliveryMin): array
    {
        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Dispatched, OrderStatus::PartiallyDispatched])
            ->whereBetween('delivery_date', [$from, $to])
            ->with(['customer:id,name', 'deliveryRun.area:id,name', 'lines.product.units', 'lines.product.channelListings' => fn ($q) => $q->where('channel', 'ordermentum')])
            ->orderBy('delivery_date')->orderBy('number')
            ->get();

        $movements = StockMovement::where('type', MovementType::Sale)->where('source_type', 'order')
            ->whereIn('source_id', $orders->pluck('id'))
            ->get(['source_id', 'product_id', 'qty', 'unit_cost'])
            ->groupBy('source_id');

        $rows = $orders->map(fn (Order $o) => $this->orderRow($o, $movements[$o->id] ?? collect(), $deliveryCost, $freeDeliveryMin))->values();

        $areas = $rows->groupBy('area')->sortKeys()
            ->map(fn (Collection $rows, $area) => ['area' => $area] + self::summary($rows))
            ->values()->all();

        return ['orders' => $rows->all(), 'areas' => $areas, 'totals' => self::summary($rows)];
    }

    private function orderRow(Order $o, Collection $movements, float $deliveryCost, float $freeDeliveryMin): array
    {
        $products = $o->lines->pluck('product')->keyBy('id');

        $sales = 0.0;
        $priceEstimated = false;
        foreach ($o->lines->where('qty_dispatched_base', '>', 0) as $line) {
            /** @var OrderLine $line */
            $price = $line->unit_price !== null ? (float) $line->unit_price : PackPricing::sellFor($line->product, $line->base_qty_per_unit);
            $priceEstimated = $priceEstimated || $line->unit_price === null;
            $sales += $line->qty_dispatched_base / max(1, $line->base_qty_per_unit) * ($price ?? 0);
        }

        $cost = 0.0;
        $atCurrent = 0;
        foreach ($movements as $m) {
            $atCurrent += $m->unit_cost === null ? 1 : 0;
            $cost += -$m->qty * (float) ($m->unit_cost ?? $products->get($m->product_id)?->avg_cost ?? 0);
        }
        // Non-stock lines (services) leave no movement: at today's cost, usually nothing.
        foreach ($o->lines->filter(fn (OrderLine $l) => ! $l->product->is_stock_tracked && $l->qty_dispatched_base > 0) as $line) {
            $cost += $line->qty_dispatched_base * (float) $line->product->avg_cost;
        }

        $sales = round($sales, 2);
        $cost = round($cost, 2);
        $gp = round($sales - $cost, 2);

        return [
            'id' => $o->id,
            'number' => $o->number,
            'delivery_date' => $o->delivery_date?->toDateString(),
            'customer' => $o->customer?->name,
            'area' => $o->deliveryRun?->area?->name ?? 'Not on a run',
            'run' => $o->deliveryRun ? $o->deliveryRun->run_date->format('D j M').' · '.($o->deliveryRun->area?->name ?? '') : null,
            'status' => $o->status->value,
            'sales' => $sales,
            'cost' => $cost,
            'gp' => $gp,
            'delivery_cost' => round($deliveryCost, 2),
            'net' => round($gp - $deliveryCost, 2),
            'under_minimum' => $sales < $freeDeliveryMin,
            'cost_basis' => match (true) {
                $movements->isEmpty() || $atCurrent === 0 => 'dispatch',
                $atCurrent === $movements->count() => 'current',
                default => 'mixed',
            },
            'price_estimated' => $priceEstimated,
        ];
    }

    private static function summary(Collection $rows): array
    {
        $drops = $rows->count();
        $net = round($rows->sum('net'), 2);

        return [
            'drops' => $drops,
            'sales' => round($rows->sum('sales'), 2),
            'cost' => round($rows->sum('cost'), 2),
            'gp' => round($rows->sum('gp'), 2),
            'delivery_cost' => round($rows->sum('delivery_cost'), 2),
            'net' => $net,
            'avg_net' => $drops ? round($net / $drops, 2) : null,
            'under_minimum' => $rows->where('under_minimum', true)->count(),
        ];
    }
}
