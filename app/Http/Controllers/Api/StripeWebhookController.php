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
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    protected StripeWebhookService $webhookService;

    public function __construct(StripeWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Handle incoming Stripe webhooks.
     */
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        if (!$signature) {
            Log::warning('❌ Missing Stripe-Signature header.');
            return response()->json(['success' => false, 'message' => 'Missing Stripe-Signature header'], 400);
        }

        if (!$secret) {
            Log::error('❌ Missing Stripe webhook secret in configuration.');
            return response()->json(['success' => false, 'message' => 'Webhook secret not configured'], 500);
        }

        try {
            // ✅ Verify Stripe webhook signature
            $event = Webhook::constructEvent($payload, $signature, $secret);

            $eventType = $event->type;
            $eventId = $event->id;

            Log::info('📥 Stripe Webhook Received', [
                'event_type' => $eventType,
                'event_id' => $eventId,
            ]);

            // 🔁 Ensure idempotency (avoid duplicate processing)
            $existingEvent = WebhookEvent::where('stripe_event_id', $eventId)->first();

            if ($existingEvent && $existingEvent->status === 'processed') {
                Log::info('Duplicate webhook received; skipping', ['event_id' => $eventId]);
                return response()->json(['success' => true, 'message' => 'Already processed'], 200);
            }

            // 💾 Store webhook event
            $webhookEvent = WebhookEvent::updateOrCreate(
                ['stripe_event_id' => $eventId],
                [
                    'event_type' => $eventType,
                    'payload' => $event->toArray(),
                    'status' => 'pending',
                ]
            );

            // ⚙️ Process the webhook
            $this->webhookService->process($webhookEvent);

            Log::info('✅ Webhook processed successfully', ['event_id' => $eventId]);

            return response()->json(['success' => true, 'message' => 'Webhook processed successfully'], 200);
        } catch (SignatureVerificationException $e) {
            Log::error('❌ Invalid Stripe signature', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);
        } catch (UnexpectedValueException $e) {
            Log::error('❌ Invalid Stripe payload', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 400);
        } catch (Exception $e) {
            Log::error('⚠️ Webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Webhook processing failed'], 500);
        }
    }
}