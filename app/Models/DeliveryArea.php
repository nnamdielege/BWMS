<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A delivery area: delivered on fixed weekdays, optionally next-day on any weekday. */
class DeliveryArea extends Model
{
    use Auditable;

    protected $fillable = ['code', 'name', 'weekdays', 'next_day_eligible', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'next_day_eligible' => 'boolean', 'is_active' => 'boolean'];
    }

    public function places(): HasMany
    {
        return $this->hasMany(DeliveryAreaPlace::class)->orderBy('postcode')->orderBy('suburb');
    }

    /** ISO weekday (1 = Monday) on which this area has a scheduled run. */
    public function deliversOn(int $isoWeekday): bool
    {
        return in_array($isoWeekday, array_map('intval', $this->weekdays ?? []), true);
    }
}
