<?php

namespace App\Console\Commands;

use App\Authorization\Permissions;
use App\Mail\ReorderSummaryMail;
use App\Models\Setting;
use App\Models\User;
use App\Services\Purchasing\ReorderPlanner;
use App\Support\BusinessDate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Daily at 7am (Brisbane), when switched on in the reorder settings: the summary to managers and admins. */
class SendReorderSummary extends Command
{
    protected $signature = 'reorder:daily-email {--force : Send even when the setting is off}';

    protected $description = 'Email managers the reorder suggestions summary';

    public function handle(ReorderPlanner $planner): int
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

        $to = User::role([Permissions::ROLE_ADMIN, Permissions::ROLE_WAREHOUSE_MANAGER])->where('is_active', true)->pluck('email')->filter()->unique()->values();
        if ($to->isEmpty()) {
            $this->warn('No active managers to email.');

            return self::SUCCESS;
        }
        foreach ($to as $email) {
            Mail::to($email)->send(new ReorderSummaryMail($suppliers, $stockouts, BusinessDate::today()));
        }
        $this->info("Sent to {$to->count()} manager(s).");

        return self::SUCCESS;
    }
}
