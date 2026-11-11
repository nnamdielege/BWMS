<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Reports\DropReport;
use App\Services\Reports\MarginExport;
use App\Services\Reports\StockValueReport;
use App\Support\Audit;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Reports → Stock value & margin: stock at cost and sell, and whether drops pay. Managers and admins. */
class MarginReportController extends Controller
{
    public function __construct(private StockValueReport $stock, private DropReport $drops) {}

    public function index(Request $request): Response
    {
        $f = $this->filters($request);

        return Inertia::render('reports/Margin', [
            'filters' => $f,
            'settings' => ['delivery_cost' => Setting::dropDeliveryCost(), 'free_delivery_min' => Setting::freeDeliveryMin()],
            'stock' => $this->stock->build($f['basis'], $f['sellable']),
            'modelled' => $this->drops->modelled($f['units'], Setting::dropDeliveryCost()),
            'real' => $this->drops->real($f['from'], $f['to'], Setting::dropDeliveryCost(), Setting::freeDeliveryMin()),
        ]);
    }

    /** Delivery cost per drop and the free-delivery minimum are company settings. */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delivery_cost' => ['required', 'numeric', 'min:0', 'max:10000'],
            'free_delivery_min' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);
        Setting::put([
            Setting::DROP_DELIVERY_COST => number_format((float) $data['delivery_cost'], 2, '.', ''),
            Setting::DROP_FREE_DELIVERY_MIN => number_format((float) $data['free_delivery_min'], 2, '.', ''),
        ]);

        return back()->with('success', 'Delivery settings saved.');
    }

    public function export(Request $request, string $view, string $format, MarginExport $export): HttpResponse
    {
        $f = $this->filters($request);
        $delivery = Setting::dropDeliveryCost();

        $file = match ($view) {
            'stock' => $export->stock($this->stock->build($f['basis'], $f['sellable']), $f['basis'], $f['sellable'], $format),
            'drops' => $export->drops($this->drops->modelled($f['units'], $delivery), $f['units'], $delivery, $format),
            'real' => $export->real($this->drops->real($f['from'], $f['to'], $delivery, Setting::freeDeliveryMin()), $f['from'], $f['to'], $delivery, Setting::freeDeliveryMin(), $format),
        };
        Audit::record('margin_report_exported', null, meta: ['view' => $view, 'format' => $format] + $f);

        return response($file['body'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /** @return array{basis: string, sellable: bool, units: int, from: string, to: string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'basis' => ['nullable', 'in:'.StockValueReport::ON_HAND.','.StockValueReport::AVAILABLE],
            'sellable' => ['nullable', 'boolean'],
            'units' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = $data['to'] ?? BusinessDate::today();
        $from = $data['from'] ?? Carbon::parse($to)->subDays(27)->toDateString();
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            $from = Carbon::parse($to)->subDays(366)->toDateString();
        }

        return [
            'basis' => $data['basis'] ?? StockValueReport::ON_HAND,
            'sellable' => (bool) ($data['sellable'] ?? false),
            'units' => (int) ($data['units'] ?? 6),
            'from' => $from,
            'to' => $to,
        ];
    }
}
