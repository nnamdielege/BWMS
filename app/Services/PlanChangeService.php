<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\SubscriptionAudit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;

class PlanChangeService
{
    /**
     * Calculate prorated amount for plan change
     */

    public function calculateProration(Subscription $subscription, SubscriptionPlan $newPlan)
    {
        $currentPlan = $subscription->plan;

        $billingCycleStart = Carbon::parse($subscription->current_period_start);
        $billingCycleEnd = Carbon::parse($subscription->current_period_end);
        $today = Carbon::now();

        $totalDaysInCycle = $billingCycleStart->diffInDays($billingCycleEnd);
        $daysRemaining = max(1, $today->diffInDays($billingCycleEnd));
        $daysPassed = $totalDaysInCycle - $daysRemaining;

        $currentDailyRate = $currentPlan->price_monthly / $totalDaysInCycle;
        $newDailyRate = $newPlan->price_monthly / $totalDaysInCycle;

        $amountAlreadyPaid = $currentDailyRate * $daysPassed;
        $shouldPayForRemaining = $newDailyRate * $daysRemaining;
        $proratedAmount = $shouldPayForRemaining - ($currentPlan->price_monthly - $amountAlreadyPaid);

        $isUpgrade = $newPlan->price_monthly > $currentPlan->price_monthly;

        return [
            'current_plan' => ['name' => $currentPlan->name, 'price' => $currentPlan->price_monthly],
            'new_plan' => ['name' => $newPlan->name, 'price' => $newPlan->price_monthly],
            'billing_cycle' => [
                'start' => $billingCycleStart->toDateString(),
                'end' => $billingCycleEnd->toDateString(),
                'days_remaining' => ceil($daysRemaining),
                'days_in_cycle' => $totalDaysInCycle,
            ],
            'calculation' => [
                'current_daily_rate' => round($currentDailyRate, 2),
                'new_daily_rate' => round($newDailyRate, 2),
                'days_passed' => $daysPassed,
                'should_pay_for_remaining' => round($shouldPayForRemaining, 2),
            ],
            'is_upgrade' => $isUpgrade,
            'is_downgrade' => !$isUpgrade,
            'amount_due' => $isUpgrade ? round(max(0, $proratedAmount), 2) : 0,
            'amount_credit' => !$isUpgrade ? round(max(0, -$proratedAmount), 2) : 0,
            'proration_charge' => $isUpgrade ? round(max(0, $proratedAmount), 2) : 0,
            'proration_credit' => !$isUpgrade ? round(max(0, -$proratedAmount), 2) : 0,
        ];
    }

    /**
     * Process plan change with Stripe
     */
    public function processStripeChange(Subscription $subscription, SubscriptionPlan $newPlan, array $prorationData)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $stripeSubscriptionId = $subscription->stripe_subscription_id;

        // Get current subscription to find the plan item ID
        $stripeSubscription = \Stripe\Subscription::retrieve(
            $stripeSubscriptionId,
            [],
            ['api_key' => config('services.stripe.secret')]
        );

        $planItem = $stripeSubscription->items->data[0];

        try {
            // Update the subscription with new price
            // Stripe automatically creates prorations
            $updated = \Stripe\Subscription::update(
                $stripeSubscriptionId,
                [
                    'items' => [
                        [
                            'id' => $planItem->id,
                            'price' => $newPlan->stripe_price_id,
                            'proration_behavior' => 'create_prorations',
                        ],
                    ],
                    'proration_date' => time(),
                    'billing_cycle_anchor' => 'unchanged',
                ],
                ['api_key' => config('services.stripe.secret')]
            );

            return [
                'success' => true,
                'stripe_subscription_id' => $updated->id,
                'status' => $updated->status,
            ];
        } catch (\Exception $e) {
            throw new \Exception('Stripe update failed: ' . $e->getMessage());
        }
    }

    /**
     * Complete plan change in database
     */
    public function completePlanChange(Subscription $subscription, SubscriptionPlan $newPlan, array $prorationData)
    {
        try {
            // Get old plan before updating
            $oldPlan = $subscription->plan;

            // Update subscription with new plan
            $subscription->update([
                'plan_id' => $newPlan->id,
                'amount' => $newPlan->price_monthly,
                'last_plan_change_at' => now(),
            ]);

            // Record the plan change in transactions
            if (method_exists($subscription, 'recordPlanChange') && $oldPlan) {
                $subscription->recordPlanChange($oldPlan, $newPlan, $prorationData);
            }

            Log::info('Plan change completed', [
                'subscription_id' => $subscription->id,
                'old_plan' => $oldPlan->name ?? 'Unknown',
                'new_plan' => $newPlan->name,
                'proration_charge' => $prorationData['proration_charge'],
                'proration_credit' => $prorationData['proration_credit'],
            ]);

            // Refresh the subscription to get updated plan relationship
            return $subscription->fresh();
        } catch (\Exception $e) {
            Log::error('Plan change completion failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}