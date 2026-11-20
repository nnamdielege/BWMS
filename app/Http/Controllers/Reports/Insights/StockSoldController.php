<?php

namespace App\Http\Controllers\Reports\Insights;

use App\Services\Reports\Insights\InsightFilters;
use App\Services\Reports\Insights\StockSoldReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reports → Stock sold. */
class StockSoldController extends InsightReportController
{
    public function __construct(private StockSoldReport $report) {}

    protected function component(): string
    {
        return 'reports/insights/Sold';
    }

    protected function name(): string
    {
        return 'stock_sold';
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
        return $request->validate(['group' => ['nullable', Rule::in(StockSoldReport::GROUPS)]])['group'] ?? 'sku';
    }
}
