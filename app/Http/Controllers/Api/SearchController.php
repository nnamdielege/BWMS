<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\SalesOrder;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        try {
            $query = $request->get('query', '');

            Log::info('Global search request', ['query' => $query]);

            if (strlen($query) < 2) {
                return response()->json($this->emptyResponse());
            }

            $searchTerm = '%' . $query . '%';

            $results = [
                'products' => $this->searchProducts($searchTerm),
                'customers' => $this->searchCustomers($searchTerm),
                'suppliers' => $this->searchSuppliers($searchTerm),
                'sales_orders' => $this->searchSalesOrders($searchTerm),
                'purchase_orders' => $this->searchPurchaseOrders($searchTerm),
            ];

            $results['total'] = collect($results)->flatten(1)->count();

            Log::info('Search completed', [
                'query' => $query,
                'products' => count($results['products']),
                'customers' => count($results['customers']),
                'suppliers' => count($results['suppliers']),
                'sales_orders' => count($results['sales_orders']),
                'purchase_orders' => count($results['purchase_orders']),
                'total' => $results['total']
            ]);

            return response()->json($results);
        } catch (\Exception $e) {
            Log::error('Global search error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Search failed',
                'error' => $e->getMessage(),
                ...$this->emptyResponse()
            ], 500);
        }
    }

    private function searchProducts($searchTerm)
    {
        try {
            return Product::where(function ($query) use ($searchTerm) {
                $query->where('name', 'LIKE', $searchTerm)
                    ->orWhere('sku', 'LIKE', $searchTerm)
                    ->orWhere('description', 'LIKE', $searchTerm);
            })
                ->limit(5)
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'type' => 'product',
                        'title' => $product->name,
                        'subtitle' => "SKU: {$product->sku}",
                        'description' => $product->description ? substr($product->description, 0, 50) . '...' : '',
                        'meta' => "Price: $" . number_format($product->price, 2),
                        'link' => "/products/{$product->id}",
                        'icon' => 'cube',
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Product search error: ' . $e->getMessage());
            return [];
        }
    }

    private function searchCustomers($searchTerm)
    {
        try {
            Log::info('Searching customers with term: ' . $searchTerm);

            $customers = Customer::where(function ($query) use ($searchTerm) {
                $query->where('company_name', 'LIKE', $searchTerm)
                    ->orWhere('customer_code', 'LIKE', $searchTerm)
                    ->orWhere('email', 'LIKE', $searchTerm)
                    ->orWhere('name', 'LIKE', $searchTerm)
                    ->orWhere('phone', 'LIKE', $searchTerm)
                    ->orWhere('mobile', 'LIKE', $searchTerm)
                    ->orWhere('city', 'LIKE', $searchTerm);
            })
                ->where('is_active', true)
                ->limit(5)
                ->get();

            Log::info('Found ' . $customers->count() . ' customers');

            return $customers->map(function ($customer) {
                return [
                    'id' => $customer->id,
                    'type' => 'customer',
                    'title' => $customer->company_name,
                    'subtitle' => $customer->customer_code,
                    'description' => $customer->name ?? '',
                    'meta' => $customer->email ?? $customer->phone ?? $customer->mobile ?? '',
                    'link' => "/customers/{$customer->id}",
                    'icon' => 'user',
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('Customer search error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    private function searchSuppliers($searchTerm)
    {
        try {
            return Supplier::where(function ($query) use ($searchTerm) {
                $query->where('company_name', 'LIKE', $searchTerm)
                    ->orWhere('supplier_code', 'LIKE', $searchTerm)
                    ->orWhere('email', 'LIKE', $searchTerm)
                    ->orWhere('contact_name', 'LIKE', $searchTerm)
                    ->orWhere('phone', 'LIKE', $searchTerm);
            })
                ->limit(5)
                ->get()
                ->map(function ($supplier) {
                    return [
                        'id' => $supplier->id,
                        'type' => 'supplier',
                        'title' => $supplier->company_name,
                        'subtitle' => $supplier->supplier_code,
                        'description' => $supplier->contact_name ?? '',
                        'meta' => $supplier->email ?? $supplier->phone ?? '',
                        'link' => "/suppliers/{$supplier->id}",
                        'icon' => 'truck',
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Supplier search error: ' . $e->getMessage());
            return [];
        }
    }

    private function searchSalesOrders($searchTerm)
    {
        try {
            return DB::table('sales_orders')
                ->leftJoin('customers', 'sales_orders.customer_id', '=', 'customers.id')
                ->where('sales_orders.order_number', 'LIKE', $searchTerm)
                ->orWhere('customers.company_name', 'LIKE', $searchTerm)
                ->select(
                    'sales_orders.id',
                    'sales_orders.order_number',
                    'sales_orders.status',
                    'sales_orders.total',
                    'customers.company_name'
                )
                ->limit(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'type' => 'sales_order',
                        'title' => $order->order_number,
                        'subtitle' => $order->company_name ?? 'N/A',
                        'description' => "Status: " . ucfirst($order->status),
                        'meta' => "Total: $" . number_format($order->total, 2),
                        'link' => "/sales-orders/{$order->id}",
                        'icon' => 'shopping-cart',
                        'badge' => $order->status,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Sales order search error: ' . $e->getMessage());
            return [];
        }
    }

    private function searchPurchaseOrders($searchTerm)
    {
        try {
            return DB::table('purchase_orders')
                ->leftJoin('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
                ->where('purchase_orders.order_number', 'LIKE', $searchTerm)
                ->orWhere('suppliers.company_name', 'LIKE', $searchTerm)
                ->select(
                    'purchase_orders.id',
                    'purchase_orders.order_number',
                    'purchase_orders.status',
                    'purchase_orders.total',
                    'suppliers.company_name'
                )
                ->limit(5)
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'type' => 'purchase_order',
                        'title' => $order->order_number,
                        'subtitle' => $order->company_name ?? 'N/A',
                        'description' => "Status: " . ucfirst($order->status),
                        'meta' => "Total: $" . number_format($order->total, 2),
                        'link' => "/purchase-orders/{$order->id}",
                        'icon' => 'shopping-bag',
                        'badge' => $order->status,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            Log::error('Purchase order search error: ' . $e->getMessage());
            return [];
        }
    }

    private function emptyResponse()
    {
        return [
            'products' => [],
            'customers' => [],
            'suppliers' => [],
            'sales_orders' => [],
            'purchase_orders' => [],
            'total' => 0,
        ];
    }
}