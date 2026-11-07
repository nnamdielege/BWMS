<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One area's deliveries on one date from one site: the unit of picking, packing and dispatch. */
class DeliveryRun extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_DISPATCHED = 'dispatched';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['run_date' => 'date', 'dispatched_at' => 'datetime'];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class, 'delivery_area_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'site_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function pickLists(): HasMany
    {
        return $this->hasMany(PickList::class);
    }

    /**
     * Open lists holding this run's orders. By order, not by delivery_run_id: a list
     * made on the desktop can cover more than one run.
     */
    public function openPickLists(): Builder
    {
        return PickList::where('status', PickList::STATUS_OPEN)
            ->whereHas('lines.orderLine.order', fn ($q) => $q->where('delivery_run_id', $this->id));
    }

    public function label(): string
    {
        return $this->run_date->format('D j M').' · '.$this->area->name;
    }
}
