<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\SubstitutionReport;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Reports → Substitutions: what customers accepted instead, by date range. Managers and admins. */
class SubstitutionReportController extends Controller
{
    public function __construct(private SubstitutionReport $report) {}

    public function index(Request $request): Response
    {
        $f = $this->filters($request);

        return Inertia::render('reports/Substitutions', ['filters' => $f, 'report' => $this->report->build($f['from'], $f['to'])]);
    }

    public function export(Request $request, string $format): HttpResponse
    {
        $f = $this->filters($request);
        $file = $this->report->export($this->report->build($f['from'], $f['to']), $f['from'], $f['to'], $format);
        Audit::record('substitution_report_exported', null, meta: ['format' => $format] + $f);

        return response($file['body'], 200, ['Content-Type' => $file['type'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"']);
    }

    /** @return array{from: string, to: string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $data['to'] ?? BusinessDate::today();
        $from = $data['from'] ?? Carbon::parse($to)->subDays(27)->toDateString();
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            $from = Carbon::parse($to)->subDays(366)->toDateString();
        }

        return ['from' => $from, 'to' => $to];
    }
}
