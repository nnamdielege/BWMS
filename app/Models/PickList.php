<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PickList extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime'];
    }

    public function deliveryRun(): BelongsTo
    {
        return $this->belongsTo(DeliveryRun::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'site_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PickListLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * How far along this list is: the one status every screen shows, whichever
     * screen did the work. `status` alone only knows open, dispatched or cancelled.
     *
     * @return array{key: string, label: string}
     */
    public function stage(): array
    {
        if ($this->status !== self::STATUS_OPEN) {
            return ['key' => $this->status, 'label' => ucfirst($this->status)];
        }

        $lines = $this->lines()->count();
        $done = $this->lines()->whereNotNull('qty_picked_base')->count();
        if ($done === 0) {
            return ['key' => 'not_started', 'label' => 'Not started'];
        }
        if ($done < $lines) {
            return ['key' => 'picking', 'label' => "Picking {$done}/{$lines}"];
        }

        $orders = $this->lines()->join('order_lines', 'order_lines.id', '=', 'pick_list_lines.order_line_id')->distinct()->count('order_lines.order_id');
        $packed = OrderPack::where('pick_list_id', $this->id)->count();

        return $packed >= $orders ? ['key' => 'packed', 'label' => 'Packed, ready to dispatch'] : ['key' => 'picked', 'label' => 'Picked, ready to pack'];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
