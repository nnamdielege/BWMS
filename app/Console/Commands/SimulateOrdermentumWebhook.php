<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class SimulateOrdermentumWebhook extends Command
{
    protected $signature = 'webhook:simulate {event=stock.updated}';
    protected $description = 'Simulate an Ordermentum webhook event for testing';

    public function handle()
    {
        $event = $this->argument('event');

        $this->info("🔔 Simulating Ordermentum webhook event: {$event}");
        $this->newLine();

        // Define test payloads for different events
        $payloads = [
            'stock.updated' => [
                'event' => 'stock.updated',
                'timestamp' => now()->toIso8601String(),
                'product_id' => 'abc123def456',
                'variant_id' => 'var123',
                'quantity' => 150,
                'old_quantity' => 100,
            ],
            'stock.changed' => [
                'event' => 'stock.changed',
                'timestamp' => now()->toIso8601String(),
                'product_id' => 'abc123def456',
                'old_quantity' => 100,
                'new_quantity' => 80,
                'change' => -20,
            ],
            'order.created' => [
                'event' => 'order.created',
                'timestamp' => now()->toIso8601String(),
                'order_id' => 'ord123',
                'order_number' => 'ORD-001',
                'customer_id' => 'cust123',
            ],
            'order.confirmed' => [
                'event' => 'order.confirmed',
                'timestamp' => now()->toIso8601String(),
                'order_id' => 'ord123',
                'order_number' => 'ORD-001',
                'status' => 'confirmed',
                'items' => [
                    ['product_id' => 'abc123', 'quantity' => 10],
                ],
            ],
        ];

        $payload = $payloads[$event] ?? $payloads['stock.updated'];

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
            $this->line("  1. Webhook received and validated");
            $this->line("  2. Stock sync triggered automatically");
            $this->line("  3. Inventory updated from Ordermentum");
            $this->newLine();

            $this->info("✓ Webhook simulation complete!");
        } catch (\Exception $e) {
            $this->error("✗ Failed to send webhook: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
