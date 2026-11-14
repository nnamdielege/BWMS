<?php

namespace App\Services\Purchasing;

use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrderLine;
use App\Models\SalesHistory;
use App\Models\Setting;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\Orders\AvailabilityService;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reorder suggestions per product, grouped by supplier (ReorderMath does the sums).
 *
 * Demand: the last 8 weeks to yesterday (Brisbane) of what was dispatched in BWMS
 * ("bwms", from sale movements) plus imported sales from before BWMS (sales_history).
 * A day that started with nothing on hand is a stock-out and isn't counted, where the
 * ledger goes back that far. Available is sellable on hand less reservations, all sites.
 * On order is what open POs (draft, sent, part received) haven't delivered yet, so a
 * draft made from these suggestions takes them off.
 *
 * Only active, stock-tracked products sold on a channel.
 */
class ReorderPlanner
{
    public const ON_ORDER_STATUSES = [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived];

    public function __construct(private AvailabilityService $availability) {}

    /**
     * @param  array{supplier_id?: ?int, range_id?: ?int, only_needed?: bool, stockout_only?: bool}  $filters
     * @return array{groups: list<array>, settings: array, today: string}
     */
    public function build(array $filters = []): array
    {
        $settings = Setting::reorder();
        $tz = config('bwms.business_timezone');
        $today = Carbon::parse(BusinessDate::today(), $tz);
        $from = $today->copy()->subDays(ReorderMath::WINDOW_DAYS); // first day counted; the window ends yesterday

        $products = Product::query()->where('is_active', true)->where('is_stock_tracked', true)->where('sell_channels', '!=', 'none')
            ->when($filters['range_id'] ?? null, fn ($q, $r) => $q->where('range_id', $r))
            ->with(['units', 'range:id,name'])->orderBy('sku')->get();
        $ids = $products->pluck('id')->all();
        if ($ids === []) {
            return ['groups' => [], 'settings' => $settings, 'today' => $today->toDateString()];
        }

        $lastPo = $this->lastPurchase($ids);
        $suppliers = Supplier::whereIn('id', $products->pluck('supplier_id')->merge(collect($lastPo)->pluck('supplier_id'))->filter()->unique())->get()->keyBy('id');
        [$sold, $sources] = $this->sales($ids, $from, $today, $tz);
        $stockOut = $this->stockOutDays($ids, $from, $today, $tz);
        $available = $this->availability->availableMany($ids);
        $onOrder = PurchaseOrderLine::query()->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereIn('purchase_orders.status', array_map(fn ($s) => $s->value, self::ON_ORDER_STATUSES))
            ->whereIn('purchase_order_lines.product_id', $ids)->groupBy('purchase_order_lines.product_id')
            ->selectRaw('purchase_order_lines.product_id, SUM(GREATEST(0, CAST(qty_ordered_base AS SIGNED) - CAST(qty_received_base AS SIGNED))) AS qty')
            ->pluck('qty', 'product_id');

        $rows = [];
        foreach ($products as $p) {
            $supplierId = $p->supplier_id ?? ($lastPo[$p->id]['supplier_id'] ?? null);
            $supplier = $supplierId ? $suppliers[$supplierId] ?? null : null;
            if (($filters['supplier_id'] ?? null) && $supplierId !== (int) $filters['supplier_id']) {
                continue;
            }
            $row = $this->row($p, $supplier, $p->supplier_id ? 'product' : ($supplier ? 'last_po' : null), $sold[$p->id] ?? [], $stockOut[$p->id] ?? [],
                $sources[$p->id] ?? [], (int) ($available[$p->id] ?? 0), (int) ($onOrder[$p->id] ?? 0), $lastPo[$p->id] ?? null, $settings, $today, $from);
            if (($filters['only_needed'] ?? false) && $row['suggest_base'] <= 0) {
                continue;
            }
            if (($filters['stockout_only'] ?? false) && ! $row['stockout_before_delivery']) {
                continue;
            }
            $rows[] = $row;
        }

        $groups = collect($rows)->groupBy(fn ($r) => $r['supplier']['id'] ?? 0)->map(function (Collection $lines) use ($suppliers) {
            $supplier = $suppliers[$lines->first()['supplier']['id'] ?? 0] ?? null;
            $value = round($lines->sum('suggest_value'), 2);
            $min = $supplier?->min_order_value !== null ? (float) $supplier->min_order_value : null;

            return [
                'supplier' => $supplier ? ['id' => $supplier->id, 'name' => $supplier->name, 'code' => $supplier->code, 'lead_time_days' => $supplier->lead_time_days,
                    'order_days' => $supplier->orderDays(), 'review_period_days' => $supplier->review_period_days, 'moq_packs' => $supplier->moq_packs] : null,
                'min_order_value' => $min,
                'order_value' => $value,
                'meets_min' => $min === null || $value >= $min,
                'to_order' => $lines->where('suggest_base', '>', 0)->count(),
                'lines' => $lines->values()->all(),
            ];
        })->sortBy(fn ($g) => $g['supplier'] ? '0'.$g['supplier']['name'] : '1')->values()->all();

        return ['groups' => $groups, 'settings' => $settings, 'today' => $today->toDateString()];
    }

    /** Products that will run out before their next delivery could arrive. */
    public function stockOutCount(): int
    {
        return collect($this->build(['stockout_only' => true])['groups'])->sum(fn ($g) => count($g['lines']));
    }

    private function row(Product $p, ?Supplier $supplier, ?string $supplierFrom, array $daily, array $outDays, array $sources, int $available, int $onOrder, ?array $last, array $s, Carbon $today, Carbon $from): array
    {
        $series = [];
        for ($d = $from->copy(); $d->lt($today); $d->addDay()) {
            $date = $d->toDateString();
            $series[] = isset($outDays[$date]) && ! isset($daily[$date]) ? null : (int) ($daily[$date] ?? 0);
        }
        $demand = ReorderMath::demand($series);
        $avg = $demand['avg'];

        $lead = $p->lead_time_days ?? $supplier?->lead_time_days ?? $s['default_lead_days'];
        $review = ReorderMath::reviewDays($supplier?->orderDays() ?? [], $supplier?->review_period_days, $s['default_review_days'], $today);
        $cover = $lead + $review;
        $safety = ReorderMath::safety($s['safety_mode'], $avg, $demand['std'], $lead, $s['safety_days'], $s['safety_z']);
        $need = ReorderMath::need($avg, $cover, $safety, $available, $onOrder);

        $unit = $this->purchaseUnit($p);
        $pack = $p->order_multiple_base ?? $unit?->base_qty ?? 1;
        $moq = $supplier?->moq_packs;
        $suggest = ReorderMath::suggest($need, $pack, $moq);

        $costBase = $last && $last['base_qty'] > 0 ? (float) $last['unit_cost'] / $last['base_qty'] : (float) $p->avg_cost;
        $daysLeft = ReorderMath::daysLeft($available, $avg);
        $stockout = $daysLeft === null ? null : $today->copy()->addDays((int) floor($daysLeft))->toDateString();
        $nextDelivery = $today->copy()->addDays($lead)->toDateString();
        $last4 = (int) array_sum(array_slice(array_map('intval', $series), -28));
        $prev4 = (int) array_sum(array_slice(array_map('intval', $series), 0, 28));
        $packLabel = $unit && $unit->base_qty === $pack ? $unit->name : "{$pack} {$p->base_unit}";
        $f = fn (float $n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        return [
            'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'range' => $p->range?->name, 'base_unit' => $p->base_unit,
            'supplier' => $supplier ? ['id' => $supplier->id, 'from' => $supplierFrom] : null,
            'pack_base' => $pack, 'pack_label' => $packLabel, 'moq_packs' => $moq,
            'purchase_unit' => $unit ? ['id' => $unit->id, 'name' => $unit->name, 'base_qty' => $unit->base_qty] : null,
            'avg_daily' => round($avg, 3), 'lead_days' => $lead, 'review_days' => $review, 'cover_days' => $cover,
            'safety' => round($safety, 2), 'available' => $available, 'on_order' => $onOrder, 'need' => round($need, 2),
            'suggest_base' => $suggest, 'suggest_packs' => intdiv($suggest, max(1, $pack)),
            'cost_per_base' => round($costBase, 4), 'suggest_value' => round($suggest * $costBase, 2),
            'days_left' => $daysLeft === null ? null : round($daysLeft, 1), 'stockout_date' => $stockout, 'next_delivery' => $nextDelivery,
            'stockout_before_delivery' => $stockout !== null && $stockout < $nextDelivery,
            'sold_4w' => $last4, 'trend' => ReorderMath::trend($last4, $prev4),
            'sources' => $sources,
            'working' => [
                'Demand: '.$f($avg)." {$p->base_unit}/day, weighted over 8 weeks (".$demand['days'].' days counted'
                    .($demand['days'] < ReorderMath::WINDOW_DAYS ? ', '.(ReorderMath::WINDOW_DAYS - $demand['days']).' stock-out days left out' : '').')',
                'Weeks (newest first): '.implode(' · ', array_map(fn ($w) => "{$w['sold']}/{$w['days']}d ×{$w['weight']}", $demand['weeks'])),
                'Sold from: '.($sources === [] ? 'nothing in 8 weeks' : implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($sources), $sources))),
                "Cover: {$lead} days lead + {$review} days to the next order = {$cover} days",
                'Safety: '.($s['safety_mode'] === 'z' ? "z {$s['safety_z']} × std-dev ".$f($demand['std'])." × √{$lead}" : "{$s['safety_days']} days × ".$f($avg)).' = '.$f($safety),
                'Need: '.$f($avg)." × {$cover} + ".$f($safety)." − ({$available} available + {$onOrder} on order) = ".$f($need),
                'Suggest: '.($suggest > 0 ? "rounded up to {$packLabel}".($moq ? ", MOQ {$moq}" : '')." = {$suggest} {$p->base_unit}" : 'nothing needed'),
            ],
        ];
    }

    /** The pack it's bought in: the supplier pack when there's a purchasable unit that size, else the default purchase pack. */
    private function purchaseUnit(Product $p): ?ProductUnit
    {
        $buyable = $p->units->where('purchasable', true);

        return ($p->order_multiple_base ? $buyable->firstWhere('base_qty', $p->order_multiple_base) : null)
            ?? $buyable->firstWhere('default_purchase', true)
            ?? $buyable->sortByDesc('base_qty')->first();
    }

    /** Per product: the latest PO line (supplier, cost per pack, pack size), cancelled POs aside. @return array<int, array{supplier_id: int, unit_cost: string, base_qty: int}> */
    private function lastPurchase(array $ids): array
    {
        return PurchaseOrderLine::query()->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereIn('purchase_order_lines.product_id', $ids)->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->orderBy('purchase_orders.id')->orderBy('purchase_order_lines.id')
            ->get(['purchase_order_lines.product_id', 'purchase_orders.supplier_id', 'purchase_order_lines.unit_cost', 'purchase_order_lines.base_qty_per_unit'])
            ->keyBy('product_id') // the last one wins
            ->map(fn ($l) => ['supplier_id' => (int) $l->supplier_id, 'unit_cost' => (string) $l->unit_cost, 'base_qty' => (int) $l->base_qty_per_unit])
            ->all();
    }

    /**
     * Sold per product per day in the window, BWMS dispatches and imported history together,
     * and how much came from each source.
     *
     * @return array{0: array<int, array<string, int>>, 1: array<int, array<string, int>>}
     */
    private function sales(array $ids, Carbon $from, Carbon $today, string $tz): array
    {
        $sold = [];
        $sources = [];
        StockMovement::where('type', MovementType::Sale)->whereIn('product_id', $ids)
            ->whereBetween('occurred_at', [$from->copy()->utc(), $today->copy()->utc()])
            ->toBase()->orderBy('id')->get(['product_id', 'qty', 'occurred_at'])
            ->each(function ($m) use (&$sold, &$sources, $tz) {
                $date = Carbon::parse($m->occurred_at, 'UTC')->setTimezone($tz)->toDateString();
                $sold[$m->product_id][$date] = ($sold[$m->product_id][$date] ?? 0) - (int) $m->qty;
                $sources[$m->product_id]['bwms'] = ($sources[$m->product_id]['bwms'] ?? 0) - (int) $m->qty;
            });
        SalesHistory::whereIn('product_id', $ids)->where('sale_date', '>=', $from->toDateString())->where('sale_date', '<', $today->toDateString())
            ->groupBy('product_id', 'sale_date', 'source')->selectRaw('product_id, sale_date, source, SUM(qty_base) AS qty')->toBase()->get()
            ->each(function ($h) use (&$sold, &$sources) {
                $date = substr((string) $h->sale_date, 0, 10);
                $sold[$h->product_id][$date] = ($sold[$h->product_id][$date] ?? 0) + (int) $h->qty;
                $sources[$h->product_id][$h->source] = ($sources[$h->product_id][$h->source] ?? 0) + (int) $h->qty;
            });

        return [$sold, $sources];
    }

    /**
     * Days that began with nothing on hand, per product, worked back from today's on hand
     * through the ledger. Days before the product's first movement are unknown, so kept.
     *
     * @return array<int, array<string, true>>
     */
    private function stockOutDays(array $ids, Carbon $from, Carbon $today, string $tz): array
    {
        $onHand = StockBalance::whereIn('product_id', $ids)->groupBy('product_id')->selectRaw('product_id, SUM(on_hand) AS qty')->pluck('qty', 'product_id');
        $first = StockMovement::whereIn('product_id', $ids)->groupBy('product_id')->selectRaw('product_id, MIN(occurred_at) AS first')->pluck('first', 'product_id');
        $net = []; // product => date => net movement that day
        StockMovement::whereIn('product_id', $ids)->where('occurred_at', '>=', $from->copy()->utc())->toBase()
            ->get(['product_id', 'qty', 'occurred_at'])
            ->each(function ($m) use (&$net, $tz) {
                $date = Carbon::parse($m->occurred_at, 'UTC')->setTimezone($tz)->toDateString();
                $net[$m->product_id][$date] = ($net[$m->product_id][$date] ?? 0) + (int) $m->qty;
            });

        $out = [];
        foreach ($ids as $id) {
            if (! isset($first[$id])) {
                continue;
            }
            $firstDate = Carbon::parse($first[$id], 'UTC')->setTimezone($tz)->toDateString();
            $level = (int) ($onHand[$id] ?? 0); // at the end of today
            // Walk back: on hand at the start of day d = at its end − that day's movements.
            for ($d = $today->copy(); $d->gte($from); $d->subDay()) {
                $date = $d->toDateString();
                $level -= $net[$id][$date] ?? 0;
                if ($d->lt($today) && $date > $firstDate && $level <= 0) {
                    $out[$id][$date] = true;
                }
            }
        }

        return $out;
    }
}
