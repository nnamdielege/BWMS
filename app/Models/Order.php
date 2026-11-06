<?php

namespace App\Models;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use Auditable;

    protected $fillable = ['customer_id', 'site_id', 'requested_delivery_date', 'notes', 'short_ship_policy'];

    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'status' => OrderStatus::class,
            'oversold' => 'boolean',
            'needs_review' => 'boolean',
            'oversold_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'requested_delivery_date' => 'date',
            'delivery_date' => 'date',
            'review_notes' => 'array',
            'external_version' => 'integer',
        ];
    }

    /**
     * Raise a review reason (not saved). The same reason raised again changes nothing,
     * so one already marked handled stays handled; $always is for events that can recur.
     */
    public function flag(string $key, string $message, bool $always = false): void
    {
        $notes = $this->review_notes ?? [];
        if (! $always && ($notes[$key] ?? null) === $message) {
            return;
        }
        $notes[$key] = $message;
        $this->forceFill(['review_notes' => $notes, 'needs_review' => true]);
    }

    /** A reason that no longer holds (not saved). The last one going takes the flag with it. */
    public function unflag(string $key): void
    {
        $notes = $this->review_notes ?? [];
        if (! array_key_exists($key, $notes)) {
            return;
        }
        unset($notes[$key]);
        $this->forceFill($notes === [] ? ['review_notes' => null, 'needs_review' => false] : ['review_notes' => $notes]);
    }

    public function deliveryRun(): BelongsTo
    {
        return $this->belongsTo(DeliveryRun::class);
    }

    public function packs(): HasMany
    {
        return $this->hasMany(OrderPack::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'site_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
