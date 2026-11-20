<?php

namespace App\Http\Controllers\Reports\Insights;

use App\Services\Reports\Insights\InsightFilters;
use App\Services\Reports\Insights\StockReceivedReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reports → Stock received. */
class StockReceivedController extends InsightReportController
{
    public function __construct(private StockReceivedReport $report) {}

    protected function component(): string
    {
        return 'reports/insights/Received';
    }

    protected function name(): string
    {
        return 'stock_received';
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
        return $request->validate(['group' => ['nullable', Rule::in(StockReceivedReport::GROUPS)]])['group'] ?? 'sku';
    }
}
