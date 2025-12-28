<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\SyncOrdermentumStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrdermentumWebhookController extends Controller
{
    /**
     * Handle Ordermentum webhook events
     * Endpoint: POST /api/v1/webhooks/ordermentum
     * 
     * Ordermentum Event Types:
     * - order_created
     * - order_updated
     * - purchaser_created
     * - purchaser_updated
     * - invoice_created
     * - invoice_updated
     * - credit_note_created
     * - credit_note_updated
     * - credit_note_completed
     * - credit_note_cancelled
     */
    public function handle(Request $request)
    {
        try {
            // Get webhook payload
            $payload = $request->all();
            $eventType = $payload['eventType'] ?? $payload['event'] ?? null;

            Log::info('Ordermentum webhook received', [
                'event_type' => $eventType,
                'entity_id' => $payload['entityId'] ?? null,
                'entity_type' => $payload['entityType'] ?? null,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Verify webhook signature (if Ordermentum sends one)
            if (!$this->verifyWebhookSignature($request)) {
                Log::warning('Ordermentum webhook signature verification failed', [
                    'ip' => $request->ip(),
                    'event' => $eventType,
                ]);
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            // Route to appropriate handler based on Ordermentum event type
            match ($eventType) {
                // Order events
                'order_created' => $this->handleOrderCreated($payload),
                'order_updated' => $this->handleOrderUpdated($payload),

                // Purchaser events
                'purchaser_created' => $this->handlePurchaserCreated($payload),
                'purchaser_updated' => $this->handlePurchaserUpdated($payload),

                // Invoice events
                'invoice_created' => $this->handleInvoiceCreated($payload),
                'invoice_updated' => $this->handleInvoiceUpdated($payload),

                // Credit note events
                'credit_note_created' => $this->handleCreditNoteCreated($payload),
                'credit_note_updated' => $this->handleCreditNoteUpdated($payload),
                'credit_note_completed' => $this->handleCreditNoteCompleted($payload),
                'credit_note_cancelled' => $this->handleCreditNoteCancelled($payload),

                default => $this->handleUnknownEvent($eventType, $payload),
            };

            // Return 202 Accepted - webhook received and queued
            return response()->json([
                'message' => 'Webhook received and queued for processing',
                'event' => $eventType,
                'status' => 'accepted',
                'timestamp' => now()->toIso8601String(),
            ], 202);
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
            'order_id' => $payload['id'] ?? $payload['orderId'] ?? null,
            'order_number' => $payload['orderNumber'] ?? null,
            'purchaser_id' => $payload['purchaserId'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);

        // Trigger stock sync when order is created
        $this->dispatchStockSync();
    }

    /**
     * Handle order updated event
     */
    private function handleOrderUpdated(array $payload)
    {
        Log::info('Order updated in Ordermentum', [
            'order_id' => $payload['id'] ?? $payload['orderId'] ?? null,
            'status' => $payload['status'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);

        $this->dispatchStockSync();
    }

    /**
     * Handle purchaser created event
     */
    private function handlePurchaserCreated(array $payload)
    {
        Log::info('Purchaser created in Ordermentum', [
            'purchaser_id' => $payload['id'] ?? $payload['purchaserId'] ?? null,
            'purchaser_name' => $payload['name'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);

        // May need to sync or update customer data
    }

    /**
     * Handle purchaser updated event
     */
    private function handlePurchaserUpdated(array $payload)
    {
        Log::info('Purchaser updated in Ordermentum', [
            'purchaser_id' => $payload['id'] ?? $payload['purchaserId'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);
    }

    /**
     * Handle invoice created event
     */
    private function handleInvoiceCreated(array $payload)
    {
        Log::info('Invoice created in Ordermentum', [
            'invoice_id' => $payload['id'] ?? $payload['invoiceId'] ?? null,
            'order_id' => $payload['orderId'] ?? null,
            'amount' => $payload['amount'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);

        // May need to sync invoice data
    }

    /**
     * Handle invoice updated event
     */
    private function handleInvoiceUpdated(array $payload)
    {
        Log::info('Invoice updated in Ordermentum', [
            'invoice_id' => $payload['id'] ?? $payload['invoiceId'] ?? null,
            'status' => $payload['status'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);
    }

    /**
     * Handle credit note created event
     */
    private function handleCreditNoteCreated(array $payload)
    {
        Log::info('Credit note created in Ordermentum', [
            'credit_note_id' => $payload['id'] ?? $payload['creditNoteId'] ?? null,
            'invoice_id' => $payload['invoiceId'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);

        // May need to sync credit note data
    }

    /**
     * Handle credit note updated event
     */
    private function handleCreditNoteUpdated(array $payload)
    {
        Log::info('Credit note updated in Ordermentum', [
            'credit_note_id' => $payload['id'] ?? $payload['creditNoteId'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);
    }

    /**
     * Handle credit note completed event
     */
    private function handleCreditNoteCompleted(array $payload)
    {
        Log::info('Credit note completed in Ordermentum', [
            'credit_note_id' => $payload['id'] ?? $payload['creditNoteId'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);
    }

    /**
     * Handle credit note cancelled event
     */
    private function handleCreditNoteCancelled(array $payload)
    {
        Log::info('Credit note cancelled in Ordermentum', [
            'credit_note_id' => $payload['id'] ?? $payload['creditNoteId'] ?? null,
            'entity_id' => $payload['entityId'] ?? null,
        ]);
    }

    /**
     * Handle unknown event types
     */
    private function handleUnknownEvent($eventType, array $payload)
    {
        Log::warning('Unknown Ordermentum webhook event', [
            'event_type' => $eventType,
            'entity_id' => $payload['entityId'] ?? null,
            'payload_keys' => array_keys($payload),
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
     * GET /api/v1/webhooks/ordermentum/health
     */
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'webhook_url' => route('webhooks.ordermentum'),
            'supported_events' => [
                'order_created',
                'order_updated',
                'purchaser_created',
                'purchaser_updated',
                'invoice_created',
                'invoice_updated',
                'credit_note_created',
                'credit_note_updated',
                'credit_note_completed',
                'credit_note_cancelled',
            ],
            'queue_driver' => config('queue.default'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
