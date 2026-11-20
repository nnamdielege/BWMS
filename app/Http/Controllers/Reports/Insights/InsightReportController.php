<?php

namespace App\Http\Controllers\Reports\Insights;

use App\Http\Controllers\Controller;
use App\Services\Reports\Insights\InsightFilters;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shape every stock insight report shares: the filter bar, the page, and an
 * XLSX/CSV export of the same figures. Managers and admins (reports.margin).
 */
abstract class InsightReportController extends Controller
{
    /** Inertia page component. */
    abstract protected function component(): string;

    /** Short name for the audit log, e.g. "stock_received". */
    abstract protected function name(): string;

    abstract protected function build(InsightFilters $filters, Request $request): array;

    /** @return array{filename: string, type: string, body: string} */
    abstract protected function file(array $report, InsightFilters $filters, string $format): array;

    /** Days shown when no dates are given. */
    protected function defaultDays(): int
    {
        return 30;
    }

    /** Anything else the page needs (beyond filters, options and report). */
    protected function extra(Request $request): array
    {
        return [];
    }

    public function index(Request $request): Response
    {
        $f = InsightFilters::fromRequest($request, $this->defaultDays());

        return Inertia::render($this->component(), [
            'filters' => $f->toArray(),
            'options' => InsightFilters::options($f->productId),
            'report' => $this->build($f, $request),
        ] + $this->extra($request));
    }

    public function export(Request $request, string $format): HttpResponse
    {
        $f = InsightFilters::fromRequest($request, $this->defaultDays());
        $file = $this->file($this->build($f, $request), $f, $format);
        Audit::record($this->name().'_report_exported', null, meta: ['format' => $format] + $f->toArray());

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }
}
