<?php

namespace App\Services;

use App\Models\Subscription;
use App\Mail\SubscriptionCancelledMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SubscriptionCancellationService
{
    /**
     * Cancel subscription immediately
     */
    public function cancelImmediately(Subscription $subscription, ?string $reason = null)
    {
        try {
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);



            try {
                Mail::to($subscription->user->email)->send(
                    new SubscriptionCancelledMail($subscription, false)
                );
            } catch (\Exception $mailError) {
                Log::info('Subscription cancelled immediately', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'reason' => $reason,
                ]);
            }

            return [
                'success' => true,
                'message' => 'Subscription cancelled successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Schedule cancellation at end of billing period
     */
    public function scheduleForCancellation(Subscription $subscription, ?string $reason = null)
    {
        try {
            $subscription->update([
                'cancel_at_period_end' => true,
                'cancels_at' => $subscription->current_period_end,
                'cancellation_reason' => $reason,
            ]);

            // Send notification email
            Mail::to($subscription->user->email)->send(
                new SubscriptionCancelledMail($subscription, true)
            );

            Log::info('Subscription scheduled for cancellation', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'cancels_at' => $subscription->current_period_end,
                'reason' => $reason,
            ]);

            return [
                'success' => true,
                'message' => 'Subscription will be cancelled at end of billing period',
                'cancels_at' => $subscription->current_period_end,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to schedule cancellation', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Reactivate a scheduled cancellation
     */
    public function reactivate(Subscription $subscription)
    {
        try {
            $subscription->update([
                'cancel_at_period_end' => false,
                'cancels_at' => null,
                'cancellation_reason' => null,
            ]);

            Log::info('Subscription reactivated', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);

            return [
                'success' => true,
                'message' => 'Subscription reactivated successfully',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to reactivate subscription', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check and process subscriptions that should be cancelled
     * (Call this from a scheduled task)
     */
    public function processScheduledCancellations()
    {
        $cancelledCount = 0;

        try {
            $subscriptions = Subscription::where('cancel_at_period_end', true)
                ->where('cancels_at', '<=', now())
                ->where('status', '!=', 'cancelled')
                ->get();

            foreach ($subscriptions as $subscription) {
                $subscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

                $cancelledCount++;
            }

            Log::info('Processed scheduled cancellations', [
                'cancelled_count' => $cancelledCount,
            ]);

            return $cancelledCount;
        } catch (\Exception $e) {
            Log::error('Error processing scheduled cancellations', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}