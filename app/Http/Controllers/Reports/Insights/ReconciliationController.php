<?php

namespace App\Http\Controllers\Reports\Insights;

use App\Services\Reports\Insights\InsightFilters;
use App\Services\Reports\Insights\ReconciliationReport;
use Illuminate\Http\Request;

/** Reports → Stock reconciliation (shrinkage). */
class ReconciliationController extends InsightReportController
{
    public function __construct(private ReconciliationReport $report) {}

    protected function component(): string
    {
        return 'reports/insights/Reconciliation';
    }

    protected function name(): string
    {
        return 'stock_reconciliation';
    }

    protected function build(InsightFilters $filters, Request $request): array
    {
        return $this->report->build($filters);
    }

    protected function file(array $report, InsightFilters $filters, string $format): array
    {
        return $this->report->export($report, $filters, $format);
    }
}
