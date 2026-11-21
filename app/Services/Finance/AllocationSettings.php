<?php

namespace App\Services\Finance;

use App\Models\Setting;

/**
 * Settings for Finance → Income allocation, kept in the settings table (audited there).
 *
 * GST basis: accrual = GST on sales dispatched in the period (default); cash = GST inside the
 * receipts themselves. Company tax: a % of the period's net profit estimate, 0 (off) until the
 * accountant confirms a rate. Fixed costs a week and director salaries a fortnight feed the
 * Vault and Owner's Comp advice only; blank means no advice.
 */
final class AllocationSettings
{
    public const DAYS = 'finance.allocation_days';

    public const GST_BASIS = 'finance.gst_basis';

    public const NET_OFF_CREDITS = 'finance.net_off_gst_credits';

    public const COMPANY_TAX_RATE = 'finance.company_tax_rate';

    public const FIXED_COSTS_WEEKLY = 'finance.fixed_costs_weekly';

    public const VAULT_WEEKS_MIN = 'finance.vault_weeks_min';

    public const VAULT_WEEKS_MAX = 'finance.vault_weeks_max';

    public const DIRECTOR_SALARIES = 'finance.director_salaries_fortnight';

    public const DEBT_PRIORITY = 'finance.debt_priority';

    /** Estimated PAYG withholding as a % of gross wages; 0 = don't show the line. */
    public const PAYG_RATE = 'finance.payg_rate';

    public static function paygRate(): float
    {
        return min(100.0, max(0.0, (float) Setting::get(self::PAYG_RATE, '0')));
    }

    public const DEFAULT_DEBT_PRIORITY = ['Supplier credit', 'ATO', 'Business loan', 'Equipment finance'];

    /** @return list<int> days of the month, ascending (default 10th and 25th) */
    public static function days(): array
    {
        $days = array_map('intval', array_filter(array_map('trim', explode(',', Setting::get(self::DAYS, '10,25') ?? ''))));
        $days = array_values(array_unique(array_filter($days, fn ($d) => $d >= 1 && $d <= 31)));
        sort($days);

        return $days ?: [10, 25];
    }

    public static function gstBasis(): string
    {
        return Setting::get(self::GST_BASIS, 'accrual') === 'cash' ? 'cash' : 'accrual';
    }

    public static function netOffCredits(): bool
    {
        return Setting::get(self::NET_OFF_CREDITS, '0') === '1';
    }

    /** Percent, 0 to 100. */
    public static function companyTaxRate(): float
    {
        return min(100.0, max(0.0, (float) Setting::get(self::COMPANY_TAX_RATE, '0')));
    }

    public static function fixedCostsWeekly(): ?float
    {
        return self::optional(self::FIXED_COSTS_WEEKLY);
    }

    public static function directorSalariesFortnight(): ?float
    {
        return self::optional(self::DIRECTOR_SALARIES);
    }

    /** @return array{0: float, 1: float} weeks of fixed costs the Vault should hold, low and high */
    public static function vaultWeeks(): array
    {
        $min = max(0.0, (float) Setting::get(self::VAULT_WEEKS_MIN, '4'));
        $max = max($min, (float) Setting::get(self::VAULT_WEEKS_MAX, '8'));

        return [$min, $max];
    }

    /** @return list<string> */
    public static function debtPriority(): array
    {
        $raw = Setting::get(self::DEBT_PRIORITY);

        return $raw === null ? self::DEFAULT_DEBT_PRIORITY : array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }

    /** Everything, for the settings and allocation pages. */
    public static function all(): array
    {
        [$min, $max] = self::vaultWeeks();

        return [
            'days' => self::days(),
            'gst_basis' => self::gstBasis(),
            'net_off_credits' => self::netOffCredits(),
            'company_tax_rate' => self::companyTaxRate(),
            'fixed_costs_weekly' => self::fixedCostsWeekly(),
            'vault_weeks_min' => $min,
            'vault_weeks_max' => $max,
            'director_salaries_fortnight' => self::directorSalariesFortnight(),
            'debt_priority' => self::debtPriority(),
            'payg_rate' => self::paygRate(),
        ];
    }

    private static function optional(string $key): ?float
    {
        $v = Setting::get($key);

        return $v === null || trim($v) === '' ? null : max(0.0, (float) $v);
    }
}
