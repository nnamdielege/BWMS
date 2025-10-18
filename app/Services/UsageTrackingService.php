<?php

namespace App\Services;

use App\Models\UsageTracking;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use Carbon\Carbon;

class UsageTrackingService
{
    /**
     * Track a user action
     */
    public function track(
        $userId,
        $actionType,
        $resourceType,
        $resourceId = null,
        $metadata = null
    ) {
        $subscription = Subscription::where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        UsageTracking::create([
            'user_id' => $userId,
            'subscription_id' => $subscription->id,
            'action_type' => $actionType,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
            'tracked_at' => now(),
        ]);

        return true;
    }

    /**
     * Check if user can perform an action
     */
    public function canPerformAction($userId, $actionType)
    {
        $subscription = Subscription::where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->first();

        if (!$subscription) {
            return [
                'allowed' => false,
                'reason' => 'No active subscription',
            ];
        }

        // Get the limit for this action
        $limit = SubscriptionLimit::where('plan_id', $subscription->plan_id)
            ->where('action_type', $actionType)
            ->first();

        // If no limit exists, allow the action
        if (!$limit) {
            return ['allowed' => true];
        }

        // Get current usage for this billing cycle
        $billingCycleStart = $this->getBillingCycleStart($subscription);
        $currentUsage = UsageTracking::where('subscription_id', $subscription->id)
            ->where('action_type', $actionType)
            ->where('tracked_at', '>=', $billingCycleStart)
            ->count();

        if ($currentUsage >= $limit->monthly_limit) {
            return [
                'allowed' => false,
                'reason' => "Limit reached for {$actionType}",
                'limit' => $limit->monthly_limit,
                'current' => $currentUsage,
            ];
        }

        return [
            'allowed' => true,
            'limit' => $limit->monthly_limit,
            'current' => $currentUsage,
            'remaining' => $limit->monthly_limit - $currentUsage,
        ];
    }

    /**
     * Get usage stats for a subscription
     */
    public function getUsageStats($userId)
    {
        $subscription = Subscription::where('user_id', $userId)
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->first();

        if (!$subscription) {
            return null;
        }

        $billingCycleStart = $this->getBillingCycleStart($subscription);

        // Get all limits for the plan
        $limits = SubscriptionLimit::where('plan_id', $subscription->plan_id)
            ->get();

        $stats = [];
        foreach ($limits as $limit) {
            $currentUsage = UsageTracking::where('subscription_id', $subscription->id)
                ->where('action_type', $limit->action_type)
                ->where('tracked_at', '>=', $billingCycleStart)
                ->count();

            $stats[] = [
                'action_type' => $limit->action_type,
                'description' => $limit->description,
                'limit' => $limit->monthly_limit,
                'current' => $currentUsage,
                'remaining' => max(0, $limit->monthly_limit - $currentUsage),
                'percentage' => round(($currentUsage / $limit->monthly_limit) * 100, 2),
            ];
        }

        return [
            'subscription_id' => $subscription->id,
            'plan_name' => $subscription->plan->name,
            'billing_cycle_start' => $billingCycleStart,
            'billing_cycle_end' => $this->getBillingCycleEnd($subscription),
            'stats' => $stats,
        ];
    }

    /**
     * Reset usage for expired billing cycles
     */
    public function resetExpiredUsage($subscriptionId)
    {
        $subscription = Subscription::find($subscriptionId);

        if (!$subscription || $subscription->status === 'cancelled') {
            return false;
        }

        $billingCycleEnd = $this->getBillingCycleEnd($subscription);

        if (now() < $billingCycleEnd) {
            return false; // Not time to reset yet
        }

        // Log the reset
        $subscription->resetLogs()->create([
            'reset_at' => now(),
        ]);

        return true;
    }

    /**
     * Get billing cycle start date
     */
    private function getBillingCycleStart(Subscription $subscription)
    {
        $currentDate = now();
        $billingDay = $subscription->billing_day ?? $subscription->created_at->day;

        // If we haven't reached the billing day this month yet
        if ($currentDate->day < $billingDay) {
            return $currentDate->copy()
                ->subMonth()
                ->day($billingDay)
                ->startOfDay();
        }

        return $currentDate->copy()
            ->day($billingDay)
            ->startOfDay();
    }

    /**
     * Get billing cycle end date
     */
    private function getBillingCycleEnd(Subscription $subscription)
    {
        $billingDay = $subscription->billing_day ?? $subscription->created_at->day;

        return now()->copy()
            ->addMonth()
            ->day($billingDay)
            ->startOfDay();
    }
}