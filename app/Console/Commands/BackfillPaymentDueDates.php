<?php

namespace App\Console\Commands;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * One-off, after release: payment due dates for POs that were already open before approval set
 * them. Run it once suppliers' payment terms (days) are entered, since it reads them; a migration
 * would run before they are and give every PO 0 days.
 */
class BackfillPaymentDueDates extends Command
{
    protected $signature = 'purchasing:backfill-payment-due {--dry-run : List what would change, change nothing}';

    protected $description = 'Set payment_due_date on open POs that have none: expected date (else order date) + supplier payment terms';

    public const STATUSES = [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received];

    public function handle(): int
    {
        $pos = PurchaseOrder::with('supplier:id,name,payment_terms_days')
            ->whereIn('status', PurchaseOrderStatus::values(self::STATUSES))
            ->whereNull('payment_due_date')->whereNull('paid_at')
            ->orderBy('id')->get();

        $rows = [];
        foreach ($pos as $po) {
            $from = $po->expected_date ?? $po->order_date;
            $due = $from->copy()->addDays((int) $po->supplier->payment_terms_days)->toDateString();
            $rows[] = [$po->number, $po->supplier->name, $po->status->label(), $po->expected_date ? 'expected '.$from->toDateString() : 'ordered '.$from->toDateString(),
                $po->supplier->payment_terms_days, $due];
            if (! $this->option('dry-run')) {
                $po->forceFill(['payment_due_date' => $due])->save();
            }
        }

        $this->table(['PO', 'Supplier', 'Status', 'From', 'Terms (days)', 'Due'], $rows);
        if ($this->option('dry-run')) {
            $this->info(count($rows).' PO(s) would be updated. Nothing changed (dry run).');

            return self::SUCCESS;
        }
        if ($rows !== []) {
            Audit::record('purchase_orders_payment_due_backfilled', meta: ['count' => count($rows), 'numbers' => array_column($rows, 0)]);
        }
        $this->info(count($rows).' PO(s) updated.');

        return self::SUCCESS;
    }
}
