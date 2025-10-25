<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'paused_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'stripe_subscription_id',
        'paypal_subscription_id',
        'payment_method',
        'payment_method_id',
        'cancel_at_period_end',
        'cancelled_at',
        'stripe_customer_id',
        'last_payment_at',
        'failed_payment_count',

    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'last_payment_at' => 'datetime',
        'paused_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function usageRecords()
    {
        return $this->hasMany(UsageRecord::class);
    }

    // Check if user is in trial period
    public function isInTrial()
    {
        return $this->status === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    // Check if subscription is active
    public function isActive()
    {
        return $this->status === 'active' && (!$this->current_period_end || $this->current_period_end->isFuture());
    }

    /**
     * Get days remaining in trial
     */
    public function trialDaysRemaining()
    {
        if (!$this->isInTrial()) return 0;

        $daysRemaining = $this->trial_ends_at->diffInDays(Carbon::now(), absolute: true);
        return (int) ceil($daysRemaining);
    }

    // Get data usage percentage
    public function getDataUsagePercentage()
    {
        $limit = $this->plan->data_limit_gb * 1024; // Convert to MB
        $used = $this->usageRecords()->sum('data_used_mb');
        return round(($used / $limit) * 100, 2);
    }

    public function usageTracking()
    {
        return $this->hasMany(UsageTracking::class);
    }

    public function resetLogs()
    {
        return $this->hasMany(UsageResetLog::class);
    }
}