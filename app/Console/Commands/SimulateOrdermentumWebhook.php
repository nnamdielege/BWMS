<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SimulateOrdermentumWebhook extends Command
{
    protected $signature = 'webhook:simulate {event=order_created}';
    protected $description = 'Simulate an Ordermentum webhook event for testing';

    public function handle()
    {
        $event = $this->argument('event');

        $this->info("🔔 Simulating Ordermentum webhook event: {$event}");
        $this->newLine();

        // Define test payloads matching Ordermentum documentation
        $payloads = [
            // Order Events
            'order_created' => [
                'eventType' => 'order_created',
                'id' => 'ord_' . uniqid(),
                'orderId' => 'ORD-001',
                'orderNumber' => 'ORD-001',
                'purchaserId' => 'purchaser_123',
                'supplierId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'status' => 'pending',
                'totalAmount' => 1500.00,
                'currency' => 'AUD',
                'createdAt' => now()->toIso8601String(),
                'items' => [
                    [
                        'productId' => 'prod_abc123',
                        'quantity' => 10,
                        'unitPrice' => 100.00,
                    ],
                ],
            ],

            'order_updated' => [
                'eventType' => 'order_updated',
                'id' => 'ord_' . uniqid(),
                'orderId' => 'ORD-001',
                'orderNumber' => 'ORD-001',
                'purchaserId' => 'purchaser_123',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'status' => 'confirmed',
                'updatedAt' => now()->toIso8601String(),
            ],

            // Purchaser Events
            'purchaser_created' => [
                'eventType' => 'purchaser_created',
                'id' => 'purchaser_' . uniqid(),
                'purchaserId' => 'purchaser_' . uniqid(),
                'name' => 'Test Purchaser',
                'email' => 'purchaser@example.com',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'createdAt' => now()->toIso8601String(),
            ],

            'purchaser_updated' => [
                'eventType' => 'purchaser_updated',
                'id' => 'purchaser_123',
                'purchaserId' => 'purchaser_123',
                'name' => 'Updated Purchaser Name',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'updatedAt' => now()->toIso8601String(),
            ],

            // Invoice Events
            'invoice_created' => [
                'eventType' => 'invoice_created',
                'id' => 'inv_' . uniqid(),
                'invoiceId' => 'INV-001',
                'invoiceNumber' => 'INV-001',
                'orderId' => 'ORD-001',
                'purchaserId' => 'purchaser_123',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'amount' => 1500.00,
                'currency' => 'AUD',
                'dueDate' => now()->addDays(30)->toDateString(),
                'status' => 'issued',
                'createdAt' => now()->toIso8601String(),
            ],

            'invoice_updated' => [
                'eventType' => 'invoice_updated',
                'id' => 'inv_123',
                'invoiceId' => 'INV-001',
                'orderId' => 'ORD-001',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'status' => 'paid',
                'paidAt' => now()->toIso8601String(),
                'updatedAt' => now()->toIso8601String(),
            ],

            // Credit Note Events
            'credit_note_created' => [
                'eventType' => 'credit_note_created',
                'id' => 'cn_' . uniqid(),
                'creditNoteId' => 'CN-001',
                'invoiceId' => 'INV-001',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'amount' => 100.00,
                'reason' => 'Return of goods',
                'status' => 'draft',
                'createdAt' => now()->toIso8601String(),
            ],

            'credit_note_updated' => [
                'eventType' => 'credit_note_updated',
                'id' => 'cn_123',
                'creditNoteId' => 'CN-001',
                'invoiceId' => 'INV-001',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'status' => 'issued',
                'updatedAt' => now()->toIso8601String(),
            ],

            'credit_note_completed' => [
                'eventType' => 'credit_note_completed',
                'id' => 'cn_123',
                'creditNoteId' => 'CN-001',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'completedAt' => now()->toIso8601String(),
            ],

            'credit_note_cancelled' => [
                'eventType' => 'credit_note_cancelled',
                'id' => 'cn_123',
                'creditNoteId' => 'CN-001',
                'entityId' => env('ORDERMENTUM_SUPPLIER_ID', 'supplier_456'),
                'entityType' => 'supplier',
                'cancelledAt' => now()->toIso8601String(),
                'reason' => 'Cancelled for review',
            ],
        ];

        // Get the appropriate payload
        if (!isset($payloads[$event])) {
            $this->error("Unknown event: {$event}");
            $this->line('Available events:');
            foreach (array_keys($payloads) as $availableEvent) {
                $this->line("  - {$availableEvent}");
            }
            return 1;
        }

        $payload = $payloads[$event];

        $this->line("Payload:");
        $this->line(json_encode($payload, JSON_PRETTY_PRINT));
        $this->newLine();

        // Send webhook to local endpoint
        $this->info("📤 Sending webhook to: POST /api/v1/webhooks/ordermentum");

        try {
            $response = Http::post(url('/api/v1/webhooks/ordermentum'), $payload);

            $statusCode = $response->status();
            $this->info("✓ Webhook sent (HTTP {$statusCode})");
            $this->newLine();

            $this->info("📋 Response:");
            $this->line(json_encode($response->json(), JSON_PRETTY_PRINT));
            $this->newLine();

            // Show what happens next
            $this->info("🔄 Webhook processing:");
            $this->line("  1. Webhook received at POST /api/v1/webhooks/ordermentum");
            $this->line("  2. Event type validated: {$event}");
            $this->line("  3. Job dispatched to queue");
            $this->line("  4. Response returned (202 Accepted)");
            $this->line("  5. Queue worker processes job asynchronously");
            $this->newLine();

            $this->info("✓ Webhook simulation complete!");
            $this->newLine();

            // Show next steps
            $this->line("Next steps:");
            $this->line("  1. Start queue worker: php artisan queue:work");
            $this->line("  2. Monitor logs: tail -f storage/logs/laravel.log");
        } catch (\Exception $e) {
            $this->error("✗ Failed to send webhook: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
