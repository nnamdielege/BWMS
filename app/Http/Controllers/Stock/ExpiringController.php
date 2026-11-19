<?php

namespace App\Http\Controllers\Stock;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Stock\ExpiringSoon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Stock → Expiring soon: batches near their best-before and whether they'll sell first. */
class ExpiringController extends Controller
{
    public function index(Request $request, ExpiringSoon $expiring): Response
    {
        $user = $request->user();
        $report = $expiring->build();
        // Cost figures only for those who see costs elsewhere.
        if (! $user->can('reports.view')) {
            $report['rows'] = array_map(fn ($r) => array_merge($r, ['unit_cost' => null, 'value_left' => null]), $report['rows']);
            $report['totals']['value_left'] = null;
        }

        return Inertia::render('stock/Expiring', [
            'report' => $report,
            'settings' => ['days' => Setting::expiringDays(), 'in_email' => Setting::expiringInEmail()],
            'can' => ['settings' => $user->can('reports.margin'), 'costs' => $user->can('reports.view'), 'move' => $user->can('stock.transfer')],
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'in_email' => ['required', 'boolean'],
        ]);
        Setting::put([Setting::EXPIRING_DAYS => (string) $data['days'], Setting::EXPIRING_IN_EMAIL => $data['in_email'] ? '1' : '0']);

        return back()->with('success', 'Expiring soon settings saved.');
    }
}
