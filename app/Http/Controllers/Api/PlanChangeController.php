<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

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
                'data' => $plans,
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
            'data' => $plans,
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
            Log::error('Calculate change failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

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

            // Validate proration data has required keys
            if (!isset($prorationData['proration_charge']) || !isset($prorationData['proration_credit'])) {
                throw new \Exception('Invalid proration data: missing required keys');
            }

            // Handle charges or credits
            if ($prorationData['proration_charge'] > 0) {
                $this->chargeUser($subscription->user, $prorationData['proration_charge']);
                Log::info('Upgrade charge processed', [
                    'user_id' => $user->id,
                    'amount' => $prorationData['proration_charge'],
                ]);
            } elseif ($prorationData['proration_credit'] > 0) {
                $this->applyCredit($subscription, $prorationData['proration_credit']);
                Log::info('Downgrade credit applied', [
                    'subscription_id' => $subscription->id,
                    'amount' => $prorationData['proration_credit'],
                ]);
            }

            // Update Stripe if applicable
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
                }
            }

            // Update local database
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
                    'proration' => [
                        'charge' => $prorationData['proration_charge'] ?? 0,
                        'credit' => $prorationData['proration_credit'] ?? 0,
                        'effective_date' => $prorationData['effective_date'] ?? now()->toDateString(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Plan change failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Plan change failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Charge user for plan upgrade proration
     */
    private function chargeUser(User $user, float $amount)
    {
        if (!$user->stripe_customer_id) {
            throw new \Exception('User does not have a Stripe customer ID');
        }

        try {
            $stripe = new StripeClient(config('services.stripe.secret'));

            $charge = $stripe->charges->create([
                'amount' => round($amount * 100),
                'currency' => strtolower(config('app.currency', 'aud')),
                'customer' => $user->stripe_customer_id,
                'description' => 'Plan upgrade proration charge',
                'metadata' => [
                    'user_id' => $user->id,
                    'type' => 'plan_change_proration',
                ],
            ]);

            Log::info('Proration charge created', [
                'user_id' => $user->id,
                'charge_id' => $charge->id,
                'amount' => $amount,
            ]);

            if (method_exists($user, 'transactions') && $user->transactions()) {
                $user->transactions()->create([
                    'type' => 'proration_charge',
                    'amount' => $amount,
                    'currency' => config('app.currency', 'aud'),
                    'stripe_charge_id' => $charge->id,
                    'status' => 'completed',
                    'description' => 'Plan upgrade proration',
                ]);
            }

            return $charge;
        } catch (ApiErrorException $e) {
            Log::error('Stripe charge failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'stripe_error_code' => $e->getStripeCode(),
            ]);

            throw new \Exception('Payment failed: ' . $e->getMessage());
        }
    }

    /**
     * Apply credit for plan downgrade proration
     */
    private function applyCredit(Subscription $subscription, float $creditAmount)
    {
        try {
            // Option 1: Add to user account credits
            if (method_exists($subscription->user, 'addCredit')) {
                $subscription->user->addCredit($creditAmount);
            }

            // Option 2: Create transaction record
            if (method_exists($subscription->user, 'transactions') && $subscription->user->transactions()) {
                $subscription->user->transactions()->create([
                    'type' => 'proration_credit',
                    'amount' => $creditAmount,
                    'currency' => config('app.currency', 'aud'),
                    'status' => 'completed',
                    'description' => 'Plan downgrade proration credit',
                ]);
            }

            // Option 3: Update subscription credit balance
            if (method_exists($subscription, 'addCreditBalance')) {
                $subscription->addCreditBalance($creditAmount);
            }

            Log::info('Proration credit applied', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'credit_amount' => $creditAmount,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Credit application failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to apply credit: ' . $e->getMessage());
        }
    }
}