<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageResetLog extends Model
{
    protected $fillable = ['subscription_id', 'reset_at'];

    protected $casts = ['reset_at' => 'datetime'];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}