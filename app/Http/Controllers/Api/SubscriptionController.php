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
        try {
            $stripeSecret = config('services.stripe.secret');
            if ($stripeSecret) {
                Stripe::setApiKey($stripeSecret);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to set Stripe key in constructor: ' . $e->getMessage());
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
        $subscription = $request->user()->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription found',
            ], 404);
        }

        $subscription->load('plan');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $subscription->id,
                'plan' => [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'price' => $subscription->plan->price_monthly,
                    'interval' => 'month',
                    'features' => $subscription->plan->features ?? [],
                ],
                'status' => $subscription->status,
                'amount' => $subscription->amount,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'trial_ends_at' => $subscription->trial_ends_at,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
                'cancelled_at' => $subscription->cancelled_at,
            ],
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

        // CREATE INVOICE FOR TRIAL
        Invoice::create([
            'subscription_id' => $subscription->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'amount' => 0, // Trial is free
            'currency' => 'AUD',
            'status' => 'paid',
            'issued_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays($plan->trial_days),
            'notes' => 'Trial subscription invoice',
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

        $stripeSecret = config('services.stripe.secret');
        if (!$stripeSecret) {
            return response()->json([
                'success' => false,
                'message' => 'Stripe is not configured'
            ], 500);
        }

        Stripe::setApiKey($stripeSecret);

        $user = $request->user();
        $plan = SubscriptionPlan::find($request->plan_id);

        if (!$plan) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found'
            ], 404);
        }

        try {
            // Create a provisional subscription in our database
            $subscription = Subscription::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'plan_id' => $plan->id,
                    'status' => 'pending',  // Mark as pending until payment succeeds
                    'payment_method' => 'stripe',
                    'amount' => $plan->price_monthly,
                ]
            );

            // Create a payment link
            $paymentLink = \Stripe\PaymentLink::create([
                'line_items' => [
                    [
                        'price_data' => [
                            'currency' => 'aud',
                            'product_data' => [
                                'name' => $plan->name . ' Plan',
                                'description' => $plan->description ?? '',
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
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'url' => config('app.url') . '/subscription/success',
                    ],
                ],
                'metadata' => [
                    'subscription_id' => $subscription->id,  // Store our DB subscription ID
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                ],
            ]);

            Log::info('Payment link created', [
                'subscription_id' => $subscription->id,
                'payment_link' => $paymentLink->id,
            ]);

            return response()->json([
                'success' => true,
                'checkout_url' => $paymentLink->url,
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe payment link error', [
                'message' => $e->getMessage(),
                'user_id' => $user->id,
                'plan_id' => $plan->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout: ' . $e->getMessage()
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
            $plan = SubscriptionPlan::find($session->metadata->plan_id);
            $subscription = Subscription::create([
                'user_id' => $session->metadata->user_id,
                'plan_id' => $session->metadata->plan_id,
                'status' => 'active',
                'payment_method' => 'stripe',
                'amount' => $plan->price_monthly,
                'current_period_start' => Carbon::now(),
                'current_period_end' => Carbon::now()->addMonth(),
            ]);
        } else {
            $plan = $subscription->plan;
            $subscription->update([
                'stripe_subscription_id' => $session->subscription,
                'status' => 'active',
                'payment_method' => 'stripe',
                'amount' => $plan->price_monthly,
                'current_period_start' => Carbon::now(),
                'current_period_end' => Carbon::now()->addMonth(),
            ]);
        }

        // Record payment
        $payment = Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'amount' => $session->amount_total / 100,
            'currency' => strtoupper($session->currency),
            'status' => 'completed',
            'transaction_id' => $session->payment_intent,
            'payment_provider' => 'stripe',
            'processed_at' => Carbon::now(),
        ]);

        // CREATE INVOICE
        Invoice::create([
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
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
            Stripe::setApiKey(config('services.stripe.secret'));

            $refundAmount = 0;

            // Process prorated refund
            if ($subscription->stripe_subscription_id) {
                try {
                    // Get the latest invoice
                    $invoices = \Stripe\Invoice::all([
                        'subscription' => $subscription->stripe_subscription_id,
                        'limit' => 1,
                    ]);

                    if (!empty($invoices->data)) {
                        $invoice = $invoices->data[0];

                        // Calculate prorated refund
                        if ($invoice->paid && $invoice->amount_paid > 0) {
                            $now = now();
                            $periodEnd = \Carbon\Carbon::createFromTimestamp($invoice->period_end);

                            // Days remaining in billing cycle
                            $daysRemaining = $now->diffInDays($periodEnd);
                            $totalDaysInCycle = \Carbon\Carbon::createFromTimestamp($invoice->period_start)
                                ->diffInDays($periodEnd);

                            if ($daysRemaining > 0 && $totalDaysInCycle > 0) {
                                // Calculate prorated amount
                                $dailyRate = $invoice->amount_paid / $totalDaysInCycle;
                                $refundAmount = intval($dailyRate * $daysRemaining);

                                // Process refund
                                if ($refundAmount > 0) {
                                    \Stripe\Refund::create([
                                        'payment_intent' => $invoice->payment_intent,
                                        'amount' => $refundAmount,
                                    ]);

                                    Log::info('Prorated refund processed', [
                                        'subscription_id' => $subscription->id,
                                        'refund_amount' => $refundAmount / 100,
                                        'days_remaining' => $daysRemaining,
                                    ]);
                                }
                            }
                        }
                    }

                    // Cancel on Stripe
                    \Stripe\Subscription::retrieve($subscription->stripe_subscription_id)->cancel();
                } catch (\Exception $stripeError) {
                    Log::warning('Stripe cancellation: ' . $stripeError->getMessage());
                }
            }

            // Local cancellation
            $result = $this->cancellationService->cancelImmediately(
                $subscription,
                $request->reason
            );

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'refunded' => true,
                'refund_amount' => $refundAmount / 100,
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
     * Reactivate a cancelled subscription
     */
    public function reactivateSubscription(Request $request)
    {
        $subscription = $request->user()->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No subscription found',
            ], 404);
        }

        if ($subscription->status !== 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Subscription is not cancelled',
            ], 422);
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $chargeAmount = intval($subscription->plan->price_monthly * 100);
            $paymentMethodId = $subscription->payment_method_id;

            // If payment_method_id not saved, try to get it from Stripe customer
            if (!$paymentMethodId && $subscription->stripe_customer_id) {
                try {
                    $paymentMethods = \Stripe\PaymentMethod::all([
                        'customer' => $subscription->stripe_customer_id,
                        'type' => 'card',
                        'limit' => 1,
                    ]);

                    if (!empty($paymentMethods->data)) {
                        $paymentMethodId = $paymentMethods->data[0]->id;

                        // Save it for next time
                        $subscription->update(['payment_method_id' => $paymentMethodId]);

                        Log::info('Payment method retrieved from Stripe', [
                            'subscription_id' => $subscription->id,
                            'payment_method_id' => $paymentMethodId,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Could not retrieve payment method from Stripe: ' . $e->getMessage());
                }
            }

            if (!$paymentMethodId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No saved payment method found. Please add a payment method or contact support.',
                ], 422);
            }

            if ($subscription->stripe_customer_id) {
                try {
                    // Attach payment method to customer if needed
                    try {
                        \Stripe\PaymentMethod::retrieve($paymentMethodId)->attach([
                            'customer' => $subscription->stripe_customer_id,
                        ]);
                    } catch (\Exception $e) {
                        // Payment method might already be attached
                        Log::info('Payment method attach skipped: ' . $e->getMessage());
                    }

                    // Create payment intent
                    $paymentIntent = \Stripe\PaymentIntent::create([
                        'amount' => $chargeAmount,
                        'currency' => 'aud',
                        'customer' => $subscription->stripe_customer_id,
                        'payment_method' => $paymentMethodId,
                        'off_session' => true,
                        'confirm' => true,
                        'description' => 'Subscription reactivation - ' . $subscription->plan->name,
                        'metadata' => [
                            'subscription_id' => $subscription->id,
                            'reason' => 'reactivation',
                        ],
                    ]);

                    if ($paymentIntent->status !== 'succeeded') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Payment failed. Card declined or expired. Please update your payment method.',
                        ], 402);
                    }

                    // Record the payment
                    $payment = Payment::create([
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'amount' => $chargeAmount / 100,
                        'currency' => 'AUD',
                        'status' => 'completed',
                        'transaction_id' => $paymentIntent->id,
                        'payment_provider' => 'stripe',
                        'processed_at' => now(),
                    ]);

                    Log::info('Reactivation charge succeeded', [
                        'subscription_id' => $subscription->id,
                        'amount' => $chargeAmount / 100,
                        'payment_intent' => $paymentIntent->id,
                    ]);
                } catch (\Exception $chargeError) {
                    Log::error('Reactivation charge failed', [
                        'subscription_id' => $subscription->id,
                        'error' => $chargeError->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Payment declined: ' . $chargeError->getMessage(),
                    ], 402);
                }
            }

            // Reactivate subscription
            $subscription->update([
                'status' => 'active',
                'cancelled_at' => null,
                'cancel_at_period_end' => false,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
                'last_payment_at' => now(),
            ]);

            // CREATE INVOICE FOR REACTIVATION
            Invoice::create([
                'subscription_id' => $subscription->id,
                'payment_id' => $payment->id ?? null,
                'invoice_number' => Invoice::generateInvoiceNumber(),
                'amount' => $chargeAmount / 100,
                'currency' => 'AUD',
                'status' => 'paid',
                'issued_at' => Carbon::now(),
                'due_at' => Carbon::now()->addDays(30),
                'notes' => 'Subscription reactivation',
            ]);

            $subscription->load('plan');

            return response()->json([
                'success' => true,
                'message' => 'Subscription reactivated successfully',
                'data' => [
                    'id' => $subscription->id,
                    'plan' => [
                        'id' => $subscription->plan->id,
                        'name' => $subscription->plan->name,
                        'price' => $subscription->plan->price_monthly,
                    ],
                    'status' => $subscription->status,
                    'amount_charged' => $chargeAmount / 100,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reactivate subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reactivate subscription: ' . $e->getMessage(),
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

            $plan = $subscription->plan;
            $subscription->update([
                'status' => 'active',
                'stripe_subscription_id' => $session->subscription,
                'amount' => $plan->price_monthly,
                'current_period_start' => Carbon::now(),
                'current_period_end' => Carbon::now()->addMonth(),
            ]);

            $payment = Payment::where('transaction_id', $session->payment_intent)->first();

            if (!$payment) {
                $payment = Payment::create([
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
                    'payment_id' => $payment->id,
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

    /**
     * Verify payment - just fetch the subscription
     */
    public function verifyPayment(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        Log::info('=== VERIFY PAYMENT START ===', ['user_id' => $userId]);

        try {
            $subscription = $user->subscription;
            Log::info('After relationship query', ['subscription_id' => $subscription?->id ?? 'NULL']);

            if (!$subscription) {
                $subscription = Subscription::where('user_id', $userId)->latest()->first();
                Log::info('After direct query', ['subscription_id' => $subscription?->id ?? 'NULL']);
            }

            if (!$subscription) {
                Log::error('NO SUBSCRIPTION FOUND');
                return response()->json(['success' => false, 'message' => 'No subscription'], 404);
            }

            Log::info('Subscription found', [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'user_id' => $subscription->user_id,
            ]);

            $subscription->status = 'active';
            $subscription->current_period_start = now();
            $subscription->current_period_end = now()->addMonth();
            $subscription->save();

            Log::info('Subscription updated', ['status' => $subscription->status]);

            $subscription->load('plan');

            Log::info('=== VERIFY PAYMENT SUCCESS ===');

            return response()->json([
                'success' => true,
                'message' => 'Subscription verified',
                'data' => $subscription
            ]);
        } catch (\Throwable $e) {
            Log::error('=== VERIFY PAYMENT ERROR ===', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Create Setup Intent for saving payment method
     */
    public function createSetupIntent(Request $request)
    {
        $user = $request->user();

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $subscription = $user->subscription;
            $stripeCustomerId = $subscription?->stripe_customer_id;

            if (!$stripeCustomerId) {
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'name' => $user->name,
                    'metadata' => [
                        'user_id' => $user->id,
                    ],
                ]);
                $stripeCustomerId = $customer->id;

                if ($subscription) {
                    $subscription->update(['stripe_customer_id' => $stripeCustomerId]);
                }
            }

            $setupIntent = \Stripe\SetupIntent::create([
                'customer' => $stripeCustomerId,
                'usage' => 'off_session',
                'description' => 'Save payment method for ' . $user->email,
            ]);

            return response()->json([
                'success' => true,
                'client_secret' => $setupIntent->client_secret,
                'customer_id' => $stripeCustomerId,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create setup intent', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create setup intent: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Confirm Setup Intent after payment method is added
     */
    public function confirmSetupIntent(Request $request)
    {
        $request->validate([
            'setup_intent_id' => 'required|string',
        ]);

        $user = $request->user();

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $setupIntent = \Stripe\SetupIntent::retrieve($request->setup_intent_id);

            if ($setupIntent->status !== 'succeeded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method setup failed. Status: ' . $setupIntent->status,
                ], 400);
            }

            $paymentMethodId = $setupIntent->payment_method;
            $subscription = $user->subscription;

            if (!$subscription) {
                $defaultPlan = SubscriptionPlan::where('is_active', true)->first();

                if (!$defaultPlan) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No subscription plan found',
                    ], 404);
                }

                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'plan_id' => $defaultPlan->id,
                    'status' => 'trial',
                    'trial_ends_at' => Carbon::now()->addDays($defaultPlan->trial_days),
                ]);
            }

            $subscription->update([
                'payment_method_id' => $paymentMethodId,
                'stripe_customer_id' => $setupIntent->customer,
            ]);

            Log::info('Payment method saved successfully', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'payment_method_id' => $paymentMethodId,
                'customer_id' => $setupIntent->customer,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method saved successfully',
                'payment_method_id' => $paymentMethodId,
                'subscription_id' => $subscription->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to confirm setup intent', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save payment method: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get saved payment methods for current user
     */
    public function getPaymentMethods(Request $request)
    {
        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription || !$subscription->stripe_customer_id) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No payment methods saved',
            ]);
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            $paymentMethods = \Stripe\PaymentMethod::all([
                'customer' => $subscription->stripe_customer_id,
                'type' => 'card',
            ]);

            $formatted = array_map(function ($pm) use ($subscription) {
                return [
                    'id' => $pm->id,
                    'brand' => $pm->card->brand,
                    'last4' => $pm->card->last4,
                    'exp_month' => $pm->card->exp_month,
                    'exp_year' => $pm->card->exp_year,
                    'is_default' => $pm->id === $subscription->payment_method_id,
                ];
            }, $paymentMethods->data);

            return response()->json([
                'success' => true,
                'data' => $formatted,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch payment methods', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Could not fetch payment methods',
            ]);
        }
    }

    /**
     * Delete a payment method
     */
    public function deletePaymentMethod(Request $request, string $paymentMethodId)
    {
        $user = $request->user();
        $subscription = $user->subscription;

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No subscription found',
            ], 404);
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));

            \Stripe\PaymentMethod::retrieve($paymentMethodId)->detach();

            if ($subscription->payment_method_id === $paymentMethodId) {
                $subscription->update(['payment_method_id' => null]);
            }

            Log::info('Payment method deleted', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete payment method', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete payment method: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function handleSubscriptionCreated($stripeSubscription)
    {
        Log::info('handleSubscriptionCreated called', [
            'stripe_subscription_id' => $stripeSubscription->id,
        ]);

        try {
            $userId = $stripeSubscription->metadata['user_id'] ?? null;
            $subscriptionId = $stripeSubscription->metadata['subscription_id'] ?? null;

            if (!$userId || !$subscriptionId) {
                Log::warning('Missing metadata in subscription', [
                    'stripe_subscription_id' => $stripeSubscription->id,
                ]);
                return;
            }

            $subscription = Subscription::find($subscriptionId);

            if (!$subscription || $subscription->user_id != $userId) {
                Log::warning('Subscription not found or user mismatch', [
                    'subscription_id' => $subscriptionId,
                    'user_id' => $userId,
                ]);
                return;
            }

            $paymentMethodId = null;
            if ($stripeSubscription->default_payment_method) {
                $paymentMethodId = $stripeSubscription->default_payment_method;
            }

            $subscription->update([
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_customer_id' => $stripeSubscription->customer,
                'payment_method_id' => $paymentMethodId,
                'status' => 'active',
                'current_period_start' => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_start),
                'current_period_end' => \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end),
            ]);

            Log::info('✅ Subscription activated from webhook', [
                'subscription_id' => $subscription->id,
                'stripe_subscription_id' => $stripeSubscription->id,
                'payment_method_id' => $paymentMethodId,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in handleSubscriptionCreated', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function handleInvoicePaymentSucceeded($invoice)
    {
        Log::info('handleInvoicePaymentSucceeded called', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription,
        ]);

        try {
            if (!$invoice->subscription) {
                return;
            }

            $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

            if (!$subscription) {
                Log::warning('Subscription not found for invoice', [
                    'invoice_id' => $invoice->id,
                    'stripe_subscription_id' => $invoice->subscription,
                ]);
                return;
            }

            Payment::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'amount' => $invoice->amount_paid / 100,
                'currency' => strtoupper($invoice->currency),
                'status' => 'completed',
                'transaction_id' => $invoice->payment_intent,
                'payment_provider' => 'stripe',
                'processed_at' => \Carbon\Carbon::now(),
            ]);

            Log::info('Payment recorded from webhook', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $subscription->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing payment webhook', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleInvoicePaymentFailed($invoice)
    {
        Log::warning('Payment failed for invoice', [
            'invoice_id' => $invoice->id,
        ]);

        try {
            if (!$invoice->subscription) {
                return;
            }

            $subscription = Subscription::where('stripe_subscription_id', $invoice->subscription)->first();

            if (!$subscription) {
                return;
            }

            $subscription->update([
                'failed_payment_count' => ($subscription->failed_payment_count ?? 0) + 1,
            ]);

            Log::info('Failed payment count updated', [
                'subscription_id' => $subscription->id,
                'failed_count' => $subscription->failed_payment_count,
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling failed payment', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}