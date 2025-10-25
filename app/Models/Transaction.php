<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'subscription_id',
        'type',
        'amount',
        'currency',
        'stripe_charge_id',
        'status',
        'description',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}