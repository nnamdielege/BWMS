<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingBudgetService;
use App\Services\Purchasing\ReorderService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\Audit;
use Illuminate\Console\Command;

/** Mondays 06:00 (Brisbane), and from "Generate suggestions" on the PO list: one suggested PO per supplier. */
class SuggestPurchaseOrders extends Command
{
    protected $signature = 'purchasing:suggest';

    protected $description = 'Create or refresh suggested purchase orders from sales velocity';

    public function handle(ReorderService $reorder, PurchasingBudgetService $budgets): int
    {
        if ($budget = $budgets->ensureCurrentWeek()) {
            $this->line("Budget for the week of {$budget->week_start->toDateString()} set to \$".number_format((float) $budget->budget, 2).' (74% estimate; check against Xero).');
        }
        try {
            $results = $reorder->draftPurchaseOrders();
        } catch (StockException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $r) {
            $po = $r['po'];
            $this->line(sprintf('%-9s %s  %s  $%s ex GST%s', $r['action'], $po->number, $po->supplier->name, number_format((float) $po->subtotal, 2),
                $r['below_minimum'] ? '  (below the $'.number_format($r['min_order_value'], 2).' minimum)' : ''));
        }
        Audit::record('purchase_orders_suggested', meta: ['source' => 'command'] + $results->countBy('action')->all());
        $this->info($results->isEmpty() ? 'Nothing to order.' : "{$results->count()} suggested PO(s) processed.");

        return self::SUCCESS;
    }
}
