<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Key/value company settings (name, ABN, address… shown on POs). D-12.
 */
class Setting extends Model
{
    use Auditable;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public const COMPANY_KEYS = [
        'company.name', 'company.abn', 'company.address', 'company.phone', 'company.email',
        'company.delivery_address', 'po.terms',
    ];

    /** When off, the product scan alone confirms a pick. On until someone turns it off. */
    public const REQUIRE_BIN_SCAN = 'picking.require_bin_scan';

    /** Drop profitability: what one delivery costs, and the order value that delivers free. Dollars ex GST. */
    public const DROP_DELIVERY_COST = 'drops.delivery_cost';

    public const DROP_FREE_DELIVERY_MIN = 'drops.free_delivery_min';

    /**
     * Reorder suggestions. Safety stock is either days of demand ("days") or
     * z × demand std-dev × √lead time ("z"). Lead time and review period fall back to
     * these when a supplier has none. The 7am email is off until switched on.
     */
    public const REORDER_SAFETY_MODE = 'reorder.safety_mode';

    public const REORDER_SAFETY_DAYS = 'reorder.safety_days';

    public const REORDER_SAFETY_Z = 'reorder.safety_z';

    public const REORDER_DEFAULT_LEAD_DAYS = 'reorder.default_lead_days';

    public const REORDER_DEFAULT_REVIEW_DAYS = 'reorder.default_review_days';

    public const REORDER_EMAIL = 'reorder.daily_email';

    /** Who gets the 7am reorder email: comma-separated addresses. Not set: config bwms.reorder_email_to. */
    public const REORDER_EMAIL_TO = 'reorder.email_to';

    /** @return list<string> the valid addresses the 7am reorder email goes to (none if cleared) */
    public static function reorderEmailTo(): array
    {
        $raw = self::get(self::REORDER_EMAIL_TO) ?? (string) config('bwms.reorder_email_to');

        return array_values(array_unique(array_filter(
            array_map(fn ($a) => strtolower(trim($a)), explode(',', $raw)),
            fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /** @return array{safety_mode: string, safety_days: float, safety_z: float, default_lead_days: int, default_review_days: int, daily_email: bool} */
    public static function reorder(): array
    {
        return [
            'safety_mode' => self::get(self::REORDER_SAFETY_MODE, 'days') === 'z' ? 'z' : 'days',
            'safety_days' => (float) self::get(self::REORDER_SAFETY_DAYS, '3'),
            'safety_z' => (float) self::get(self::REORDER_SAFETY_Z, '1.65'),
            'default_lead_days' => (int) self::get(self::REORDER_DEFAULT_LEAD_DAYS, '7'),
            'default_review_days' => (int) self::get(self::REORDER_DEFAULT_REVIEW_DAYS, '7'),
            'daily_email' => self::get(self::REORDER_EMAIL, '0') === '1',
        ];
    }

    /** Expiring soon: best-before within this many days (default 14), and whether the 7am email lists them (default on). */
    public const EXPIRING_DAYS = 'spoilage.expiring_days';

    public const EXPIRING_IN_EMAIL = 'spoilage.expiring_in_email';

    public static function expiringDays(): int
    {
        return max(1, (int) self::get(self::EXPIRING_DAYS, '14'));
    }

    public static function expiringInEmail(): bool
    {
        return self::get(self::EXPIRING_IN_EMAIL, '1') !== '0';
    }

    /**
     * Which write-off reasons count as spoilage (loss): reason codes, comma-separated.
     * Samples, gifts, favours, staff and internal use are never loss; they have their own report.
     */
    public const SPOILAGE_LOSS_REASONS = 'spoilage.loss_reasons';

    public const DEFAULT_LOSS_REASONS = ['EXPIRED', 'DAMAGED', 'SPOILED', 'RECALL', 'OTHER'];

    /** @return list<string> reason codes */
    public static function spoilageLossReasons(): array
    {
        $raw = self::get(self::SPOILAGE_LOSS_REASONS);

        return $raw === null ? self::DEFAULT_LOSS_REASONS : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Optional monthly budget for samples, gifts and favours, dollars at cost. Empty = no budget. */
    public const GIVEAWAY_BUDGET = 'giveaway.monthly_budget';

    public static function giveawayBudget(): ?float
    {
        $v = self::get(self::GIVEAWAY_BUDGET);

        return $v === null || trim($v) === '' ? null : max(0.0, (float) $v);
    }

    public static function dropDeliveryCost(): float
    {
        return (float) self::get(self::DROP_DELIVERY_COST, '30');
    }

    public static function freeDeliveryMin(): float
    {
        return (float) self::get(self::DROP_FREE_DELIVERY_MIN, '250');
    }

    public static function requiresBinScan(): bool
    {
        return self::get(self::REQUIRE_BIN_SCAN, '1') !== '0';
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all_()[$key] ?? $default;
    }

    /** @param  array<string, ?string>  $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            self::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Cache::forget('settings.all');
    }

    /** @return array<string, ?string> */
    public static function all_(): array
    {
        return Cache::remember('settings.all', 300, fn () => self::pluck('value', 'key')->all());
    }
}
