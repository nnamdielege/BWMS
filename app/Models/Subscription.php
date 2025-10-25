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
        'stripe_item_id',
        'paypal_subscription_id',
        'payment_method',
        'payment_method_id',
        'cancel_at_period_end',
        'cancelled_at',
        'stripe_customer_id',
        'last_payment_at',
        'failed_payment_count',
        'amount',
        'credit_balance',
        'last_plan_change_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'last_payment_at' => 'datetime',
        'paused_at' => 'datetime',
        'last_plan_change_at' => 'datetime',
        'amount' => 'decimal:2',
        'credit_balance' => 'decimal:2',
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

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function usageTracking()
    {
        return $this->hasMany(UsageTracking::class);
    }

    public function resetLogs()
    {
        return $this->hasMany(UsageResetLog::class);
    }

    // ===== Existing Methods =====

    /**
     * Check if user is in trial period
     */
    public function isInTrial()
    {
        return $this->status === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if subscription is active
     */
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

    /**
     * Get data usage percentage
     */
    public function getDataUsagePercentage()
    {
        $limit = $this->plan->data_limit_gb * 1024; // Convert to MB
        $used = $this->usageRecords()->sum('data_used_mb');
        return round(($used / $limit) * 100, 2);
    }

    // ===== Plan Change Methods =====

    /**
     * Add credit balance (for downgrades)
     */
    public function addCreditBalance(float $amount)
    {
        $this->credit_balance = ($this->credit_balance ?? 0) + $amount;
        return $this->save();
    }

    /**
     * Deduct credit balance (when using credits)
     */
    public function deductCreditBalance(float $amount)
    {
        if (($this->credit_balance ?? 0) >= $amount) {
            $this->credit_balance -= $amount;
            return $this->save();
        }
        return false;
    }

    /**
     * Get available credit balance
     */
    public function getAvailableCredit()
    {
        return $this->credit_balance ?? 0;
    }

    /**
     * Check if subscription has available credits
     */
    public function hasAvailableCredit()
    {
        return ($this->credit_balance ?? 0) > 0;
    }

    /**
     * Get days remaining in current billing cycle
     */
    public function daysRemainingInCycle()
    {
        if (!$this->current_period_end) {
            return 0;
        }

        $daysRemaining = Carbon::now()->diffInDays($this->current_period_end, absolute: true);
        return max(1, (int) ceil($daysRemaining));
    }

    /**
     * Get total days in current billing cycle
     */
    public function totalDaysInCycle()
    {
        if (!$this->current_period_start || !$this->current_period_end) {
            return 30; // Default to 30 days
        }

        $totalDays = $this->current_period_start->diffInDays($this->current_period_end);
        return max(1, (int) $totalDays);
    }

    /**
     * Calculate the daily rate for current plan
     */
    public function getDailyRate()
    {
        if (!$this->amount || !$this->plan) {
            return 0;
        }

        $totalDays = $this->totalDaysInCycle();
        return round($this->amount / $totalDays, 4);
    }

    /**
     * Check if subscription can be changed
     */
    public function canChangePlan()
    {
        return $this->isActive() &&
            $this->current_period_end &&
            $this->current_period_end->isFuture();
    }

    /**
     * Get plan change history
     */
    public function getPlanChangeHistory()
    {
        return $this->transactions()
            ->where('type', 'plan_change')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Record a plan change in transactions
     */
    public function recordPlanChange($oldPlan, $newPlan, $prorationData)
    {
        return $this->transactions()->create([
            'type' => 'plan_change',
            'amount' => $prorationData['proration_charge'] ?? $prorationData['proration_credit'] ?? 0,
            'currency' => config('app.currency', 'aud'),
            'status' => 'completed',
            'description' => sprintf(
                'Plan changed from %s to %s',
                $oldPlan->name ?? 'Unknown',
                $newPlan->name ?? 'Unknown'
            ),
            'metadata' => [
                'old_plan_id' => $oldPlan->id,
                'new_plan_id' => $newPlan->id,
                'old_plan_price' => $oldPlan->price_monthly,
                'new_plan_price' => $newPlan->price_monthly,
                'proration_charge' => $prorationData['proration_charge'] ?? 0,
                'proration_credit' => $prorationData['proration_credit'] ?? 0,
            ],
        ]);
    }

    /**
     * Scope: Get active subscriptions
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: Get cancelled subscriptions
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Scope: Get trial subscriptions
     */
    public function scopeTrial($query)
    {
        return $query->where('status', 'trial');
    }

    /**
     * Scope: Get paused subscriptions
     */
    public function scopePaused($query)
    {
        return $query->where('status', 'paused');
    }
}