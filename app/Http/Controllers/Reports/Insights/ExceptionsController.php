<?php

namespace App\Http\Controllers\Reports\Insights;

use App\Models\Setting;
use App\Services\Reports\Insights\ExceptionsReport;
use App\Services\Reports\Insights\InsightFilters;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Reports → Exceptions & audit (theft indicators). */
class ExceptionsController extends InsightReportController
{
    public function __construct(private ExceptionsReport $report) {}

    protected function component(): string
    {
        return 'reports/insights/Exceptions';
    }

    protected function name(): string
    {
        return 'exceptions';
    }

    protected function build(InsightFilters $filters, Request $request): array
    {
        return $this->report->build($filters);
    }

    protected function file(array $report, InsightFilters $filters, string $format): array
    {
        return $this->report->export($report, $filters, $format);
    }

    /** Working hours and what counts as a large stocktake variance. */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'start_hour' => ['required', 'integer', 'min:0', 'max:23'],
            'end_hour' => ['required', 'integer', 'min:1', 'max:24', 'gt:start_hour'],
            'large_variance' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $before = ['work_hours' => Setting::get(ExceptionsReport::WORK_HOURS), 'large_variance' => Setting::get(ExceptionsReport::LARGE_VARIANCE)];
        $after = [
            'work_hours' => sprintf('%02d:00-%02d:00', $data['start_hour'], $data['end_hour']),
            'large_variance' => (string) round((float) $data['large_variance'], 2),
        ];
        Setting::put([ExceptionsReport::WORK_HOURS => $after['work_hours'], ExceptionsReport::LARGE_VARIANCE => $after['large_variance']]);
        Audit::record('exceptions_settings_changed', null, old: $before, new: $after);

        return back()->with('success', 'Exception settings saved.');
    }
}
