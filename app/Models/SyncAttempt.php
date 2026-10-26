<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Log of one call to a channel API (B7). Kept 90 days (NFR-12). */
class SyncAttempt extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['request' => 'array', 'success' => 'boolean'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(90));
    }
}
