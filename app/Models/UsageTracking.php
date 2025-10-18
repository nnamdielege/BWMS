<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageTracking extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'action_type',
        'resource_type',
        'resource_id',
        'metadata',
        'tracked_at',
    ];

    protected $casts = [
        'metadata' => 'json',
        'tracked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}