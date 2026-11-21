<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\AllocationAccount;
use App\Models\AllocationStaff;
use App\Models\Setting;
use App\Services\Finance\AllocationMath;
use App\Services\Finance\AllocationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Income allocation → Settings (admins only): the bank accounts, their weights,
 * allocation days, GST basis and the figures the advice uses. Every change is audited (the
 * account and setting models record their own changes).
 */
class AllocationSettingsController extends Controller
{
    public function edit(): Response
    {
        $accounts = AllocationAccount::ordered();
        $split = $accounts->filter->takesShare()->mapWithKeys(fn (AllocationAccount $a) => [$a->role => (float) $a->weight])->all();

        return Inertia::render('finance/AllocationSettings', [
            'accounts' => $accounts->map(fn (AllocationAccount $a) => [
                'id' => $a->id, 'role' => $a->role, 'name' => $a->name, 'xero_code' => $a->xero_code, 'purpose' => $a->purpose,
                'weight' => $a->weight, 'target_weight' => $a->target_weight, 'institution' => $a->institution, 'last4' => $a->last4,
                'is_active' => $a->is_active, 'is_split' => $a->isSplit(),
            ])->values(),
            'percent' => AllocationMath::rescaled($split),
            'settings' => AllocationSettings::all(),
            'staff' => AllocationStaff::orderByDesc('is_active')->orderBy('name')->get()->map(fn (AllocationStaff $s) => [
                'id' => $s->id, 'name' => $s->name, 'role' => $s->role, 'pay_type' => $s->pay_type, 'rate' => $s->rate,
                'hours_per_week' => $s->hours_per_week, 'super_percent' => $s->super_percent, 'start_date' => $s->start_date->toDateString(),
                'end_date' => $s->end_date?->toDateString(), 'is_active' => $s->is_active,
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'accounts' => ['required', 'array'],
            'accounts.*.id' => ['required', 'integer', 'exists:allocation_accounts,id'],
            'accounts.*.name' => ['required', 'string', 'max:100'],
            'accounts.*.xero_code' => ['nullable', 'string', 'max:20'],
            'accounts.*.purpose' => ['nullable', 'string', 'max:255'],
            'accounts.*.weight' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'accounts.*.target_weight' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'accounts.*.institution' => ['nullable', 'string', 'max:100'],
            // Never a full account number: the last 4 digits only.
            'accounts.*.last4' => ['nullable', 'digits:4'],
            'accounts.*.is_active' => ['boolean'],
            'days' => ['required', 'array', 'min:1', 'max:4'],
            'days.*' => ['integer', 'between:1,31', 'distinct'],
            'gst_basis' => ['required', 'in:accrual,cash'],
            'net_off_credits' => ['boolean'],
            'company_tax_rate' => ['required', 'numeric', 'between:0,100'],
            'fixed_costs_weekly' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vault_weeks_min' => ['required', 'numeric', 'min:0', 'max:104'],
            'vault_weeks_max' => ['required', 'numeric', 'gte:vault_weeks_min', 'max:104'],
            'director_salaries_fortnight' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'debt_priority' => ['nullable', 'array', 'max:10'],
            'debt_priority.*' => ['string', 'max:60'],
            'payg_rate' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['accounts'] as $row) {
                $a = AllocationAccount::findOrFail($row['id']);
                $a->fill([
                    'name' => $row['name'], 'xero_code' => $row['xero_code'] ?? null, 'purpose' => $row['purpose'] ?? null,
                    'institution' => $row['institution'] ?? null, 'last4' => $row['last4'] ?? null,
                    'target_weight' => $a->isSplit() && isset($row['target_weight']) && $row['target_weight'] !== '' ? $row['target_weight'] : null,
                    'weight' => $a->isSplit() ? ($row['weight'] ?? 0) : null,
                    // Only Debt Service is switched by hand; Payroll follows the staff list; the rest are always on.
                    'is_active' => match ($a->role) {
                        AllocationAccount::DEBT_SERVICE => (bool) ($row['is_active'] ?? false),
                        AllocationAccount::PAYROLL => $a->is_active, // on while any staff member is active
                        default => true,
                    },
                ])->save();
            }
            if (AllocationAccount::all()->filter->takesShare()->isEmpty()) {
                throw ValidationException::withMessages(['accounts' => 'At least one account needs a weight above 0.']);
            }

            $days = array_map('intval', $data['days']);
            sort($days);
            Setting::put([
                AllocationSettings::DAYS => implode(',', $days),
                AllocationSettings::GST_BASIS => $data['gst_basis'],
                AllocationSettings::NET_OFF_CREDITS => ($data['net_off_credits'] ?? false) ? '1' : '0',
                AllocationSettings::COMPANY_TAX_RATE => (string) (float) $data['company_tax_rate'],
                AllocationSettings::FIXED_COSTS_WEEKLY => isset($data['fixed_costs_weekly']) ? (string) $data['fixed_costs_weekly'] : '',
                AllocationSettings::VAULT_WEEKS_MIN => (string) (float) $data['vault_weeks_min'],
                AllocationSettings::VAULT_WEEKS_MAX => (string) (float) $data['vault_weeks_max'],
                AllocationSettings::DIRECTOR_SALARIES => isset($data['director_salaries_fortnight']) ? (string) $data['director_salaries_fortnight'] : '',
                AllocationSettings::DEBT_PRIORITY => implode("\n", array_values(array_filter(array_map('trim', $data['debt_priority'] ?? [])))),
                AllocationSettings::PAYG_RATE => (string) (float) ($data['payg_rate'] ?? 0),
            ]);
        });

        return back()->with('success', 'Income allocation settings saved.');
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $staff = AllocationStaff::create($this->staffData($request));
        AllocationStaff::syncPayrollAccount();

        return back()->with('success', "{$staff->name} added to payroll.");
    }

    public function updateStaff(Request $request, AllocationStaff $staff): RedirectResponse
    {
        $staff->fill($this->staffData($request))->save();
        AllocationStaff::syncPayrollAccount();

        return back()->with('success', "{$staff->name} saved.");
    }

    public function destroyStaff(AllocationStaff $staff): RedirectResponse
    {
        $staff->delete();
        AllocationStaff::syncPayrollAccount();

        return back()->with('success', "{$staff->name} removed from payroll.");
    }

    /** Pay facts only: no TFN, bank details or other personal data. */
    private function staffData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'pay_type' => ['required', 'in:hourly,salary'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'hours_per_week' => ['nullable', 'required_if:pay_type,hourly', 'numeric', 'gt:0', 'max:168'],
            'super_percent' => ['required', 'numeric', 'between:0,100'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
        ]);

        return $data + ['is_active' => true];
    }
}
