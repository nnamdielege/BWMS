<?php

namespace App\Services;

use App\Mail\PaymentSuccessfulMail;
use App\Mail\PaymentFailedMail;
use App\Mail\SubscriptionActivatedMail;
use App\Mail\SubscriptionSuspendedMail;
use App\Mail\TrialEndingSoonMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Exception;

class StripeWebhookService
{
    /**
     * Process webhook event
     */
    public function process(WebhookEvent $webhookEvent)
    {
        try {
            $payload = $webhookEvent->payload;
            $eventType = $webhookEvent->event_type;

            Log::info('Processing webhook', [
                'event_type' => $eventType,
                'event_id' => $webhookEvent->stripe_event_id
            ]);

            // Route to appropriate handler
            switch ($eventType) {
                case 'checkout.session.completed':
                    $this->handleCheckoutComplete($payload);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handleInvoicePaymentSucceeded($payload);
                    break;

                case 'invoice.payment_failed':
                    $this->handleInvoicePaymentFailed($payload);
                    break;

                case 'customer.subscription.created':
                    $this->handleSubscriptionCreated($payload);
                    break;

                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($payload);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($payload);
                    break;

                case 'customer.subscription.trial_will_end':
                    $this->handleTrialWillEnd($payload);
                    break;

                default:
                    Log::info('Unhandled webhook event type', ['type' => $eventType]);
            }

            $webhookEvent->markAsProcessed();
        } catch (Exception $e) {
            Log::error('Webhook processing failed', [
                'webhook_id' => $webhookEvent->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $webhookEvent->markAsFailed($e->getMessage());

            throw $e;
        }
    }


    /**
     * Handle checkout completion
     */
    protected function handleCheckoutComplete(array $payload)
    {
        // ⚠️ CHANGE #1 (LINES 92-99):
        // CHANGED: From throwing exception to using warning + return
        // REASON: Not all checkout sessions have metadata. If missing, just skip gracefully
        // BEFORE: if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
        //            throw new Exception('Missing or invalid metadata in checkout session payload');
        // AFTER:
        if (!isset($payload['metadata']) || !is_array($payload['metadata'])) {
            Log::warning('⚠️ Checkout session has no metadata - skipping processing', [
                'session_id' => $payload['id'] ?? 'unknown',
                'payload_keys' => array_keys($payload),
            ]);
            return; // Exit gracefully instead of throwing
        }

        // VALIDATION: Check for required metadata fields
        if (!isset($payload['metadata']['user_id'])) {
            Log::warning('⚠️ Checkout metadata missing user_id - skipping processing', [
                'session_id' => $payload['id'] ?? 'unknown',
                'metadata_keys' => array_keys($payload['metadata']),
            ]);
            return; // Exit gracefully instead of throwing
        }

        if (!isset($payload['metadata']['plan_id'])) {
            Log::warning('⚠️ Checkout metadata missing plan_id - skipping processing', [
                'session_id' => $payload['id'] ?? 'unknown',
                'metadata_keys' => array_keys($payload['metadata']),
            ]);
            return; // Exit gracefully instead of throwing
        }

        Log::info('handleCheckoutComplete called', [
            'session_id' => $payload['id'],
            'user_id' => $payload['metadata']['user_id'],
        ]);

        $subscription = Subscription::where('user_id', $payload['metadata']['user_id'])->first();

        if (!$subscription) {
            $plan = SubscriptionPlan::find($payload['metadata']['plan_id']);

            if (!$plan) {
                throw new \Exception('Plan not found: ' . $payload['metadata']['plan_id']);
            }

            $subscription = Subscription::create([
                'user_id' => $payload['metadata']['user_id'],
                'plan_id' => $payload['metadata']['plan_id'],
                'status' => 'active',
                'payment_method' => 'stripe',
                'amount' => $plan->price_monthly,
                'current_period_start' => Carbon::now(),
                'current_period_end' => Carbon::now()->addMonth(),
            ]);
        } else {
            $plan = $subscription->plan;
            $subscription->update([
                'stripe_subscription_id' => $payload['subscription'] ?? null,
                'status' => 'active',
                'payment_method' => 'stripe',
                'amount' => $plan->price_monthly,
                'current_period_start' => Carbon::now(),
                'current_period_end' => Carbon::now()->addMonth(),
            ]);
        }

        // Record payment
        // ⚠️ CHANGE #2 (LINE 157):
        // CHANGED: Added null coalescing operator
        // REASON: If amount_total is missing from payload, use 0 instead of crashing
        // BEFORE: 'amount' => $payload['amount_total'] / 100,
        // AFTER:
        $payment = Payment::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'amount' => ($payload['amount_total'] ?? 0) / 100, // ← ADDED ?? 0
            'currency' => strtoupper($payload['currency'] ?? 'AUD'),
            'status' => 'completed',
            'transaction_id' => $payload['payment_intent'] ?? null,
            'payment_provider' => 'stripe',
            'processed_at' => Carbon::now(),
        ]);

        // CREATE INVOICE
        Invoice::create([
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'amount' => (float)(($payload['amount_total'] ?? 0) / 100),
            'currency' => strtoupper($payload['currency'] ?? 'AUD'),
            'issued_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(30),
            'status' => 'paid',
        ]);

        Log::info('✅ Checkout completed and invoice created', [
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
        ]);
    }

    /**
     * Handle successful invoice payment (recurring payments)
     */
    protected function handleInvoicePaymentSucceeded(array $payload)
    {
        // ⚠️ CHANGE #3 (LINE 188):
        // CHANGED: Removed ['data']['object'] wrapper
        // REASON: Payload is NOW already extracted as the invoice object (not wrapped in Stripe event envelope)
        // BEFORE: $invoice = $payload['data']['object'];
        // AFTER:
        $invoice = $payload; // ← REMOVED ['data']['object']

        // Get subscription ID from nested path
        // ⚠️ CHANGE #4 (LINE 191):
        // CHANGED: Simplified nested path extraction
        // REASON: Invoice now has direct 'subscription' field, not nested under parent.subscription_details
        // BEFORE: $stripeSubscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;
        // AFTER:
        $stripeSubscriptionId = $invoice['subscription'] ?? null; // ← SIMPLIFIED PATH

        Log::info('handleInvoicePaymentSucceeded called', [
            'invoice_id' => $invoice['id'] ?? null,
            'subscription_id' => $stripeSubscriptionId,
        ]);

        if (!$stripeSubscriptionId) {
            Log::warning('No subscription in invoice', ['invoice_id' => $invoice['id'] ?? null]);
            return;
        }

        // Find subscription via the user relationship
        $subscription = Subscription::where('status', 'pending')
            ->with('user')
            ->get()
            ->first(function ($sub) use ($invoice) {
                return $sub->user->email === ($invoice['customer_email'] ?? '');
            });

        if (!$subscription) {
            Log::warning('Pending subscription not found', [
                'customer_email' => $invoice['customer_email'] ?? 'unknown',
            ]);
            return;
        }

        // Record payment - USE LOCAL SUBSCRIPTION ID, NOT STRIPE ID
        $payment = Payment::create([
            'subscription_id'     => $subscription->id,  // Use local ID, not $stripeSubscriptionId
            'user_id'             => $subscription->user_id,  // Get from subscription, not invoice
            'amount'              => (float)(($invoice['amount_paid'] ?? 0) / 100),
            'currency'            => strtoupper($invoice['currency'] ?? 'AUD'),
            'status'              => 'completed',
            'transaction_id'      => $invoice['payment_intent'] ?? null,
            'payment_provider'    => 'stripe',
            'processed_at'        => Carbon::now(),
        ]);

        // Create invoice record - USE LOCAL SUBSCRIPTION ID
        Invoice::create([
            'subscription_id' => $subscription->id,  // Use local ID
            'payment_id'      => $payment->id,
            'invoice_number'  => Invoice::generateInvoiceNumber(),
            'amount'          => (float)(($invoice['amount_paid'] ?? 0) / 100),
            'currency'        => strtoupper($invoice['currency'] ?? 'AUD'),
            'issued_at'       => Carbon::createFromTimestamp($invoice['created']),
            'due_at'          => Carbon::createFromTimestamp($invoice['due_date'] ?? $invoice['created'])->addDays(30),
            'status'          => 'paid',
        ]);

        DB::transaction(function () use ($subscription, $invoice, $stripeSubscriptionId) {
            $wasInTrial = $subscription->isInTrial();

            // Update subscription
            $subscription->update([
                'stripe_subscription_id' => $stripeSubscriptionId,
                'stripe_customer_id' => $invoice['customer'],
                'status' => 'active',
                'current_period_start' => Carbon::createFromTimestamp($invoice['period_start'] ?? $invoice['created']),
                'current_period_end' => Carbon::createFromTimestamp($invoice['period_end'] ?? $invoice['created']),
            ]);

            // Send payment successful email
            try {
                Mail::to($subscription->user->email)->send(
                    new PaymentSuccessfulMail($subscription, ($invoice['amount_paid'] ?? 0) / 100)
                );

                Log::info('✅ Payment successful email sent', [
                    'user_email' => $subscription->user->email,
                    'amount' => ($invoice['amount_paid'] ?? 0) / 100,
                ]);
            } catch (Exception $e) {
                Log::error('❌ Failed to send payment success email', [
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('✅ Subscription payment successful', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'amount' => ($invoice['amount_paid'] ?? 0) / 100,
                'currency' => strtoupper($invoice['currency'] ?? 'AUD'),
                'was_in_trial' => $wasInTrial,
            ]);
        });
    }

    /**
     * Handle failed invoice payment
     */
    protected function handleInvoicePaymentFailed(array $payload)
    {
        $invoice = $payload;

        $stripeSubscriptionId = $invoice['subscription'] ?? null;

        if (!$stripeSubscriptionId) {
            Log::warning('No subscription found in failed payment invoice');
            return;
        }

        // Find subscription by Stripe subscription ID
        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

        if (!$subscription) {
            Log::warning('Subscription not found for failed payment', ['stripe_subscription_id' => $stripeSubscriptionId]);
            return;
        }

        DB::transaction(function () use ($subscription, $invoice) {
            $amount = ($invoice['amount_due'] ?? 0) / 100;
            $failedCount = ($invoice['attempt_count'] ?? 0);
            $maxRetries = 3;

            // Suspend after max retries
            if ($failedCount >= $maxRetries) {
                $subscription->update([
                    'status' => 'suspended',
                    'cancelled_at' => now(),
                ]);

                Log::warning('⚠️ Subscription suspended due to payment failures', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'failed_attempts' => $failedCount,
                ]);

                // Send suspension email
                try {
                    Mail::to($subscription->user->email)->send(
                        new SubscriptionSuspendedMail($subscription)
                    );

                    Log::info('✅ Subscription suspended email sent', [
                        'user_email' => $subscription->user->email,
                    ]);
                } catch (Exception $e) {
                    Log::error('❌ Failed to send suspension email', [
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // Send payment failed email
                try {
                    Mail::to($subscription->user->email)->send(
                        new PaymentFailedMail($subscription, $amount)
                    );

                    Log::info('✅ Payment failed email sent', [
                        'user_email' => $subscription->user->email,
                        'failed_count' => $failedCount,
                    ]);
                } catch (Exception $e) {
                    Log::error('❌ Failed to send payment failed email', [
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::warning('Payment failed', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'failed_count' => $failedCount,
                    'attempt_count' => $invoice['attempt_count'] ?? 0,
                ]);
            }
        });
    }

    /**
     * Handle subscription creation
     */
    protected function handleSubscriptionCreated(array $payload)
    {
        // ⚠️ CHANGE #5 (LINE 392):
        // CHANGED: Removed ['data']['object'] wrapper
        // REASON: Payload is NOW already the subscription object
        // BEFORE: $stripeSubscription = $payload['data']['object'];
        // AFTER:
        $stripeSubscription = $payload; // ← REMOVED ['data']['object']
        $stripeSubscriptionId = $stripeSubscription['id'];
        $stripeCustomerId = $stripeSubscription['customer'];

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

        if ($subscription) {
            $subscription->update([
                'status' => $stripeSubscription['status'],
                'stripe_customer_id' => $stripeCustomerId,
            ]);

            Log::info('Subscription created via webhook', [
                'subscription_id' => $subscription->id,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
        }
    }

    /**
     * Handle subscription updates
     */
    protected function handleSubscriptionUpdated(array $payload)
    {
        // ⚠️ CHANGE #6 (LINE 416):
        // CHANGED: Removed ['data']['object'] wrapper
        // REASON: Payload is NOW already the subscription object
        // BEFORE: $stripeSubscription = $payload['data']['object'];
        // AFTER:
        $stripeSubscription = $payload; // ← REMOVED ['data']['object']
        $stripeSubscriptionId = $stripeSubscription['id'];

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

        if (!$subscription) {
            Log::warning('Subscription not found for update', ['stripe_subscription_id' => $stripeSubscriptionId]);
            return;
        }

        DB::transaction(function () use ($subscription, $stripeSubscription) {
            $updates = [
                'status' => $this->mapStripeStatus($stripeSubscription['status']),
            ];

            // Update current period end
            if (isset($stripeSubscription['current_period_end'])) {
                $updates['current_period_end'] = Carbon::createFromTimestamp($stripeSubscription['current_period_end']);
            }

            // Update current period start
            if (isset($stripeSubscription['current_period_start'])) {
                $updates['current_period_start'] = Carbon::createFromTimestamp($stripeSubscription['current_period_start']);
            }

            // Handle trial end
            if (isset($stripeSubscription['trial_end']) && $stripeSubscription['trial_end']) {
                $updates['trial_ends_at'] = Carbon::createFromTimestamp($stripeSubscription['trial_end']);
            }

            // Handle cancellation - check both cancel_at_period_end and canceled_at
            if (isset($stripeSubscription['cancel_at_period_end'])) {
                $updates['cancel_at_period_end'] = $stripeSubscription['cancel_at_period_end'];
            }

            if (isset($stripeSubscription['canceled_at']) && $stripeSubscription['canceled_at']) {
                $updates['cancelled_at'] = Carbon::createFromTimestamp($stripeSubscription['canceled_at']);
            }

            $subscription->update($updates);

            Log::info('Subscription updated', [
                'subscription_id' => $subscription->id,
                'stripe_status' => $stripeSubscription['status'],
                'updates' => $updates,
            ]);
        });
    }

    /**
     * Handle subscription deletion/cancellation
     */
    protected function handleSubscriptionDeleted(array $payload)
    {
        // ⚠️ CHANGE #7 (LINE 470):
        // CHANGED: Removed ['data']['object'] wrapper
        // REASON: Payload is NOW already the subscription object
        // BEFORE: $stripeSubscription = $payload['data']['object'];
        // AFTER:
        $stripeSubscription = $payload; // ← REMOVED ['data']['object']
        $stripeSubscriptionId = $stripeSubscription['id'];

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

        if (!$subscription) {
            Log::warning('Subscription not found for deletion', ['stripe_subscription_id' => $stripeSubscriptionId]);
            return;
        }

        DB::transaction(function () use ($subscription) {
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancel_at_period_end' => false,
            ]);

            Log::info('Subscription cancelled via webhook', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);
        });
    }

    /**
     * Handle trial ending soon
     */
    protected function handleTrialWillEnd(array $payload)
    {
        // ⚠️ CHANGE #8 (LINE 499):
        // CHANGED: Removed ['data']['object'] wrapper
        // REASON: Payload is NOW already the subscription object
        // BEFORE: $stripeSubscription = $payload['data']['object'];
        // AFTER:
        $stripeSubscription = $payload; // ← REMOVED ['data']['object']
        $stripeSubscriptionId = $stripeSubscription['id'];

        $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();

        if (!$subscription) {
            return;
        }

        Log::info('Trial will end soon', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'trial_ends_at' => $subscription->trial_ends_at,
        ]);

        // Send trial ending soon email
        try {
            Mail::to($subscription->user->email)->send(
                new TrialEndingSoonMail($subscription)
            );

            Log::info('✅ Trial ending soon email sent', [
                'user_email' => $subscription->user->email,
            ]);
        } catch (Exception $e) {
            Log::error('❌ Failed to send trial ending email', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map Stripe subscription status to our status
     */
    protected function mapStripeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'canceled' => 'cancelled',
            'unpaid' => 'suspended',
            'incomplete' => 'incomplete',
            'incomplete_expired' => 'cancelled',
            default => $stripeStatus,
        };
    }

    /**
     * Retry failed webhooks (can be called from a scheduled command)
     */
    public function retryFailedWebhooks()
    {
        $failedWebhooks = WebhookEvent::failedAndRetryable()
            ->where('created_at', '>', now()->subDays(7)) // Only retry webhooks from last 7 days
            ->get();

        foreach ($failedWebhooks as $webhook) {
            try {
                Log::info('Retrying webhook', [
                    'webhook_id' => $webhook->id,
                    'retry_count' => $webhook->retry_count
                ]);

                $this->process($webhook);
            } catch (Exception $e) {
                Log::error('Webhook retry failed', [
                    'webhook_id' => $webhook->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}