<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\StripeWebhookService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    protected $webhookService;

    public function __construct(StripeWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Handle incoming Stripe webhooks
     */
    public function handle(Request $request)
    {
        Log::info('Received');

        return response()->json([
            'success' => true,
            'message' => 'Webhook processing test'
        ], 200);
        try {
            // Get the webhook signature
            $signature = $request->header('Stripe-Signature');
            $payload = $request->getContent();

            if (!$signature) {
                Log::warning('Missing Stripe signature', [
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Missing signature'
                ], 401);
            }

            // Verify webhook signature
            try {
                $event = Webhook::constructEvent(
                    $payload,
                    $signature,
                    config('services.stripe.webhook_secret')
                );
            } catch (SignatureVerificationException $e) {
                Log::warning('Invalid Stripe signature', [
                    'error' => $e->getMessage(),
                    'ip' => $request->ip()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature'
                ], 401);
            }

            $eventType = $event->type;
            $eventId = $event->id;

            // Check if we've already processed this webhook (idempotency)
            $existingEvent = WebhookEvent::where('stripe_event_id', $eventId)->first();

            if ($existingEvent && $existingEvent->status === 'processed') {
                Log::info('Duplicate webhook received', [
                    'event_id' => $eventId,
                    'event_type' => $eventType
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Already processed'
                ], 200);
            }

            // Store webhook event in database
            $webhookEvent = WebhookEvent::updateOrCreate(
                ['stripe_event_id' => $eventId],
                [
                    'event_type' => $eventType,
                    'payload' => $event->toArray(),
                    'status' => 'pending',
                ]
            );

            // Process the webhook
            $this->webhookService->process($webhookEvent);

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully'
            ], 200);
        } catch (Exception $e) {
            Log::error('Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed'
            ], 500);
        }
    }
}