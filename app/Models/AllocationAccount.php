<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank account in Finance → Income allocation. Only the last 4 digits of its number are kept.
 * Income is where receipts land; Tax is calculated; the split roles share what's left by weight.
 */
class AllocationAccount extends Model
{
    use Auditable;

    public const INCOME = 'income';

    public const TAX = 'tax';

    public const STOCK = 'stock';

    public const OWNERS_COMP = 'owners_comp';

    public const OPEX = 'opex';

    public const PROFIT = 'profit';

    public const VAULT = 'vault';

    public const DEBT_SERVICE = 'debt_service';

    /** Roles that take a weighted share of what's left after Tax. */
    public const SPLIT_ROLES = [self::STOCK, self::OWNERS_COMP, self::OPEX, self::PROFIT, self::VAULT, self::DEBT_SERVICE];

    protected $fillable = ['name', 'xero_code', 'purpose', 'weight', 'target_weight', 'institution', 'last4', 'is_active'];

    protected function casts(): array
    {
        return ['weight' => 'float', 'target_weight' => 'float', 'is_active' => 'boolean'];
    }

    public function isSplit(): bool
    {
        return in_array($this->role, self::SPLIT_ROLES, true);
    }

    /** Takes a share this run: a split role, switched on, with a weight above zero. */
    public function takesShare(): bool
    {
        return $this->isSplit() && $this->is_active && (float) $this->weight > 0;
    }

    /** "Stock" from "PF2 Stock", for transfer references. */
    public function shortName(): string
    {
        return trim(preg_replace('/^PF\d+\s+/i', '', $this->name)) ?: $this->name;
    }

    /** @return Collection<int, self> */
    public static function ordered()
    {
        return self::orderBy('sort')->orderBy('id')->get();
    }
}
