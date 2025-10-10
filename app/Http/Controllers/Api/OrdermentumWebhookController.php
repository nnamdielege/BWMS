<?php

// app/Http/Controllers/Api/OrdermentumWebhookController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrdermentumService;
use App\Models\OrdermentumConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrdermentumWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify webhook signature
        $signature = $request->header('X-Ordermentum-Signature');
        if (!$this->verifySignature($request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        $data = $request->input('data');

        Log::info('Ordermentum webhook received', ['event' => $event, 'data' => $data]);

        try {
            switch ($event) {
                case 'order.created':
                case 'order.updated':
                    $this->handleOrderEvent($data);
                    break;

                case 'order.cancelled':
                    $this->handleOrderCancellation($data);
                    break;

                default:
                    Log::info('Unhandled webhook event', ['event' => $event]);
            }

            return response()->json(['message' => 'Webhook processed successfully']);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Webhook processing failed'], 500);
        }
    }

    protected function verifySignature($payload, $signature)
    {
        $secret = config('services.ordermentum.webhook_secret');
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    protected function handleOrderEvent($data)
    {
        $connection = OrdermentumConnection::first();

        if (!$connection) {
            throw new \Exception('No Ordermentum connection found');
        }

        $controller = new OrdermentumController();
        $controller->createSalesOrderFromOrdermentum($data, $connection);
    }

    protected function handleOrderCancellation($data)
    {
        $connection = OrdermentumConnection::first();

        if (!$connection) {
            return;
        }

        $ordermentumOrder = $connection->orders()
            ->where('ordermentum_order_id', $data['id'])
            ->first();

        if ($ordermentumOrder && $ordermentumOrder->salesOrder) {
            $ordermentumOrder->salesOrder->update(['status' => 'cancelled']);
        }
    }
}