<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseOrder;
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

        return Inertia::render('Dashboard', ['widgets' => $widgets]);
    }
}
