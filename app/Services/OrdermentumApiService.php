<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class OrdermentumApiService
{
    protected $username;
    protected $password;
    protected $baseUrl;
    protected $stockUrl;
    protected $supplierId;
    protected $locationId;
    protected $token;

    public function __construct()
    {
        $this->username = config('services.ordermentum.username');
        $this->password = config('services.ordermentum.password');
        $this->baseUrl = config('services.ordermentum.base_url');
        $this->stockUrl = config('services.ordermentum.stock_url', 'https://stock.ordermentum.com/v1');
        $this->supplierId = config('services.ordermentum.supplier_id');
        $this->locationId = config('services.ordermentum.location_id');
    }

    /**
     * Authenticate and get API token
     */
    protected function authenticate()
    {
        // Check if we have a cached token
        $cachedToken = Cache::get('ordermentum_api_token');
        if ($cachedToken) {
            $this->token = $cachedToken;
            return $this->token;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/auth', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->token = $data['access_token'] ?? null;

                // Cache token for 23 hours (assuming 24hr expiry)
                if ($this->token) {
                    Cache::put('ordermentum_api_token', $this->token, now()->addHours(23));
                }

                return $this->token;
            }

            Log::error('Ordermentum Authentication Failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Ordermentum Authentication Exception', [
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Make authenticated request to Ordermentum API
     */
    protected function request($method, $endpoint, $data = [])
    {
        // Ensure we have a valid token
        if (!$this->token) {
            $this->authenticate();
        }

        if (!$this->token) {
            Log::error('Cannot make Ordermentum request without token');
            return null;
        }

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            // For GET requests, don't pass $data parameter (query string already in endpoint)
            // For other methods, pass $data as request body
            if (strtolower($method) === 'get') {
                $response = Http::withHeaders($headers)->get($this->baseUrl . $endpoint);
            } else {
                $response = Http::withHeaders($headers)->$method($this->baseUrl . $endpoint, $data);
            }

            // If unauthorized, token might be expired - try re-authenticating once
            if ($response->status() === 401) {
                Cache::forget('ordermentum_api_token');
                $this->token = null;
                $this->authenticate();

                if ($this->token) {
                    $headers['Authorization'] = 'Bearer ' . $this->token;

                    // Retry the request with new token
                    if (strtolower($method) === 'get') {
                        $response = Http::withHeaders($headers)->get($this->baseUrl . $endpoint);
                    } else {
                        $response = Http::withHeaders($headers)->$method($this->baseUrl . $endpoint, $data);
                    }
                }
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Ordermentum API Error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Ordermentum API Exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Make authenticated request to Ordermentum Stock API
     */
    protected function stockRequest($method, $endpoint, $data = [])
    {
        // Ensure we have a valid token
        if (!$this->token) {
            $this->authenticate();
        }

        if (!$this->token) {
            Log::error('Cannot make Ordermentum stock request without token');
            return null;
        }

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ];

            // For GET requests, don't pass $data parameter (query string already in endpoint)
            // For other methods, pass $data as request body
            if (strtolower($method) === 'get') {
                $response = Http::withHeaders($headers)->get($this->stockUrl . $endpoint);
            } else {
                $response = Http::withHeaders($headers)->$method($this->stockUrl . $endpoint, $data);
            }

            // If unauthorized, token might be expired - try re-authenticating once
            if ($response->status() === 401) {
                Cache::forget('ordermentum_api_token');
                $this->token = null;
                $this->authenticate();

                if ($this->token) {
                    $headers['Authorization'] = 'Bearer ' . $this->token;

                    // Retry the request with new token
                    if (strtolower($method) === 'get') {
                        $response = Http::withHeaders($headers)->get($this->stockUrl . $endpoint);
                    } else {
                        $response = Http::withHeaders($headers)->$method($this->stockUrl . $endpoint, $data);
                    }
                }
            }

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Ordermentum Stock API Error', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Ordermentum Stock API Exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get all products from Ordermentum (with pagination handling)
     */
    public function getProducts($page = 1, $perPage = 100)
    {
        return $this->request('get', "/products?page={$page}&per_page={$perPage}");
    }

    /**
     * Get all products (fetch all pages)
     */
    public function getAllProducts()
    {
        $allProducts = [];
        $page = 1;
        $perPage = 250;

        do {
            $response = $this->getProducts($page, $perPage);

            if (!$response || !isset($response['data'])) {
                break;
            }

            $allProducts = array_merge($allProducts, $response['data']);

            $hasMore = isset($response['meta']['current_page']) &&
                isset($response['meta']['last_page']) &&
                $response['meta']['current_page'] < $response['meta']['last_page'];

            $page++;
        } while ($hasMore);

        return $allProducts;
    }

    /**
     * Get product by ID
     */
    public function getProduct($productId)
    {
        return $this->request('get', "/products/{$productId}");
    }

    /**
     * Get all variants (with pagination handling)
     */
    public function getVariants($supplierId = null, $page = 1, $perPage = 100)
    {
        $supplierId = $supplierId ?? $this->supplierId;

        // Pass parameters as array for proper query string encoding
        $params = [
            'supplierId' => $supplierId,
            'page' => $page,
            'per_page' => $perPage
        ];

        $query = http_build_query($params);
        return $this->request('get', "/variants?{$query}");
    }

    /**
     * Get all variants (fetch all pages)
     */
    public function getAllVariants($supplierId = null)
    {
        $supplierId = $supplierId ?? $this->supplierId;

        if (!$supplierId) {
            throw new \Exception('Supplier ID is required to fetch variants');
        }

        $allVariants = [];
        $page = 1;
        $perPage = 250;

        do {
            $response = $this->getVariants($supplierId, $page, $perPage);

            if (!$response || !isset($response['data']) || count($response['data']) === 0) {
                break;
            }

            $allVariants = array_merge($allVariants, $response['data']);

            // Check if there are more pages
            $hasMore = isset($response['meta']['pageNo']) &&
                isset($response['meta']['totalPages']) &&
                $response['meta']['pageNo'] < $response['meta']['totalPages'];

            $page++;

            // Safety limit
            if ($page > 50) {
                break;
            }
        } while ($hasMore);

        // Deduplicate by variant ID
        $uniqueVariants = [];
        foreach ($allVariants as $variant) {
            $uniqueVariants[$variant['id']] = $variant;
        }

        return array_values($uniqueVariants);
    }

    /**
     * Get variant by ID
     */
    public function getVariant($variantId)
    {
        return $this->request('get', "/variants/{$variantId}");
    }

    /**
     * Get variants for a specific product
     */
    public function getProductVariants($productId)
    {
        return $this->request('get', "/products/{$productId}/variants");
    }

    /**
     * Update stock/inventory for a variant/item
     */
    public function updateStock($itemId, $available, $tracked = true, $locationId = null)
    {
        $data = [
            'available' => $available,
            'tracked' => $tracked,
        ];

        // Use configured location ID if not provided
        $locationId = $locationId ?? $this->locationId;

        if ($locationId) {
            $data['locationId'] = $locationId;
        }

        return $this->stockRequest('put', "/items/{$itemId}", $data);
    }

    /**
     * Get stock/inventory for a variant/item
     */
    public function getStock($itemId)
    {
        return $this->stockRequest('get', "/items/{$itemId}");
    }

    /**
     * Batch update stock for multiple items
     */
    public function batchUpdateStock(array $items)
    {
        // items should be array of ['itemId' => 'xxx', 'available' => 10, 'tracked' => true, 'locationId' => 'optional']
        $results = [];

        foreach ($items as $item) {
            $result = $this->updateStock(
                $item['itemId'],
                $item['available'],
                $item['tracked'] ?? true,
                $item['locationId'] ?? null
            );

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Update product inventory
     */
    public function updateInventory($productId, $quantity, $locationId = null)
    {
        $data = [
            'quantity' => $quantity,
        ];

        if ($locationId) {
            $data['location_id'] = $locationId;
        }

        return $this->request('put', "/products/{$productId}/inventory", $data);
    }

    /**
     * Batch update inventory
     */
    public function batchUpdateInventory(array $updates)
    {
        return $this->request('post', "/inventory/batch", [
            'updates' => $updates
        ]);
    }

    /**
     * Get inventory levels
     */
    public function getInventory($productId = null)
    {
        $endpoint = $productId
            ? "/products/{$productId}/inventory"
            : "/inventory";

        return $this->request('get', $endpoint);
    }

    /**
     * Get orders
     */
    public function getOrders($page = 1, $perPage = 100, $filters = [])
    {
        $query = http_build_query(array_merge([
            'page' => $page,
            'per_page' => $perPage
        ], $filters));

        return $this->request('get', "/orders?{$query}");
    }

    /**
     * Get order by ID
     */
    public function getOrder($orderId)
    {
        return $this->request('get', "/orders/{$orderId}");
    }

    /**
     * Get suppliers
     */
    public function getSuppliers($page = 1, $perPage = 100)
    {
        return $this->request('get', "/suppliers?page={$page}&per_page={$perPage}");
    }

    /**
     * Get locations/venues
     */
    public function getLocations($page = 1, $perPage = 100)
    {
        return $this->request('get', "/venues?page={$page}&per_page={$perPage}");
    }

    /**
     * Test API connection
     */
    public function testConnection()
    {
        try {
            $token = $this->authenticate();
            if (!$token) {
                return false;
            }

            $response = $this->getProducts(1, 1);
            return $response !== null;
        } catch (\Exception $e) {
            Log::error('Ordermentum connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear cached token (useful for logout or token refresh)
     */
    public function clearToken()
    {
        Cache::forget('ordermentum_api_token');
        $this->token = null;
    }
}
