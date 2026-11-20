<?php

namespace App\Services\Reports\Insights;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reports → Stock received: what came in on receipts in the dates, by SKU, supplier and PO,
 * at the PO cost (ex GST), with damaged and rejected stock at receipt, short/over against
 * the PO, and how each supplier performed.
 *
 * Supplier here is the PO's supplier. Quantities are base units; packs are the PO's packs.
 * Short/over is per PO line over its whole life: over = received beyond ordered; short =
 * ordered not received, only once the PO is finished (received, paid or closed). Until then
 * the rest is "outstanding".
 *
 * Supplier performance takes each PO whose first receipt falls in the dates:
 * - on time: first receipt on or before the expected date (POs with no expected date don't count);
 * - lead time: days from sent (else order date) to the first receipt, against the supplier's set lead time;
 * - fill rate: good stock received (accepted, at most what was ordered per line) ÷ ordered, finished POs only.
 */
class StockReceivedReport
{
    public const GROUPS = ['sku', 'supplier', 'po'];

    /** POs that won't receive any more. */
    public const FINISHED = [PurchaseOrderStatus::Received, PurchaseOrderStatus::Paid, PurchaseOrderStatus::Closed];

    public function build(InsightFilters $f): array
    {
        $lines = $this->lines($f);
        $poLines = $this->poLines($lines->pluck('po_line_id')->unique()->values()->all());

        return [
            'lines' => $lines->all(),
            'totals' => $this->totals($lines, $poLines),
            'groups' => [
                'sku' => $this->group($lines, $poLines, 'product_id', fn ($l) => trim("{$l->sku} {$l->name}")),
                'supplier' => $this->group($lines, $poLines, 'supplier_id', fn ($l) => $l->supplier),
                'po' => $this->group($lines, $poLines, 'po_id', fn ($l) => "{$l->po_number} · {$l->supplier}"),
            ],
            'suppliers' => $this->performance($f),
        ];
    }

    /** One row per receipt line in the dates. */
    private function lines(InsightFilters $f): Collection
    {
        [$start, $end] = $f->window();
        $tz = config('bwms.business_timezone');

        return DB::table('receipt_lines as rl')
            ->join('receipts as r', 'r.id', '=', 'rl.receipt_id')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'rl.purchase_order_line_id')
            ->join('purchase_orders as po', 'po.id', '=', 'pol.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->join('products as p', 'p.id', '=', 'rl.product_id')
            ->leftJoin('product_units as pu', 'pu.id', '=', 'pol.product_unit_id')
            ->whereBetween('r.received_at', [$start, $end])
            ->when($f->siteId, fn ($q, $id) => $q->where('r.site_id', $id))
            ->when($f->supplierId, fn ($q, $id) => $q->where('po.supplier_id', $id))
            ->when($f->rangeId, fn ($q, $id) => $q->where('p.range_id', $id))
            ->when($f->productId, fn ($q, $id) => $q->where('p.id', $id))
            ->orderBy('r.received_at')->orderBy('rl.id')
            ->get([
                'rl.id', 'rl.receipt_id', 'r.number as receipt', 'r.received_at', 'rl.purchase_order_line_id as po_line_id',
                'po.id as po_id', 'po.number as po_number', 'po.supplier_id', 's.name as supplier',
                'p.id as product_id', 'p.sku', 'p.name', 'p.base_unit', 'pu.name as pack', 'pol.base_qty_per_unit', 'pol.unit_cost',
                'rl.qty_accepted_base', 'rl.qty_damaged_base', 'rl.qty_rejected_base', 'rl.discrepancy_reason',
            ])
            ->map(function ($l) use ($tz) {
                $received = (int) $l->qty_accepted_base + (int) $l->qty_damaged_base;
                $perPack = max(1, (int) $l->base_qty_per_unit);
                $l->date = Carbon::parse($l->received_at, 'UTC')->setTimezone($tz)->toDateString();
                $l->units = $received;
                $l->accepted = (int) $l->qty_accepted_base;
                $l->damaged = (int) $l->qty_damaged_base;
                $l->rejected = (int) $l->qty_rejected_base;
                $l->packs = round($received / $perPack, 2);
                $l->cost = round($received / $perPack * (float) $l->unit_cost, 2);

                return $l;
            });
    }

    /** Each PO line's lifetime ordered, received and status, keyed by id. */
    private function poLines(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $finished = PurchaseOrderStatus::values(self::FINISHED);

        return DB::table('purchase_order_lines as pol')->join('purchase_orders as po', 'po.id', '=', 'pol.purchase_order_id')
            ->whereIn('pol.id', $ids)
            ->get(['pol.id', 'pol.qty_ordered_base', 'pol.qty_received_base', 'po.status'])
            ->map(function ($l) use ($finished) {
                $ordered = (int) $l->qty_ordered_base;
                $received = (int) $l->qty_received_base;
                $done = in_array($l->status, $finished, true);

                return (object) [
                    'id' => $l->id,
                    'ordered' => $ordered,
                    'over' => max(0, $received - $ordered),
                    'short' => $done ? max(0, $ordered - $received) : 0,
                    'outstanding' => $done ? 0 : max(0, $ordered - $received),
                ];
            })->keyBy('id');
    }

    private function totals(Collection $lines, Collection $poLines): array
    {
        return [
            'units' => (int) $lines->sum('units'),
            'cost' => round($lines->sum('cost'), 2),
            'receipts' => $lines->pluck('receipt_id')->unique()->count(),
            'lines' => $lines->count(),
            'damaged' => (int) $lines->sum('damaged'),
            'rejected' => (int) $lines->sum('rejected'),
            'damaged_cost' => round($lines->sum(fn ($l) => $l->damaged / max(1, (int) $l->base_qty_per_unit) * (float) $l->unit_cost), 2),
            'over' => (int) $poLines->sum('over'),
            'short' => (int) $poLines->sum('short'),
            'outstanding' => (int) $poLines->sum('outstanding'),
        ];
    }

    /** @return list<array> biggest cost first */
    private function group(Collection $lines, Collection $poLines, string $key, callable $label): array
    {
        return $lines->groupBy($key)->map(function (Collection $g, $id) use ($poLines, $label) {
            $pl = $poLines->only($g->pluck('po_line_id')->unique()->all());
            $first = $g->first();

            return [
                'key' => (string) $id,
                'label' => $label($first),
                'product_id' => $first->product_id,
                'po_id' => $first->po_id,
                'base_unit' => $g->pluck('base_unit')->unique()->count() === 1 ? $first->base_unit : null,
                'pack' => $g->pluck('pack')->unique()->count() === 1 ? $first->pack : null,
                'packs' => round($g->sum('packs'), 2),
                'units' => (int) $g->sum('units'),
                'cost' => round($g->sum('cost'), 2),
                'receipts' => $g->pluck('receipt_id')->unique()->count(),
                'damaged' => (int) $g->sum('damaged'),
                'rejected' => (int) $g->sum('rejected'),
                'ordered' => (int) $pl->sum('ordered'),
                'over' => (int) $pl->sum('over'),
                'short' => (int) $pl->sum('short'),
                'outstanding' => (int) $pl->sum('outstanding'),
            ];
        })->sortBy([['cost', 'desc'], ['label', 'asc']])->values()->all();
    }

    /** @return list<array> one row per supplier, POs whose first receipt is in the dates */
    private function performance(InsightFilters $f): array
    {
        [$start, $end] = $f->window();
        $tz = config('bwms.business_timezone');

        $first = DB::table('receipts')->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, MIN(received_at) as first_at');
        $pos = DB::table('purchase_orders as po')
            ->joinSub($first, 'fr', 'fr.purchase_order_id', '=', 'po.id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->whereBetween('fr.first_at', [$start, $end])
            ->when($f->siteId, fn ($q, $id) => $q->where('po.site_id', $id))
            ->when($f->supplierId, fn ($q, $id) => $q->where('po.supplier_id', $id))
            ->when($f->rangeId || $f->productId, fn ($q) => $q->whereExists(fn ($e) => $e->from('purchase_order_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
                ->whereColumn('l.purchase_order_id', 'po.id')
                ->when($f->rangeId, fn ($x, $id) => $x->where('p.range_id', $id))
                ->when($f->productId, fn ($x, $id) => $x->where('p.id', $id))))
            ->get(['po.id', 'po.number', 'po.supplier_id', 's.name as supplier', 's.lead_time_days', 'po.status', 'po.order_date', 'po.sent_at', 'po.expected_date', 'fr.first_at']);

        // Good stock per PO line: accepted, at most what was ordered.
        $fill = DB::table('purchase_order_lines as pol')
            ->leftJoinSub(DB::table('receipt_lines')->groupBy('purchase_order_line_id')->selectRaw('purchase_order_line_id, SUM(qty_accepted_base) as accepted'), 'a', 'a.purchase_order_line_id', '=', 'pol.id')
            ->whereIn('pol.purchase_order_id', $pos->pluck('id'))
            ->get(['pol.purchase_order_id', 'pol.qty_ordered_base', 'a.accepted'])
            ->groupBy('purchase_order_id')
            ->map(fn (Collection $ls) => [
                'ordered' => (int) $ls->sum('qty_ordered_base'),
                'good' => (int) $ls->sum(fn ($l) => min((int) $l->qty_ordered_base, (int) $l->accepted)),
            ]);
        $finished = PurchaseOrderStatus::values(self::FINISHED);

        return $pos->groupBy('supplier_id')->map(function (Collection $g) use ($tz, $fill, $finished) {
            $rows = $g->map(function ($po) use ($tz) {
                // Whole Brisbane dates on both sides, so the clock time of day never counts.
                $date = fn (?string $utc) => Carbon::parse(Carbon::parse($utc, 'UTC')->setTimezone($tz)->toDateString());
                $firstDay = $date($po->first_at);
                $sent = $po->sent_at ? $date($po->sent_at) : Carbon::parse(substr((string) $po->order_date, 0, 10));
                $expected = $po->expected_date ? Carbon::parse(substr((string) $po->expected_date, 0, 10)) : null;

                return [
                    'on_time' => $expected ? $firstDay->lte($expected) : null,
                    'lead' => (int) $sent->diffInDays($firstDay),
                    'late_days' => $expected ? max(0, (int) $expected->diffInDays($firstDay, false)) : null,
                ];
            });
            $dated = $rows->whereNotNull('on_time');
            $done = $g->filter(fn ($po) => in_array($po->status, $finished, true));
            $ordered = $done->sum(fn ($po) => $fill[$po->id]['ordered'] ?? 0);
            $good = $done->sum(fn ($po) => $fill[$po->id]['good'] ?? 0);

            return [
                'supplier_id' => $g->first()->supplier_id,
                'supplier' => $g->first()->supplier,
                'pos' => $g->count(),
                'po_numbers' => $g->pluck('number')->all(),
                'dated' => $dated->count(),
                'on_time' => $dated->where('on_time', true)->count(),
                'on_time_percent' => $dated->count() ? round($dated->where('on_time', true)->count() / $dated->count() * 100, 1) : null,
                'avg_days_late' => $dated->count() ? round($dated->avg('late_days'), 1) : null,
                'lead_actual' => round($rows->avg('lead'), 1),
                'lead_set' => $g->first()->lead_time_days !== null ? (int) $g->first()->lead_time_days : null,
                'finished' => $done->count(),
                'fill_percent' => $ordered > 0 ? round($good / $ordered * 100, 1) : null,
            ];
        })->sortBy('supplier')->values()->all();
    }

    /** @return array{filename: string, type: string, body: string} */
    public function export(array $r, InsightFilters $f, string $groupBy, string $format): array
    {
        $t = $r['totals'];
        $label = ['sku' => 'SKU', 'supplier' => 'Supplier', 'po' => 'PO'][$groupBy];

        return InsightExport::make('Stock received', $f, [
            'Money ex GST at the PO cost. Quantities in base units; packs are the PO\'s packs. Supplier = the PO\'s supplier.',
            "Received {$t['units']} units costing {$t['cost']} on {$t['receipts']} receipt(s). Damaged {$t['damaged']}, rejected {$t['rejected']}, over {$t['over']}, short {$t['short']}, outstanding {$t['outstanding']}.",
        ], [
            [
                'title' => "By {$label}",
                'columns' => [$label, 'Packs', 'Units', 'Cost', 'Receipts', 'Damaged', 'Rejected', 'Ordered', 'Over', 'Short', 'Outstanding'],
                'rows' => array_map(fn ($g) => [$g['label'], $g['packs'], $g['units'], $g['cost'], $g['receipts'], $g['damaged'], $g['rejected'],
                    $g['ordered'], $g['over'], $g['short'], $g['outstanding']], $r['groups'][$groupBy]),
                'money' => [3],
            ],
            [
                'title' => 'Supplier performance (POs first received in these dates)',
                'columns' => ['Supplier', 'POs', 'On time', 'With expected date', 'On time %', 'Avg days late', 'Lead time actual', 'Lead time set', 'Finished POs', 'Fill rate %'],
                'rows' => array_map(fn ($s) => [$s['supplier'], $s['pos'], $s['on_time'], $s['dated'], $s['on_time_percent'], $s['avg_days_late'],
                    $s['lead_actual'], $s['lead_set'], $s['finished'], $s['fill_percent']], $r['suppliers']),
            ],
            [
                'title' => 'Receipt lines',
                'columns' => ['Date', 'Receipt', 'PO', 'Supplier', 'SKU', 'Product', 'Pack', 'Packs', 'Accepted', 'Damaged', 'Rejected', 'Reason', 'Cost / pack', 'Cost'],
                'rows' => array_map(fn ($l) => [$l->date, $l->receipt, $l->po_number, $l->supplier, $l->sku, $l->name, $l->pack, $l->packs,
                    $l->accepted, $l->damaged, $l->rejected, $l->discrepancy_reason, (float) $l->unit_cost, $l->cost], $r['lines']),
                'money' => [12, 13],
            ],
        ], $format);
    }
}
