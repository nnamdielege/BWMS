<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\SpoilageReport;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reports → Spoilage: write-offs at cost, grouped, against cost of goods sold. Managers and admins. */
class SpoilageReportController extends Controller
{
    public function __construct(private SpoilageReport $report) {}

    public function index(Request $request): Response
    {
        $f = $this->filters($request);

        return Inertia::render('reports/Spoilage', [
            'filters' => $f,
            'report' => $this->report->build($f['from'], $f['to'], $f['period']),
        ]);
    }

    public function export(Request $request, string $format): HttpResponse
    {
        $f = $this->filters($request);
        $file = $this->report->export($this->report->build($f['from'], $f['to'], $f['period']), $f['from'], $f['to'], $f['group'], $format);
        Audit::record('spoilage_report_exported', null, meta: ['format' => $format] + $f);

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /** @return array{from: string, to: string, period: string, group: string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'period' => ['nullable', Rule::in(SpoilageReport::PERIODS)],
            'group' => ['nullable', Rule::in(SpoilageReport::GROUPS)],
        ]);
        $to = $data['to'] ?? BusinessDate::today();
        $from = $data['from'] ?? Carbon::parse($to)->subDays(89)->toDateString();
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 731) {
            $from = Carbon::parse($to)->subDays(731)->toDateString();
        }

        return [
            'from' => $from,
            'to' => $to,
            'period' => $data['period'] ?? SpoilageReport::defaultPeriod($from, $to),
            'group' => $data['group'] ?? 'reason',
        ];
    }
}
