<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    protected $fillable = [
        'subscription_id',
        'user_id',
        'data_used_mb',
        'api_calls',
        'items_tracked',
        'reset_at',
        'recorded_date',
    ];

    protected $casts = [
        'reset_at' => 'datetime',
        'recorded_date' => 'date',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}