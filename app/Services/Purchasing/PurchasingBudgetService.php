<?php

namespace App\Services\Purchasing;

use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchasingBudget;
use App\Models\StockMovement;
use App\Services\Reports\PackPricing;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * The weekly purchasing budget (Brisbane Mon–Sun, ex GST) and what's committed against it.
 *
 * Committed: the subtotal (ex GST) of POs approved in the week that are still going ahead
 * (approved, sent, part or fully received, paid). Ex GST throughout, like the budget estimate.
 */
class PurchasingBudgetService
{
    /** A new week's budget is pre-filled with this share of last week's dispatched sales. */
    public const ESTIMATE_SHARE = 0.74;

    /** The Monday (Y-m-d, Brisbane) of the week a date falls in; today's week by default. */
    public function weekStart(?string $date = null): string
    {
        return Carbon::parse($date ?? BusinessDate::today(), config('bwms.business_timezone'))->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public const AUTO_NOTE = 'auto-estimate – check against Xero';

    public function budgetFor(string $weekStart): ?PurchasingBudget
    {
        return PurchasingBudget::whereDate('week_start', $weekStart)->first();
    }

    /**
     * This week's budget from the 74% estimate, when the week has none yet (the suggestion run calls
     * this). Null when it already had one. Weeks still without a row stay "over budget" for approval.
     */
    public function ensureCurrentWeek(): ?PurchasingBudget
    {
        $week = $this->weekStart();
        if ($this->budgetFor($week)) {
            return null;
        }
        $estimate = $this->estimate($week);
        try {
            $budget = PurchasingBudget::create(['week_start' => $week, 'budget' => $estimate['amount'], 'notes' => self::AUTO_NOTE]);
        } catch (UniqueConstraintViolationException) {
            return null; // created meanwhile by someone else
        }
        Audit::record('purchasing_budget_auto_created', $budget, new: ['week_start' => $week, 'budget' => (string) $estimate['amount'], 'sales' => $estimate['sales']]);

        return $budget;
    }

    /** Ex GST subtotal of POs approved in the week and still committed. */
    public function committed(string $weekStart): float
    {
        [$from, $to] = $this->utcRange($weekStart);

        return round((float) PurchaseOrder::whereIn('status', PurchaseOrderStatus::values(PurchaseOrderStatus::COMMITTED))
            ->where('approved_at', '>=', $from)->where('approved_at', '<', $to)->sum('subtotal'), 2);
    }

    /**
     * Remaining is budget − committed; with no budget set it's −committed (nothing is budgeted).
     *
     * @return array{week_start: string, week_end: string, budget: ?float, committed: float, remaining: float}
     */
    public function summary(?string $weekStart = null): array
    {
        $weekStart ??= $this->weekStart();
        $budget = $this->budgetFor($weekStart);
        $committed = $this->committed($weekStart);
        $amount = $budget ? (float) $budget->budget : null;

        return [
            'week_start' => $weekStart,
            'week_end' => Carbon::parse($weekStart)->addDays(6)->toDateString(),
            'budget' => $amount,
            'committed' => $committed,
            'remaining' => round(($amount ?? 0) - $committed, 2),
        ];
    }

    /**
     * Whether approving this PO now would go over this week's budget (no budget set counts as over).
     *
     * @return array{week_start: string, week_end: string, budget: ?float, committed: float, remaining: float, po_total: float, over: bool}
     */
    public function check(PurchaseOrder $po): array
    {
        $summary = $this->summary();
        $total = round((float) $po->subtotal, 2);

        return $summary + ['po_total' => $total, 'over' => $summary['budget'] === null || $total > $summary['remaining']];
    }

    /**
     * The suggested budget for a week: 74% of the previous week's dispatched sales, ex GST.
     *
     * @return array{amount: float, sales: float, from: string, to: string}
     */
    public function estimate(string $weekStart): array
    {
        $prev = Carbon::parse($weekStart)->subWeek()->toDateString();
        $sales = $this->dispatchedValue($prev);

        return ['amount' => round($sales * self::ESTIMATE_SHARE, 2), 'sales' => $sales, 'from' => $prev, 'to' => Carbon::parse($prev)->addDays(6)->toDateString()];
    }

    /**
     * Value (ex GST) of what was dispatched in a week, net of sale reversals: each dispatch at its
     * order line's price, else the product's default sell price (PackPricing), as the drop report does.
     */
    public function dispatchedValue(string $weekStart): float
    {
        [$from, $to] = $this->utcRange($weekStart);
        $moves = StockMovement::whereIn('type', [MovementType::Sale, MovementType::SaleReversal])->where('source_type', 'order')
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
            ->toBase()->get(['product_id', 'source_id', 'qty']);
        if ($moves->isEmpty()) {
            return 0.0;
        }

        // Price per base unit, per order and product: the first priced line wins.
        $linePrice = [];
        OrderLine::whereIn('order_id', $moves->pluck('source_id')->unique())->whereNotNull('unit_price')->orderBy('id')
            ->get(['order_id', 'product_id', 'unit_price', 'base_qty_per_unit'])
            ->each(function (OrderLine $l) use (&$linePrice) {
                $linePrice["{$l->order_id}-{$l->product_id}"] ??= (float) $l->unit_price / max(1, $l->base_qty_per_unit);
            });
        $fallback = Product::with(['units', 'channelListings'])->whereIn('id', $moves->pluck('product_id')->unique())->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => PackPricing::sellFor($p, 1) ?? 0.0]);

        $value = 0.0;
        foreach ($moves as $m) {
            $value += -(int) $m->qty * ($linePrice["{$m->source_id}-{$m->product_id}"] ?? $fallback[$m->product_id] ?? 0.0);
        }

        return round($value, 2);
    }

    /** @return array{0: Carbon, 1: Carbon} the week's Brisbane Monday 00:00 to the next Monday, in UTC */
    private function utcRange(string $weekStart): array
    {
        $from = Carbon::parse($weekStart, config('bwms.business_timezone'))->startOfDay();

        return [$from->copy()->utc(), $from->copy()->addWeek()->utc()];
    }
}
