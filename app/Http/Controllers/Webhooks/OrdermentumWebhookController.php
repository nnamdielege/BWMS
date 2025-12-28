<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\SyncOrdermentumStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class OrdermentumWebhookController extends Controller
{
    /**
     * Handle Ordermentum webhook events
     * Endpoint: POST /api/webhooks/ordermentum
     */
    public function handle(Request $request)
    {
        try {
            // Get webhook payload
            $payload = $request->all();
            $eventType = $payload['event'] ?? $payload['type'] ?? null;

            Log::info('Ordermentum webhook received', [
                'event_type' => $eventType,
                'timestamp' => now()->toIso8601String(),
                'payload' => $payload,
            ]);

            // Verify webhook signature (if Ordermentum sends one)
            if (!$this->verifyWebhookSignature($request)) {
                Log::warning('Ordermentum webhook signature verification failed', [
                    'ip' => $request->ip(),
                ]);
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            // Route to appropriate handler based on event type
            match ($eventType) {
                'order.created' => $this->handleOrderCreated($payload),
                'order.updated' => $this->handleOrderUpdated($payload),
                'order.confirmed' => $this->handleOrderConfirmed($payload),
                'stock.updated' => $this->handleStockUpdated($payload),
                'stock.changed' => $this->handleStockChanged($payload),
                'inventory.changed' => $this->handleInventoryChanged($payload),
                default => $this->handleUnknownEvent($eventType, $payload),
            };

            return response()->json([
                'message' => 'Webhook received and queued for processing',
                'event' => $eventType,
                'status' => 'queued',
            ], 202); // 202 Accepted

        } catch (\Exception $e) {
            Log::error('Ordermentum webhook error: ' . $e->getMessage(), [
                'exception' => $e,
                'payload' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Webhook processing failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle order created event
     */
    private function handleOrderCreated(array $payload)
    {
        Log::info('Order created in Ordermentum', [
            'order_id' => $payload['order_id'] ?? null,
            'order_number' => $payload['order_number'] ?? null,
        ]);

        // Trigger stock sync
        $this->dispatchStockSync();
    }

    /**
     * Handle order updated event
     */
    private function handleOrderUpdated(array $payload)
    {
        Log::info('Order updated in Ordermentum', [
            'order_id' => $payload['order_id'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        $this->dispatchStockSync();
    }

    /**
     * Handle order confirmed event (CRITICAL - sync immediately)
     */
    private function handleOrderConfirmed(array $payload)
    {
        Log::info('Order confirmed in Ordermentum - CRITICAL SYNC', [
            'order_id' => $payload['order_id'] ?? null,
            'order_number' => $payload['order_number'] ?? null,
            'items_count' => count($payload['items'] ?? []),
        ]);

        // Priority dispatch - order confirmed means stock was allocated
        $this->dispatchStockSync(null, true);
    }

    /**
     * Handle stock updated event (HIGH PRIORITY)
     */
    private function handleStockUpdated(array $payload)
    {
        Log::info('Stock updated in Ordermentum', [
            'product_id' => $payload['product_id'] ?? null,
            'variant_id' => $payload['variant_id'] ?? null,
            'quantity' => $payload['quantity'] ?? null,
        ]);

        // High priority
        $this->dispatchStockSync(null, true);
    }

    /**
     * Handle stock changed event
     */
    private function handleStockChanged(array $payload)
    {
        Log::info('Stock changed in Ordermentum', [
            'product_id' => $payload['product_id'] ?? null,
            'old_quantity' => $payload['old_quantity'] ?? null,
            'new_quantity' => $payload['new_quantity'] ?? null,
        ]);

        $this->dispatchStockSync();
    }

    /**
     * Handle inventory changed event
     */
    private function handleInventoryChanged(array $payload)
    {
        Log::info('Inventory changed in Ordermentum', [
            'product_id' => $payload['product_id'] ?? null,
            'change' => $payload['change'] ?? null,
        ]);

        $this->dispatchStockSync();
    }

    /**
     * Handle unknown event types
     */
    private function handleUnknownEvent($eventType, array $payload)
    {
        Log::warning('Unknown Ordermentum webhook event', [
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
    }

    /**
     * Dispatch stock sync as async queue job
     * This doesn't block the webhook response
     */
    private function dispatchStockSync($warehouse_id = null, $force = true)
    {
        try {
            Log::info('Dispatching stock sync job to queue', [
                'warehouse_id' => $warehouse_id,
                'force' => $force,
            ]);

            // Dispatch job to queue (default queue)
            SyncOrdermentumStock::dispatch($warehouse_id, $force)
                ->onQueue('default')
                ->delay(now()); // Immediate

            Log::info('Stock sync job queued successfully');
        } catch (\Exception $e) {
            Log::error('Failed to dispatch stock sync job: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Verify webhook signature
     * Ordermentum should send an X-Ordermentum-Signature header
     */
    private function verifyWebhookSignature(Request $request): bool
    {
        // If signature verification is not required, return true
        if (!env('ORDERMENTUM_WEBHOOK_SECRET')) {
            Log::warning('ORDERMENTUM_WEBHOOK_SECRET not configured - skipping signature verification');
            return true;
        }

        $signature = $request->header('X-Ordermentum-Signature');
        if (!$signature) {
            return false;
        }

        $payload = $request->getContent();
        $secret = env('ORDERMENTUM_WEBHOOK_SECRET');
        $computedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($signature, $computedSignature);
    }

    /**
     * Health check endpoint for webhook
     */
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'webhook_url' => route('webhooks.ordermentum'),
            'queue_driver' => config('queue.default'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
