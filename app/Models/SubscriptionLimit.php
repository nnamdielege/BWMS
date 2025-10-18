<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionLimit extends Model
{
    protected $fillable = [
        'plan_id',
        'action_type',
        'monthly_limit',
        'description',
    ];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}