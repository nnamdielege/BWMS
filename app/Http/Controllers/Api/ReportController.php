<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\PurchaseOrder;
use App\Models\Inventory;
use App\Models\SalesOrderItem;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    /**
     * Sales Report
     */
    public function salesReport(Request $request)
    {
        try {
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->get('end_date', Carbon::now()->format('Y-m-d'));

            Log::info('Sales Report Request', ['start_date' => $startDate, 'end_date' => $endDate]);

            // Sales summary
            $totalOrders = SalesOrder::whereBetween('order_date', [$startDate, $endDate])->count();
            $totalRevenue = SalesOrder::whereBetween('order_date', [$startDate, $endDate])->sum('total') ?? 0;
            $pendingOrders = SalesOrder::whereBetween('order_date', [$startDate, $endDate])
                ->where('status', 'pending')->count();
            $fulfilledOrders = SalesOrder::whereBetween('order_date', [$startDate, $endDate])
                ->where('status', 'fulfilled')->count();
            $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

            $summary = [
                'total_orders' => $totalOrders,
                'total_revenue' => (float) $totalRevenue,
                'pending_orders' => $pendingOrders,
                'fulfilled_orders' => $fulfilledOrders,
                'average_order_value' => (float) $averageOrderValue,
            ];

            // Sales by status
            $salesByStatus = SalesOrder::whereBetween('order_date', [$startDate, $endDate])
                ->select('status', DB::raw('count(*) as count'), DB::raw('COALESCE(SUM(total), 0) as total'))
                ->groupBy('status')
                ->get();

            // Sales by customer
            $salesByCustomer = SalesOrder::with('customer')
                ->whereBetween('order_date', [$startDate, $endDate])
                ->whereNotNull('customer_id')
                ->select('customer_id', DB::raw('count(*) as order_count'), DB::raw('COALESCE(SUM(total), 0) as total_amount'))
                ->groupBy('customer_id')
                ->orderByDesc('total_amount')
                ->limit(10)
                ->get();

            // Daily sales trend
            $dailySales = SalesOrder::whereBetween('order_date', [$startDate, $endDate])
                ->select(
                    DB::raw('DATE(order_date) as date'),
                    DB::raw('count(*) as order_count'),
                    DB::raw('COALESCE(SUM(total), 0) as total_sales')
                )
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            // Top products by revenue
            $topProducts = SalesOrderItem::whereHas('salesOrder', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
            })
                ->with('product')
                ->select(
                    'product_id',
                    DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                    DB::raw('COALESCE(SUM(subtotal), 0) as total_revenue')
                )
                ->groupBy('product_id')
                ->orderByDesc('total_revenue')
                ->limit(10)
                ->get();

            return response()->json([
                'summary' => $summary,
                'sales_by_status' => $salesByStatus,
                'sales_by_customer' => $salesByCustomer,
                'daily_sales' => $dailySales,
                'top_products' => $topProducts,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Sales report error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to generate sales report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Purchase Report
     */
    public function purchaseReport(Request $request)
    {
        try {
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->get('end_date', Carbon::now()->format('Y-m-d'));

            Log::info('Purchase Report Request', ['start_date' => $startDate, 'end_date' => $endDate]);

            // Purchase summary
            $totalOrders = PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->count();
            $totalAmount = PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->sum('total') ?? 0;
            $pendingOrders = PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])
                ->where('status', 'pending')->count();
            $receivedOrders = PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])
                ->where('status', 'received')->count();
            $averageOrderValue = $totalOrders > 0 ? $totalAmount / $totalOrders : 0;

            $summary = [
                'total_orders' => $totalOrders,
                'total_amount' => (float) $totalAmount,
                'pending_orders' => $pendingOrders,
                'received_orders' => $receivedOrders,
                'average_order_value' => (float) $averageOrderValue,
            ];

            // Purchase by status
            $purchaseByStatus = PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])
                ->select('status', DB::raw('count(*) as count'), DB::raw('COALESCE(SUM(total), 0) as total'))
                ->groupBy('status')
                ->get();

            // Purchase by supplier
            $purchaseBySupplier = PurchaseOrder::with('supplier')
                ->whereBetween('order_date', [$startDate, $endDate])
                ->whereNotNull('supplier_id')
                ->select('supplier_id', DB::raw('count(*) as order_count'), DB::raw('COALESCE(SUM(total), 0) as total_amount'))
                ->groupBy('supplier_id')
                ->orderByDesc('total_amount')
                ->limit(10)
                ->get();

            // Daily purchase trend
            $dailyPurchases = PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])
                ->select(
                    DB::raw('DATE(order_date) as date'),
                    DB::raw('count(*) as order_count'),
                    DB::raw('COALESCE(SUM(total), 0) as total_purchases')
                )
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            // Top purchased products
            $topProducts = PurchaseOrderItem::whereHas('purchaseOrder', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
            })
                ->with('product')
                ->select(
                    'product_id',
                    DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                    DB::raw('COALESCE(SUM(subtotal), 0) as total_cost')
                )
                ->groupBy('product_id')
                ->orderByDesc('total_cost')
                ->limit(10)
                ->get();

            return response()->json([
                'summary' => $summary,
                'purchase_by_status' => $purchaseByStatus,
                'purchase_by_supplier' => $purchaseBySupplier,
                'daily_purchases' => $dailyPurchases,
                'top_products' => $topProducts,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Purchase report error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to generate purchase report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Inventory Report
     */
    public function inventoryReport(Request $request)
    {
        try {
            $warehouseId = $request->get('warehouse_id');

            Log::info('Inventory Report Request', ['warehouse_id' => $warehouseId]);

            $query = Inventory::with(['product', 'warehouse']);

            if ($warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            }

            $inventory = $query->get();

            // Summary
            $totalStock = $inventory->sum('quantity_on_hand');
            $allocatedStock = $inventory->sum('quantity_allocated');
            $availableStock = $inventory->sum(function ($item) {
                return max(0, $item->quantity_on_hand - $item->quantity_allocated);
            });
            $lowStockCount = $inventory->filter(function ($item) {
                return $item->quantity_on_hand <= 20;
            })->count();
            $outOfStockCount = $inventory->filter(function ($item) {
                return $item->quantity_on_hand == 0;
            })->count();

            $summary = [
                'total_products' => $inventory->count(),
                'total_stock' => (int) $totalStock,
                'allocated_stock' => (int) $allocatedStock,
                'available_stock' => (int) $availableStock,
                'low_stock_items' => $lowStockCount,
                'out_of_stock' => $outOfStockCount,
            ];

            // Inventory by warehouse
            $inventoryByWarehouse = Inventory::with('warehouse')
                ->select(
                    'warehouse_id',
                    DB::raw('COALESCE(SUM(quantity_on_hand), 0) as total_stock'),
                    DB::raw('COALESCE(SUM(quantity_allocated), 0) as allocated_stock'),
                    DB::raw('COUNT(DISTINCT product_id) as product_count')
                )
                ->groupBy('warehouse_id')
                ->get();

            // Low stock items
            $lowStockItems = Inventory::with(['product', 'warehouse'])
                ->where('quantity_on_hand', '<=', 20)
                ->orderBy('quantity_on_hand', 'asc')
                ->limit(20)
                ->get();

            // Stock value by product
            $stockValue = Inventory::with('product')
                ->join('products', 'inventory.product_id', '=', 'products.id')
                ->select(
                    'inventory.product_id',
                    'inventory.quantity_on_hand',
                    'products.price',
                    DB::raw('(inventory.quantity_on_hand * products.price) as stock_value')
                )
                ->orderByDesc('stock_value')
                ->limit(10)
                ->get();

            return response()->json([
                'summary' => $summary,
                'inventory_by_warehouse' => $inventoryByWarehouse,
                'low_stock_items' => $lowStockItems,
                'stock_value' => $stockValue,
            ]);
        } catch (\Exception $e) {
            Log::error('Inventory report error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to generate inventory report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Product Performance Report
     */
    public function productPerformanceReport(Request $request)
    {
        try {
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->get('end_date', Carbon::now()->format('Y-m-d'));

            Log::info('Product Performance Report Request', ['start_date' => $startDate, 'end_date' => $endDate]);

            // Top selling products by quantity
            $topSellingProducts = SalesOrderItem::whereHas('salesOrder', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
            })
                ->with('product')
                ->select(
                    'product_id',
                    DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                    DB::raw('COALESCE(SUM(subtotal), 0) as total_revenue'),
                    DB::raw('COUNT(DISTINCT sales_order_id) as order_count')
                )
                ->groupBy('product_id')
                ->orderByDesc('total_quantity')
                ->limit(20)
                ->get();

            // Products by revenue
            $productsByRevenue = SalesOrderItem::whereHas('salesOrder', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
            })
                ->with('product')
                ->select(
                    'product_id',
                    DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                    DB::raw('COALESCE(SUM(subtotal), 0) as total_revenue')
                )
                ->groupBy('product_id')
                ->orderByDesc('total_revenue')
                ->limit(20)
                ->get();

            // Slow moving products
            $soldProductIds = SalesOrderItem::whereHas('salesOrder', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('order_date', [$startDate, $endDate]);
            })
                ->select('product_id')
                ->groupBy('product_id')
                ->havingRaw('SUM(quantity) > 10')
                ->pluck('product_id')
                ->toArray();

            $slowMovingProducts = Product::whereNotIn('id', $soldProductIds)
                ->limit(20)
                ->get()
                ->map(function ($product) {
                    $product->total_sold = 0;
                    return $product;
                });

            return response()->json([
                'top_selling_products' => $topSellingProducts,
                'products_by_revenue' => $productsByRevenue,
                'slow_moving_products' => $slowMovingProducts,
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Product performance report error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to generate product performance report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}