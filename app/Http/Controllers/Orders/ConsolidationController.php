<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRun;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Orders\ConsolidationService;
use App\Services\Stock\Exceptions\StockException;
use App\Support\BusinessDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Orders → Consolidate: fill a delivery day from later runs. */
class ConsolidationController extends Controller
{
    private const DEFAULT_DAYS = 7;

    public function __construct(private ConsolidationService $consolidation) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'days' => ['nullable', 'integer', 'min:1', 'max:60']]);
        $today = BusinessDate::today();
        $target = $filters['date'] ?? Carbon::parse($today)->addDay()->toDateString();
        $days = (int) ($filters['days'] ?? self::DEFAULT_DAYS);

        $onDay = $this->consolidation->ordersBetween($target, $target);
        $candidates = $this->consolidation->ordersBetween(Carbon::parse($target)->addDay()->toDateString(), Carbon::parse($target)->addDays($days)->toDateString());
        $lists = $this->consolidation->pickLists($candidates->concat($onDay));

        $notice = Setting::get(ConsolidationService::NOTICE_SETTING) === '1';
        $moved = $onDay->where('delivery_date_reason', ConsolidationService::REASON);

        return Inertia::render('orders/Consolidate', [
            'target' => $target,
            'days' => $days,
            'today' => $today,
            'runs' => DeliveryRun::with('area:id,name,sort_order')->whereDate('run_date', $target)->get()->sortBy('area.sort_order')->values()->map(function (DeliveryRun $run) use ($onDay) {
                $orders = $onDay->where('delivery_run_id', $run->id);
                $sizes = $orders->map(fn (Order $o) => $this->consolidation->size($o));
                $values = $sizes->pluck('value')->filter();

                return [
                    'id' => $run->id, 'area' => $run->area->name, 'status' => $run->status, 'trip_label' => $run->trip_label,
                    'orders' => $orders->count(), 'packs' => $sizes->sum('packs'),
                    'value' => $values->isEmpty() ? null : $values->reduce(fn ($sum, $v) => bcadd($sum, $v, 2), '0'),
                ];
            }),
            'moved' => $moved->values()->map(fn (Order $o) => $this->row($o, $lists, $target)),
            'candidates' => $candidates->map(fn (Order $o) => $this->row($o, $lists, $target))->values(),
            'noticeEnabled' => $notice,
            'notice' => $notice ? $moved->values()->map(fn (Order $o) => [
                'order' => $o->number, 'customer' => $o->customer?->name, 'phone' => $o->customer?->phone, 'email' => $o->customer?->email,
                'moved_from' => $o->original_delivery_date->toDateString(),
            ]) : [],
        ]);
    }

    public function move(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'order_ids' => ['required', 'array', 'min:1', 'max:500'],
            'order_ids.*' => ['integer'],
        ]);
        $result = $this->consolidation->move($data['order_ids'], $data['date'], $request->user());

        $redirect = back()->with('success', "Moved {$result['moved']} order(s).");
        $problems = [...$result['warnings'], ...$result['skipped']];

        return $problems === [] ? $redirect : $redirect->with('error', implode('. ', $problems).'.');
    }

    public function undo(Request $request, Order $order): RedirectResponse
    {
        try {
            $this->consolidation->undo($order, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['consolidate' => $e->getMessage()]);
        }

        return back()->with('success', "{$order->number} is back on its original date.");
    }

    public function trips(Request $request): RedirectResponse
    {
        $data = $request->validate(['labels' => ['required', 'array'], 'labels.*' => ['nullable', 'string', 'max:64']]);
        $this->consolidation->saveTrips($data['labels']);

        return back()->with('success', 'Trips saved.');
    }

    public function notice(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        Setting::put([ConsolidationService::NOTICE_SETTING => $data['enabled'] ? '1' : '0']);

        return back();
    }

    /** @param  array<int, string>  $lists  order_id => pick list number */
    private function row(Order $order, array $lists, string $target): array
    {
        return [
            'id' => $order->id, 'number' => $order->number, 'customer' => $order->customer?->name,
            'suburb' => $order->delivery_suburb ?? $order->customer?->delivery_suburb,
            'channel' => $order->channel->value, 'date' => $order->delivery_date->toDateString(), 'area' => $order->deliveryRun->area->name,
            'moved_from' => $order->original_delivery_date?->toDateString(), 'pick_list' => $lists[$order->id] ?? null,
            'needs_review' => $order->needs_review, 'hold' => $order->holdSummary(),
            'warnings' => isset($lists[$order->id]) || $order->delivery_date->toDateString() === $target ? [] : $this->consolidation->stockWarnings($order, $target),
        ] + $this->consolidation->size($order);
    }
}
