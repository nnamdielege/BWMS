<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\Reports\PackPricing;
use Illuminate\Support\Carbon;

/**
 * Purchasing → Cash out: supplier payments due per week for 13 weeks from this Monday, by
 * supplier, against an indicative inflow from current sales velocity. Fixed costs live in
 * Xero and aren't modelled.
 *
 * Inc GST, with ex-GST figures alongside. Payments: the PO total, each line at its own tax
 * rate (0% lines carry none). Inflow: velocity × sell price (ex GST) plus 10% on products that
 * aren't GST-free. Net = inflow − payments, on each basis. POs already marked paid (paid_at,
 * including pre-payments) are left out.
 */
class CashOutReport
{
    public const WEEKS = 13;

    public function __construct(private ReorderPlanner $planner, private PurchasingBudgetService $budgets) {}

    /**
     * @return array{weeks: list<array{start: string, end: string, payments: float, payments_ex: float, inflow: float, inflow_ex: float, net: float, net_ex: float}>,
     *               suppliers: list<array{id: int, name: string, amounts: list<float>, total: float, total_ex: float}>,
     *               overdue: array{count: int, total: float, total_ex: float}, inflow_per_week: float, inflow_per_week_ex: float, unpriced_products: int}
     */
    public function build(): array
    {
        $start = Carbon::parse($this->budgets->weekStart());
        $end = $start->copy()->addWeeks(self::WEEKS); // exclusive
        $unpaid = fn () => PurchaseOrder::whereIn('status', PurchaseOrderStatus::values(PurchaseOrderStatus::PAYABLE))->whereNull('paid_at');

        $pos = $unpaid()->with('supplier:id,name')
            ->where('payment_due_date', '>=', $start->toDateString())->where('payment_due_date', '<', $end->toDateString())
            ->get(['id', 'supplier_id', 'payment_due_date', 'subtotal', 'total']);

        $bySupplier = [];
        $weekly = array_fill(0, self::WEEKS, 0.0);
        $weeklyEx = array_fill(0, self::WEEKS, 0.0);
        foreach ($pos as $po) {
            $w = intdiv((int) $start->diffInDays($po->payment_due_date), 7);
            $bySupplier[$po->supplier_id] ??= ['id' => $po->supplier_id, 'name' => $po->supplier->name, 'amounts' => array_fill(0, self::WEEKS, 0.0), 'total' => 0.0, 'total_ex' => 0.0];
            $bySupplier[$po->supplier_id]['amounts'][$w] += (float) $po->total;
            $bySupplier[$po->supplier_id]['total'] += (float) $po->total;
            $bySupplier[$po->supplier_id]['total_ex'] += (float) $po->subtotal;
            $weekly[$w] += (float) $po->total;
            $weeklyEx[$w] += (float) $po->subtotal;
        }

        [$inflow, $inflowEx, $unpriced] = $this->weeklyInflow();
        $weeks = [];
        for ($w = 0; $w < self::WEEKS; $w++) {
            $from = $start->copy()->addWeeks($w);
            $weeks[] = [
                'start' => $from->toDateString(),
                'end' => $from->copy()->addDays(6)->toDateString(),
                'payments' => round($weekly[$w], 2),
                'payments_ex' => round($weeklyEx[$w], 2),
                'inflow' => $inflow,
                'inflow_ex' => $inflowEx,
                'net' => round($inflow - $weekly[$w], 2),
                'net_ex' => round($inflowEx - $weeklyEx[$w], 2),
            ];
        }

        $overdue = $unpaid()->where('payment_due_date', '<', $start->toDateString());

        return [
            'weeks' => $weeks,
            'suppliers' => collect($bySupplier)->map(fn ($s) => ['amounts' => array_map(fn ($a) => round($a, 2), $s['amounts']), 'total' => round($s['total'], 2), 'total_ex' => round($s['total_ex'], 2)] + $s)
                ->sortByDesc('total')->values()->all(),
            'overdue' => ['count' => (clone $overdue)->count(), 'total' => round((float) (clone $overdue)->sum('total'), 2), 'total_ex' => round((float) $overdue->sum('subtotal'), 2)],
            'inflow_per_week' => $inflow,
            'inflow_per_week_ex' => $inflowEx,
            'unpriced_products' => $unpriced,
        ];
    }

    /**
     * Indicative sales a week: velocity × 7 × sell price per base unit (ex GST), over active products
     * sold on a channel, and the same plus GST on products that aren't GST-free. Products selling
     * with no sell price are counted, not guessed.
     *
     * @return array{0: float, 1: float, 2: int} inc GST, ex GST, products without a price
     */
    private function weeklyInflow(): array
    {
        $products = Product::with(['units', 'channelListings'])->where('is_active', true)->where('sell_channels', '!=', 'none')->get();
        $velocity = $this->planner->dailyVelocity($products->pluck('id')->all());
        $inc = 0.0;
        $ex = 0.0;
        $unpriced = 0;
        foreach ($products as $p) {
            $v = $velocity[$p->id] ?? 0.0;
            if ($v <= 0) {
                continue;
            }
            $price = PackPricing::sellFor($p, 1);
            if ($price === null) {
                $unpriced++;

                continue;
            }
            $sales = $v * 7 * $price;
            $ex += $sales;
            $inc += $p->gst_free ? $sales : $sales * (1 + Product::GST_RATE / 100);
        }

        return [round($inc, 2), round($ex, 2), $unpriced];
    }
}
