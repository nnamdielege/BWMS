<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The purchasing budget (ex GST) for one Brisbane Mon–Sun week, keyed by its Monday. */
class PurchasingBudget extends Model
{
    use Auditable;

    protected $fillable = ['week_start', 'budget', 'notes'];

    protected function casts(): array
    {
        return ['week_start' => 'date', 'budget' => 'decimal:2'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
