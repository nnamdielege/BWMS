<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Services\PlanChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlanChangeController extends Controller
{
    protected $planChangeService;

    public function __construct(PlanChangeService $planChangeService)
    {
        $this->planChangeService = $planChangeService;
    }

    /**
     * Get available plans for upgrade/downgrade
     */
    public function availablePlans(Request $request)
    {
        $subscription = $request->user()->subscription;

        // If no subscription or subscription is cancelled, return all active plans
        if (!$subscription || $subscription->status === 'cancelled') {
            $plans = SubscriptionPlan::where('is_active', true)
                ->get()
                ->map(fn($plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'price' => $plan->price_monthly,
                    'description' => $plan->description,
                    'features' => $plan->features ?? [],
                    'trial_days' => $plan->trial_days,
                    'interval' => 'month',
                ]);

            return response()->json([
                'success' => true,
                'data' => $plans,  // Keep as simple array for consistency
            ]);
        }

        $currentPlanId = $subscription->plan_id;

        $plans = SubscriptionPlan::where('is_active', true)
            ->get()
            ->map(fn($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price_monthly,
                'description' => $plan->description,
                'features' => $plan->features ?? [],
                'interval' => 'month',
                'type' => $plan->id == $currentPlanId ? 'current' : ($plan->price_monthly > $subscription->plan->price_monthly ? 'upgrade' : 'downgrade'),
            ]);

        return response()->json([
            'success' => true,
            'data' => $plans,  // Return same structure - just the array
        ]);
    }

    /**
     * Calculate proration for plan change
     */
    public function calculateChange(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
        ]);

        $subscription = $request->user()->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription',
            ], 404);
        }

        if ($subscription->plan_id == $request->plan_id) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a different plan',
            ], 422);
        }

        $newPlan = SubscriptionPlan::findOrFail($request->plan_id);

        try {
            $prorationData = $this->planChangeService->calculateProration(
                $subscription,
                $newPlan
            );

            return response()->json([
                'success' => true,
                'data' => $prorationData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate proration: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute plan change
     */
    public function changePlan(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
        ]);

        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription',
            ], 404);
        }

        if ($subscription->plan_id == $request->plan_id) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a different plan',
            ], 422);
        }

        try {
            $newPlan = SubscriptionPlan::findOrFail($request->plan_id);

            // Calculate proration
            $prorationData = $this->planChangeService->calculateProration(
                $subscription,
                $newPlan
            );

            // NOTE: Skip Stripe update if no stripe_subscription_id
            // For Payment Link subscriptions, we only update locally
            if ($subscription->stripe_subscription_id) {
                try {
                    $this->planChangeService->processStripeChange(
                        $subscription,
                        $newPlan,
                        $prorationData
                    );
                } catch (\Exception $stripeError) {
                    Log::warning('Stripe plan change failed, continuing with local update', [
                        'error' => $stripeError->getMessage(),
                    ]);
                    // Continue - local update is what matters
                }
            }

            // Update in local database (this is what matters)
            $subscription = $this->planChangeService->completePlanChange(
                $subscription,
                $newPlan,
                $prorationData
            );

            return response()->json([
                'success' => true,
                'message' => 'Plan updated successfully',
                'data' => [
                    'subscription' => [
                        'id' => $subscription->id,
                        'plan_id' => $subscription->plan_id,
                        'plan_name' => $subscription->plan->name,
                        'amount' => $subscription->amount,
                    ],
                    'proration' => $prorationData,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Plan change failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Plan change failed: ' . $e->getMessage()
            ], 500);
        }
    }
}