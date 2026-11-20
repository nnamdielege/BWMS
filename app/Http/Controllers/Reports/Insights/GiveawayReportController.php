<?php

namespace App\Http\Controllers\Reports\Insights;

use App\Services\Reports\Insights\GiveawayReport;
use App\Services\Reports\Insights\InsightFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reports → Samples, gifts & favours. */
class GiveawayReportController extends InsightReportController
{
    public function __construct(private GiveawayReport $report) {}

    protected function component(): string
    {
        return 'reports/insights/Giveaways';
    }

    protected function name(): string
    {
        return 'giveaways';
    }

    protected function build(InsightFilters $filters, Request $request): array
    {
        return $this->report->build($filters);
    }

    protected function extra(Request $request): array
    {
        return ['group' => $this->groupBy($request)];
    }

    protected function file(array $report, InsightFilters $filters, string $format): array
    {
        return $this->report->export($report, $filters, $this->groupBy(request()), $format);
    }

    private function groupBy(Request $request): string
    {
        return $request->validate(['group' => ['nullable', Rule::in(GiveawayReport::GROUPS)]])['group'] ?? 'reason';
    }
}
