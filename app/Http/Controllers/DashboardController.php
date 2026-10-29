<?php

namespace App\Http\Controllers;

use App\Models\CountTask;
use App\Models\Location;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StockBalance;
use App\Models\SyncAttempt;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing page. Each widget is included only if the user may see that area.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $widgets = [];

        if ($user->can('orders.view')) {
            $widgets['oversold'] = Order::where(fn ($q) => $q->where('oversold', true)->orWhere('needs_review', true))
                ->whereIn('status', ['open', 'picking', 'partially_dispatched'])
                ->latest('id')->limit(10)->get(['id', 'number', 'channel', 'oversold', 'needs_review'])
                ->map(fn (Order $o) => ['id' => $o->id, 'number' => $o->number, 'channel' => $o->channel->value, 'oversold' => $o->oversold, 'needs_review' => $o->needs_review]);
            $widgets['awaiting_pick'] = Order::whereIn('status', ['open', 'partially_dispatched'])->count();
        }

        if ($user->can('purchase_orders.view')) {
            $widgets['overdue_pos'] = PurchaseOrder::whereIn('status', ['sent', 'partially_received'])
                ->whereDate('expected_date', '<', BusinessDate::today())->count();
        }

        if ($user->can('stock.view')) {
            $negative = StockBalance::query()->where('stock_balances.on_hand', '<', 0)
                ->join('locations', 'locations.id', '=', 'stock_balances.location_id')
                ->join('products', 'products.id', '=', 'stock_balances.product_id');
            $widgets['negative_bins'] = [
                'total' => (clone $negative)->count(),
                'rows' => $negative->orderBy('stock_balances.on_hand')->limit(10)
                    ->get(['stock_balances.product_id', 'stock_balances.location_id', 'stock_balances.on_hand', 'products.sku', 'products.name'])
                    ->map(fn ($b) => ['product_id' => $b->product_id, 'sku' => $b->sku, 'name' => $b->name, 'on_hand' => (int) $b->on_hand,
                        'bin' => Location::find($b->location_id)?->path()]),
            ];

            $open = CountTask::where('status', CountTask::STATUS_OPEN);
            $widgets['count_tasks'] = [
                'total' => (clone $open)->count(),
                'rows' => $open->with(['location.parent.parent', 'product:id,sku'])->oldest()->limit(10)->get()
                    ->map(fn (CountTask $t) => ['id' => $t->id, 'location_id' => $t->location_id, 'bin' => $t->location->path(), 'sku' => $t->product?->sku,
                        'on_hand_at_raise' => $t->on_hand_at_raise, 'times_raised' => $t->times_raised, 'raised_at' => $t->created_at->toIso8601String()]),
                'can_manage' => $user->can('stocktakes.manage'),
            ];

            // Only non-zero when a site receives into a non-sellable bin (RECEIVING + put-away).
            $widgets['awaiting_putaway'] = StockBalance::where('on_hand', '>', 0)
                ->whereIn('location_id', Location::bins()->where('is_receiving', true)->where('is_sellable', false)->select('id'))->count();
        }

        if ($user->can('sync.view')) {
            // Same source as the Sync screen's "Failures (24h)", both channels.
            $widgets['sync_failures_24h'] = SyncAttempt::where('success', false)->where('created_at', '>', now()->subDay())->count();
        }

        return Inertia::render('Dashboard', ['widgets' => $widgets]);
    }
}
