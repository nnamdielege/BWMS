<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchasingBudgetRequest;
use App\Models\PurchasingBudget;
use App\Services\Purchasing\PurchasingBudgetService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Purchasing → Budgets: the purchasing budget per Brisbane Mon–Sun week, ex GST. */
class PurchasingBudgetController extends Controller
{
    public function __construct(private PurchasingBudgetService $budgets) {}

    public function index(Request $request): Response
    {
        $current = $this->budgets->weekStart();
        // Weeks without a budget are pre-filled from the last finished week's sales.
        $latestEstimate = $this->budgets->estimate($current);
        $rows = [];
        for ($w = -4; $w <= 8; $w++) {
            $start = Carbon::parse($current)->addWeeks($w)->toDateString();
            $budget = $this->budgets->budgetFor($start);
            $summary = $this->budgets->summary($start);
            $estimate = $w <= 0 ? $this->budgets->estimate($start) : $latestEstimate;
            $rows[] = [
                ...$summary,
                'is_current' => $w === 0,
                'is_past' => $w < 0,
                'id' => $budget?->id,
                'notes' => $budget?->notes,
                'updated_by' => $budget?->editor?->name,
                'estimate' => $estimate,
            ];
        }

        return Inertia::render('purchasing/Budgets', [
            'weeks' => $rows,
            'share' => PurchasingBudgetService::ESTIMATE_SHARE,
            'canManage' => $request->user()->can('purchase_orders.manage'),
        ]);
    }

    /** Set (or change) a week's budget. */
    public function store(PurchasingBudgetRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $budget = PurchasingBudget::whereDate('week_start', $data['week_start'])->first() ?? new PurchasingBudget(['week_start' => $data['week_start']]);
        $old = $budget->exists ? ['budget' => (string) $budget->budget, 'notes' => $budget->notes] : null;
        $budget->fill(['budget' => $data['budget'], 'notes' => $data['notes'] ?? null]);
        $budget->forceFill(['updated_by' => $request->user()->id])->save();
        Audit::record('purchasing_budget_set', $budget, old: $old, new: ['week_start' => $data['week_start'], 'budget' => (string) $data['budget'], 'notes' => $data['notes'] ?? null]);

        return back()->with('success', 'Budget for the week of '.Carbon::parse($data['week_start'])->format('j M').' saved.');
    }

    public function destroy(PurchasingBudget $budget): RedirectResponse
    {
        Audit::record('purchasing_budget_removed', $budget, old: ['week_start' => $budget->week_start->toDateString(), 'budget' => (string) $budget->budget]);
        $budget->delete();

        return back()->with('success', 'Budget removed.');
    }
}
