<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A channel variant seen on an order with no listing yet (sync screen mapping queue). */
class ChannelUnmappedVariant extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
