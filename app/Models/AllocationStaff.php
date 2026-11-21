<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * A staff member for the Payroll estimate in Income allocation. Pay facts only: no TFN, bank
 * details or other personal data (payroll itself is run in Xero Payroll).
 * rate: dollars an hour (hourly) or a year (salary), gross, before super.
 */
class AllocationStaff extends Model
{
    use Auditable;

    public const HOURLY = 'hourly';

    public const SALARY = 'salary';

    protected $table = 'allocation_staff';

    protected $fillable = ['name', 'role', 'pay_type', 'rate', 'hours_per_week', 'super_percent', 'start_date', 'end_date', 'is_active'];

    protected function casts(): array
    {
        return [
            'rate' => 'float', 'hours_per_week' => 'float', 'super_percent' => 'float',
            'start_date' => 'date', 'end_date' => 'date', 'is_active' => 'boolean',
        ];
    }

    /** The Payroll account is on while anyone is active. */
    public static function syncPayrollAccount(): void
    {
        $account = AllocationAccount::where('role', AllocationAccount::PAYROLL)->first();
        $on = self::where('is_active', true)->exists();
        if ($account && $account->is_active !== $on) {
            $account->forceFill(['is_active' => $on])->save();
        }
    }
}
