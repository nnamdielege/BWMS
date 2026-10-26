<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stocktake extends Model
{
    use Auditable;

    public const STATUS_COUNTING = 'counting';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['number', 'scope_location_id', 'status', 'snapshot_at', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'snapshot_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'scope_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_COUNTING;
    }
}
