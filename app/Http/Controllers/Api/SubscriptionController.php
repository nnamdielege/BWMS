<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Payment;
use App\Models\Invoice;
use App\Services\SubscriptionCancellationService;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    protected $cancellationService;

    public function __construct(SubscriptionCancellationService $cancellationService)
    {
        // Set Stripe key from config, not env
        $key = config('services.stripe.secret');
        if ($key) {
            Stripe::setApiKey($key);
        }

        $this->cancellationService = $cancellationService;
    }
    /**
     * Get all subscription plans
     */
    public function getPlans()
    {
        $plans = SubscriptionPlan::where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => $plans
        ]);
    }

    /**
     * Get current user's subscription
     */
    public function getCurrentSubscription(Request $request)
    {
        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'subscription' => $subscription,
                'plan' => $subscription->plan,
                'status' => $subscription->status,
                'trial_days_remaining' => $subscription->trialDaysRemaining(),
                'data_usage_percentage' => $subscription->getDataUsagePercentage(),
                'is_active' => $subscription->isActive(),
                'is_in_trial' => $subscription->isInTrial(),
                'current_period_end' => $subscription->current_period_end,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'cancels_at' => $subscription->cancels_at,
                'cancellation_reason' => $subscription->cancellation_reason,
            ]
        ]);
    }

    /**
     * Start trial subscription
     */
    public function startTrial(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
        ]);

        $user = $request->user();

        // Check if user already has subscription
        if ($user->subscription && $user->subscription->status !== 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'User already has an active subscription'
            ], 422);
        }

        $plan = SubscriptionPlan::find($request->plan_id);

        // Create trial subscription
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'trial',
            'trial_ends_at' => Carbon::now()->addDays($plan->trial_days),
            'payment_method' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Trial started successfully',
            'data' => $subscription
        ]);
    }

    /**
     * Create Stripe checkout session
     */
    public function createStripeCheckout(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
        ]);

        $user = $request->user();
        $plan = SubscriptionPlan::find($request->plan_id);

        try {
            $session = \Stripe\Checkout\Session::create([
                'customer_email' => $user->email,
                'payment_method_types' => ['card'],
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => 'aud',
                            'product_data' => [
                                'name' => $plan->name . ' Plan',
                                'description' => $plan->description,
                            ],
                            'unit_amount' => intval($plan->price_monthly * 100),
                            'recurring' => [
                                'interval' => 'month',
                                'interval_count' => 1,
                            ],
                        ],
                        'quantity' => 1,
                    ],
                ],
                'mode' => 'subscription',
                'success_url' => config('app.url') . '/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => config('app.url') . '/subscription/cancel',
                'metadata' => [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                ],
            ]);

            return response()->json([
                'success' => true,
                'success_url' => config('app.url') . '/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'session_id' => $session->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Stripe webhook
     */
    public function handleStripeWebhook(Request $request)
    {
        $event = json_decode($request->getContent());

        try {
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutComplete($event->data->object);
                    break;

                case 'customer.subscription.created':
                    $this->handleSubscriptionCreated($event->data->object);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handleInvoicePaymentSucceeded($event->data->object);
                    break;

                case 'invoice.payment_failed':
                    $this->handleInvoicePaymentFailed($event->data->object);
                    break;
            }
        } catch (\Exception $e) {
            Log::error('Stripe webhook error: ' . $e->getMessage());
        }

        return response()->json(['success' => true]);
    }

    /**
     * Handle checkout completion
     */
    private function handleCheckoutComplete($session)
    {
        $subscription = Subscription::where('user_id', $session->metadata->user_id)->first();

        if (!$subscription) {
            $subscription = Subscription::create([
                'user_id' => $session->metadata->user_id,
                'plan_id' => $session->metadata->plan_id,
                'status' => 'active',
                'payment_method' => 'stripe',
            ]);
        }

        $subscription->update([
            'stripe_subscription_id' => $session->subscription,
            'status' => 'active',
            'payment_method' => 'stripe',
            'current_period_start' => Carbon::now(),
            'current_period_end' => Carbon::now()->addMonth(),
        ]);

        // Record payment
        Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'amount' => $session->amount_total / 100,
            'currency' => strtoupper($session->currency),
            'status' => 'completed',
            'transaction_id' => $session->payment_intent,
            'payment_provider' => 'stripe',
            'processed_at' => Carbon::now(),
        ]);

        // Generate invoice
        Invoice::create([
            'subscription_id' => $subscription->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'amount' => $session->amount_total / 100,
            'currency' => strtoupper($session->currency),
            'issued_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(30),
            'status' => 'paid',
        ]);
    }

    /**
     * Cancel subscription immediately
     * POST /api/v1/subscription/cancel
     */
    public function cancelSubscription(Request $request)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription'
            ], 404);
        }

        if ($subscription->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is already cancelled'
            ], 422);
        }

        try {
            // Try to cancel on Stripe (optional - local cancellation is what matters)
            if ($subscription->stripe_subscription_id) {
                try {
                    $key = config('services.stripe.secret');
                    if ($key) {
                        Stripe::setApiKey($key);
                        \Stripe\Subscription::retrieve($subscription->stripe_subscription_id)->cancel();
                    } else {
                        Log::warning('Stripe API key not configured, skipping Stripe cancellation');
                    }
                } catch (\Exception $stripeError) {
                    Log::warning('Stripe cancellation skipped: ' . $stripeError->getMessage());
                    // Continue - local cancellation is what matters
                }
            }

            // Local cancellation (this is what matters)
            $result = $this->cancellationService->cancelImmediately(
                $subscription,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'message' => $result['message']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription'
            ], 500);
        }
    }

    /**
     * Schedule cancellation at end of billing period
     * POST /api/v1/subscription/cancel-at-period-end
     */
    public function scheduleCancellation(Request $request)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription'
            ], 404);
        }

        if ($subscription->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is already cancelled'
            ], 422);
        }

        try {
            // Schedule Stripe cancellation if it exists
            if ($subscription->stripe_subscription_id) {
                \Stripe\Subscription::update(
                    $subscription->stripe_subscription_id,
                    ['cancel_at_period_end' => true]
                );
            }

            // Use cancellation service
            $result = $this->cancellationService->scheduleForCancellation(
                $subscription,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'cancels_at' => $result['cancels_at'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to schedule cancellation', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule cancellation: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reactivate scheduled cancellation
     * POST /api/v1/subscription/reactivate
     */
    public function reactivateSubscription(Request $request)
    {
        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription'
            ], 404);
        }

        if (!$subscription->cancel_at_period_end) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is not scheduled for cancellation'
            ], 422);
        }

        try {
            // Reactivate Stripe subscription if it exists
            if ($subscription->stripe_subscription_id) {
                \Stripe\Subscription::update(
                    $subscription->stripe_subscription_id,
                    ['cancel_at_period_end' => false]
                );
            }

            // Use cancellation service
            $result = $this->cancellationService->reactivate($subscription);

            return response()->json([
                'success' => true,
                'message' => $result['message']
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reactivate subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reactivate subscription: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get invoices
     */
    public function getInvoices(Request $request)
    {
        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription'
            ], 404);
        }

        $invoices = $subscription->invoices()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $invoices
        ]);
    }

    /**
     * Get usage
     */
    public function getUsage(Request $request)
    {
        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription'
            ], 404);
        }

        $usage = $subscription->usageRecords()
            ->whereDate('recorded_date', '>=', Carbon::now()->startOfMonth())
            ->get();

        $totalDataUsed = $usage->sum('data_used_mb') / 1024;
        $totalApiCalls = $usage->sum('api_calls');
        $dataLimit = $subscription->plan->data_limit_gb;

        return response()->json([
            'success' => true,
            'data' => [
                'data_used_gb' => round($totalDataUsed, 2),
                'data_limit_gb' => $dataLimit,
                'data_percentage' => round(($totalDataUsed / $dataLimit) * 100, 2),
                'api_calls' => $totalApiCalls,
                'daily_records' => $usage,
            ]
        ]);
    }

    /**
     * Handle Stripe payment success
     */
    public function handleStripeSuccess(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        $user = $request->user();
        $sessionId = $request->session_id;

        try {
            $session = \Stripe\Checkout\Session::retrieve($sessionId);

            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed'
                ], 400);
            }

            $subscription = Subscription::where('stripe_subscription_id', $session->subscription)
                ->orWhere('user_id', $user->id)
                ->first();

            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription not found'
                ], 404);
            }

            if ($subscription->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $subscription->update([
                'status' => 'active',
                'stripe_subscription_id' => $session->subscription,
                'current_period_start' => Carbon::now(),
                'current_period_end' => Carbon::now()->addMonth(),
            ]);

            $payment = Payment::where('transaction_id', $session->payment_intent)->first();

            if (!$payment) {
                Payment::create([
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                    'amount' => $session->amount_total / 100,
                    'currency' => strtoupper($session->currency),
                    'status' => 'completed',
                    'transaction_id' => $session->payment_intent,
                    'payment_provider' => 'stripe',
                    'processed_at' => Carbon::now(),
                ]);
            }

            $invoice = Invoice::where('subscription_id', $subscription->id)
                ->where('status', 'paid')
                ->first();

            if (!$invoice) {
                Invoice::create([
                    'subscription_id' => $subscription->id,
                    'invoice_number' => Invoice::generateInvoiceNumber(),
                    'amount' => $session->amount_total / 100,
                    'currency' => strtoupper($session->currency),
                    'issued_at' => Carbon::now(),
                    'due_at' => Carbon::now()->addDays(30),
                    'status' => 'paid',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully',
                'data' => [
                    'subscription' => $subscription,
                    'plan' => $subscription->plan,
                    'status' => $subscription->status,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe success verification error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify payment: ' . $e->getMessage()
            ], 500);
        }
    }

    private function handleSubscriptionCreated($stripeSubscription) {}
    private function handleInvoicePaymentSucceeded($invoice) {}
    private function handleInvoicePaymentFailed($invoice) {}
}