<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class InventoryPushService
{
    private $baseUrl = 'https://app.ordermentum.com/v1';
    private $accessToken = null;

    /**
     * Update inventory and optionally push to Ordermentum
     */
    public function updateStock($inventoryId, $newQuantity, $autoPush = false)
    {
        $inventory = Inventory::with('product')->find($inventoryId);

        if (!$inventory) {
            throw new \Exception("Inventory record not found");
        }

        $oldQuantity = $inventory->quantity_on_hand;

        // Update local inventory
        $inventory->update([
            'quantity_on_hand' => $newQuantity,
            'quantity_available' => $newQuantity,
            'updated_at' => now(),
        ]);

        Log::info('Inventory updated locally', [
            'inventory_id' => $inventoryId,
            'product_id' => $inventory->product_id,
            'sku' => $inventory->product->sku,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
        ]);

        // Optionally push to Ordermentum immediately
        if ($autoPush && $inventory->product->ordermentum_variant_id) {
            $this->pushToOrdermentum($inventory);
        }

        return $inventory;
    }

    /**
     * Push inventory to Ordermentum
     */
    public function pushToOrdermentum($inventory)
    {
        $product = $inventory->product;

        if (!$product->ordermentum_product_id || !$product->ordermentum_variant_id) {
            Log::warning('Cannot push stock - missing Ordermentum IDs', [
                'sku' => $product->sku,
                'product_id' => $product->id,
            ]);
            return false;
        }

        try {
            // Authenticate
            if (!$this->authenticate()) {
                throw new \Exception('Failed to authenticate with Ordermentum');
            }

            // Push stock
            $result = $this->pushStockToOrdermentum(
                $product->ordermentum_product_id,
                $product->ordermentum_variant_id,
                $inventory->quantity_on_hand,
                $product
            );

            if ($result) {
                // Record successful push
                $inventory->update(['last_pushed_at' => now()]);

                Log::info('Stock pushed to Ordermentum successfully', [
                    'sku' => $product->sku,
                    'quantity' => $inventory->quantity_on_hand,
                ]);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to push stock to Ordermentum', [
                'sku' => $product->sku,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Authenticate with Ordermentum
     */
    private function authenticate()
    {
        $username = env('ORDERMENTUM_USERNAME');
        $password = env('ORDERMENTUM_PASSWORD');

        if (!$username || !$password) {
            return false;
        }

        $url = $this->baseUrl . '/auth';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['username' => $username, 'password' => $password]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Success
        if ($httpCode === 201) {
            $data = json_decode($response, true);
            if (isset($data['access_token'])) {
                $this->accessToken = $data['access_token'];
                return true;
            }
        }

        // 2FA Required - log for manual handling
        if ($httpCode === 403) {
            Log::warning('Ordermentum 2FA required - manual authentication needed');
            return false;
        }

        return false;
    }

    /**
     * Push stock to Ordermentum variant
     */
    private function pushStockToOrdermentum($productId, $variantId, $quantity, $product)
    {
        $url = 'https://stock.ordermentum.com/v1/products/' . $productId . '/variants/' . $variantId;

        $payload = [
            'quantity' => (int)$quantity,
            'available' => (int)$quantity,
            'outOfStock' => $quantity <= 0,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 || $httpCode === 204);
    }
}
