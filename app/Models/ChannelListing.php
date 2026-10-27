<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product + pack size as listed on an external channel (Ordermentum variant).
 * Pushed quantity = floor(ATS_base / pack base_qty) - safety_buffer, never below 0.
 */
class ChannelListing extends Model
{
    use Auditable;

    /** Push/check bookkeeping is logged in sync_attempts, not the audit log. */
    protected array $auditExclude = [
        'last_pushed_qty', 'last_pushed_at', 'last_response_code', 'last_error', 'last_remote_qty', 'last_checked_at',
        'push_requested_at', 'push_force', 'remote_tracked', 'remote_status',
    ];

    protected $fillable = [
        'channel', 'product_id', 'product_unit_id', 'external_product_id', 'external_variant_id', 'external_sku',
        'external_location_id', 'sync_enabled', 'safety_buffer',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'push_force' => 'boolean',
            'remote_tracked' => 'boolean',
            'last_pushed_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * Flag enabled listings of these products for the channel's next push run.
     * Microsecond values, compared as raw strings when cleared, so a change that
     * lands during a push is never lost.
     */
    public static function requestPush(string $channel, iterable $productIds, bool $force = false): int
    {
        $ids = collect($productIds)->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return static::where('channel', $channel)->where('sync_enabled', true)->whereIn('product_id', $ids)
            ->update(array_filter(['push_requested_at' => now()->format('Y-m-d H:i:s.u'), 'push_force' => $force ?: null], fn ($v) => $v !== null));
    }

    /** Clears the flag only if nothing re-flagged the listing since it was read. */
    public function clearPushRequest(array $attributes = []): void
    {
        static::whereKey($this->id)->where('push_requested_at', $this->getRawOriginal('push_requested_at'))
            ->update(['push_requested_at' => null, 'push_force' => false]);
        if ($attributes !== []) {
            $this->forceFill($attributes)->save();
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}
