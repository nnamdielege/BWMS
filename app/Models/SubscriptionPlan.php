<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_monthly',
        'data_limit_gb',
        'max_users',
        'max_locations',
        'max_products',
        'features',
        'is_active',
        'trial_days',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'price_monthly' => 'decimal:2',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    public function usageLimits()
    {
        return $this->hasMany(SubscriptionLimit::class, 'plan_id');
    }
}