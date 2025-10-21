<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\SubscriptionAudit;
use Carbon\Carbon;
use Stripe\Stripe;

class PlanChangeService
{
    /**
     * Calculate prorated amount for plan change
     */
    public function calculateProration(Subscription $subscription, SubscriptionPlan $newPlan)
    {
        $currentPlan = $subscription->plan;

        // Calculate days remaining in current billing cycle
        $now = Carbon::now();
        $periodEnd = Carbon::parse($subscription->current_period_end);
        $periodStart = Carbon::parse($subscription->current_period_start);

        // Use diffInDays to get whole days, not fractional days
        $daysInCycle = $periodStart->diffInDays($periodEnd);
        $daysRemaining = $now->diffInDays($periodEnd);

        // Handle case where we're past the period end
        if ($daysRemaining < 0) {
            $daysRemaining = 0;
        }

        // Calculate daily rates using price_monthly
        $currentDailyRate = $currentPlan->price_monthly / $daysInCycle;
        $newDailyRate = $newPlan->price_monthly / $daysInCycle;

        // Calculate what was already paid for the cycle
        $daysPassed = $daysInCycle - $daysRemaining;
        $alreadyPaid = $currentDailyRate * $daysPassed;

        // Calculate what user should pay for remaining days on new plan
        $shouldPayForRemaining = $newDailyRate * $daysRemaining;

        // Calculate the difference
        $amountDue = $shouldPayForRemaining - ($subscription->amount - $alreadyPaid);

        // Determine if upgrade or downgrade
        $isUpgrade = $newPlan->price_monthly > $currentPlan->price_monthly;
        $isDowngrade = $newPlan->price_monthly < $currentPlan->price_monthly;

        return [
            'is_upgrade' => $isUpgrade,
            'is_downgrade' => $isDowngrade,
            'current_plan' => [
                'id' => $currentPlan->id,
                'name' => $currentPlan->name,
                'price' => $currentPlan->price_monthly,
            ],
            'new_plan' => [
                'id' => $newPlan->id,
                'name' => $newPlan->name,
                'price' => $newPlan->price_monthly,
            ],
            'billing_cycle' => [
                'start' => $periodStart->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d'),
                'days_in_cycle' => (int) $daysInCycle,
                'days_remaining' => (int) $daysRemaining,
            ],
            'calculation' => [
                'current_daily_rate' => round($currentDailyRate, 2),
                'new_daily_rate' => round($newDailyRate, 2),
                'days_passed' => (int) $daysPassed,
                'already_paid' => round($alreadyPaid, 2),
                'should_pay_for_remaining' => round($shouldPayForRemaining, 2),
            ],
            'amount_due' => round($amountDue, 2),
            'amount_credit' => abs(round($amountDue, 2)) * ($amountDue < 0 ? 1 : 0),
            'reason' => $isUpgrade ? 'Upgrade charge' : 'Downgrade credit',
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
    public function completePlanChange(
        Subscription $subscription,
        SubscriptionPlan $newPlan,
        array $prorationData
    ) {
        $subscription->update([
            'plan_id' => $newPlan->id,
            'amount' => $newPlan->price_monthly,
            'plan_changed_at' => now(),
            'previous_plan_id' => $subscription->plan_id,
        ]);

        // Create audit log
        SubscriptionAudit::create([
            'subscription_id' => $subscription->id,
            'action' => 'plan_changed',
            'from_plan_id' => $prorationData['current_plan']['id'],
            'to_plan_id' => $prorationData['new_plan']['id'],
            'amount_charged' => $prorationData['amount_due'] > 0 ? $prorationData['amount_due'] : 0,
            'amount_credited' => $prorationData['amount_credit'],
            'reason' => $prorationData['reason'],
            'provider' => 'stripe',
            'metadata' => json_encode($prorationData),
        ]);

        return $subscription;
    }
}