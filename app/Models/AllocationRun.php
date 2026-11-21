<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One allocation day's transfers, marked done. The snapshot is the calculation exactly as it was shown. */
class AllocationRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allocation_date' => 'date', 'period_from' => 'date', 'period_to' => 'date',
            'receipts_total' => 'decimal:2', 'snapshot' => 'array', 'done_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AllocationRunLine::class)->orderBy('id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(AllocationReceipt::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
