<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money that landed in the Income account, waiting for an allocation run (allocation_run_id null) or taken by one. */
class AllocationReceipt extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function scopeWaiting(Builder $q): Builder
    {
        return $q->whereNull('allocation_run_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AllocationRun::class, 'allocation_run_id');
    }
}
