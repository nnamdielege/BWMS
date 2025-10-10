<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\OrdermentumConnection;
use Carbon\Carbon;

class OrdermentumService
{
    protected $baseUrl = 'https://api.ordermentum.com';
    protected $connection;

    public function __construct(OrdermentumConnection $connection = null)
    {
        $this->connection = $connection;
    }

    public function connect($authCode)
    {
        // Exchange authorization code for access token
        $response = Http::post("{$this->baseUrl}/oauth/token", [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.ordermentum.client_id'),
            'client_secret' => config('services.ordermentum.client_secret'),
            'code' => $authCode,
            'redirect_uri' => config('services.ordermentum.redirect_uri'),
        ]);

        if ($response->successful()) {
            $data = $response->json();

            return OrdermentumConnection::create([
                'supplier_id' => $data['supplier_id'],
                'access_token' => encrypt($data['access_token']),
                'refresh_token' => encrypt($data['refresh_token']),
                'token_expires_at' => Carbon::now()->addSeconds($data['expires_in']),
            ]);
        }

        throw new \Exception('Failed to connect to Ordermentum');
    }

    public function refreshToken()
    {
        $response = Http::post("{$this->baseUrl}/oauth/token", [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.ordermentum.client_id'),
            'client_secret' => config('services.ordermentum.client_secret'),
            'refresh_token' => decrypt($this->connection->refresh_token),
        ]);

        if ($response->successful()) {
            $data = $response->json();

            $this->connection->update([
                'access_token' => encrypt($data['access_token']),
                'refresh_token' => encrypt($data['refresh_token']),
                'token_expires_at' => Carbon::now()->addSeconds($data['expires_in']),
            ]);

            return true;
        }

        return false;
    }

    protected function getAccessToken()
    {
        // Check if token is expired
        if ($this->connection->token_expires_at <= Carbon::now()->addMinutes(5)) {
            $this->refreshToken();
            $this->connection->refresh();
        }

        return decrypt($this->connection->access_token);
    }

    public function getOrders($params = [])
    {
        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/api/orders", $params);

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Failed to fetch orders from Ordermentum');
    }

    public function updateOrderStatus($ordermentumOrderId, $status)
    {
        $response = Http::withToken($this->getAccessToken())
            ->put("{$this->baseUrl}/api/orders/{$ordermentumOrderId}/status", [
                'status' => $status,
            ]);

        return $response->successful();
    }

    public function updateProductInventory($ordermentumProductId, $quantity)
    {
        $response = Http::withToken($this->getAccessToken())
            ->put("{$this->baseUrl}/api/products/{$ordermentumProductId}", [
                'stock_quantity' => $quantity,
            ]);

        return $response->successful();
    }

    public function syncProducts()
    {
        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/api/products");

        if ($response->successful()) {
            return $response->json();
        }

        throw new \Exception('Failed to sync products from Ordermentum');
    }
}