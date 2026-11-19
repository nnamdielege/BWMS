<?php

namespace App\Console\Commands;

use App\Mail\ReorderSummaryMail;
use App\Models\Setting;
use App\Services\Purchasing\ReorderPlanner;
use App\Services\Stock\ExpiringSoon;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Daily at 7am (Brisbane), when switched on in the reorder settings: the summary to the recipients in Company settings. */
class SendReorderSummary extends Command
{
    protected $signature = 'reorder:daily-email {--force : Send even when the setting is off}';

    protected $description = 'Email the reorder suggestions summary to the recipients in Company settings';

    public function handle(ReorderPlanner $planner, ExpiringSoon $expiringSoon): int
    {
        if (! $this->option('force') && ! Setting::reorder()['daily_email']) {
            $this->line('The daily reorder email is switched off.');

            return self::SUCCESS;
        }

        $plan = $planner->build(['only_needed' => false]);
        $stockouts = 0;
        $suppliers = [];
        foreach ($plan['groups'] as $g) {
            $out = array_values(array_filter($g['lines'], fn ($l) => $l['stockout_before_delivery']));
            $stockouts += count($out);
            if ($g['to_order'] === 0 && $out === []) {
                continue;
            }
            $suppliers[] = [
                'supplier' => $g['supplier']['name'] ?? 'No supplier set',
                'to_order' => $g['to_order'],
                'value' => (float) collect($g['lines'])->sum('suggest_value'),
                'min' => $g['min_order_value'],
                'meets_min' => $g['meets_min'],
                'stockouts' => array_map(fn ($l) => $l['sku'], $out),
            ];
        }

        // Expiring soon (Stock → Expiring soon settings): the batches that won't sell in time, and those already past best-before.
        $expiring = null;
        if (Setting::expiringInEmail()) {
            $e = $expiringSoon->build();
            $expiring = [
                'days' => $e['days'],
                'batches' => $e['totals']['batches'],
                'value_left' => $e['totals']['value_left'],
                'lines' => array_values(array_map(fn ($r) => [
                    'sku' => $r['sku'], 'name' => $r['name'], 'batch' => $r['batch'], 'best_before' => $r['best_before'],
                    'left_over' => $r['left_over'], 'base_unit' => $r['base_unit'], 'expired' => $r['status'] === ExpiringSoon::STATUS_EXPIRED,
                ], array_filter($e['rows'], fn ($r) => $r['status'] !== ExpiringSoon::STATUS_OK))),
            ];
        }

        // Company settings → Reorder email: just the owner unless changed there.
        $to = collect(Setting::reorderEmailTo());
        if ($to->isEmpty()) {
            $this->warn('No reorder email recipients are set (Company settings).');

            return self::SUCCESS;
        }
        foreach ($to as $email) {
            Mail::to($email)->send(new ReorderSummaryMail($suppliers, $stockouts, BusinessDate::today(), $expiring));
        }
        $this->info('Sent to '.$to->implode(', ').'.');

        return self::SUCCESS;
    }
}
