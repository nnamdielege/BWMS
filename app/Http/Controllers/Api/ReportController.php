<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Warehouse;
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
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
            $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

            // Sales summary
            $summary = [
                'total_orders' => SalesOrder::whereBetween('order_date', [$startDate, $endDate])->count(),
                'total_revenue' => SalesOrder::whereBetween('order_date', [$startDate, $endDate])->sum('total'),
                'pending_orders' => SalesOrder::whereBetween('order_date', [$startDate, $endDate])->where('status', 'pending')->count(),
                'fulfilled_orders' => SalesOrder::whereBetween('order_date', [$startDate, $endDate])->where('status', 'fulfilled')->count(),
                'average_order_value' => SalesOrder::whereBetween('order_date', [$startDate, $endDate])->avg('total'),
            ];

            // Sales by status
            $salesByStatus = SalesOrder::select('status', DB::raw('count(*) as count'), DB::raw('SUM(total) as total'))
                ->whereBetween('order_date', [$startDate, $endDate])
                ->groupBy('status')
                ->get();

            // Sales by customer
            $salesByCustomer = SalesOrder::with('customer')
                ->select('customer_id', DB::raw('count(*) as order_count'), DB::raw('SUM(total) as total_amount'))
                ->whereBetween('order_date', [$startDate, $endDate])
                ->groupBy('customer_id')
                ->orderBy('total_amount', 'desc')
                ->limit(10)
                ->get();

            // Daily sales trend
            $dailySales = SalesOrder::select(
                DB::raw('DATE(order_date) as date'),
                DB::raw('count(*) as order_count'),
                DB::raw('SUM(total) as total_sales')
            )
                ->whereBetween('order_date', [$startDate, $endDate])
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            // Top products by revenue
            $topProducts = SalesOrderItem::with('product')
                ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
                ->select(
                    'sales_order_items.product_id',
                    DB::raw('SUM(sales_order_items.quantity) as total_quantity'),
                    DB::raw('SUM(sales_order_items.subtotal) as total_revenue')
                )
                ->whereBetween('sales_orders.order_date', [$startDate, $endDate])
                ->groupBy('sales_order_items.product_id')
                ->orderBy('total_revenue', 'desc')
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
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
            $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

            // Purchase summary
            $summary = [
                'total_orders' => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->count(),
                'total_amount' => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->sum('total'),
                'pending_orders' => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->where('status', 'pending')->count(),
                'received_orders' => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->where('status', 'received')->count(),
                'average_order_value' => PurchaseOrder::whereBetween('order_date', [$startDate, $endDate])->avg('total'),
            ];

            // Purchase by status
            $purchaseByStatus = PurchaseOrder::select('status', DB::raw('count(*) as count'), DB::raw('SUM(total) as total'))
                ->whereBetween('order_date', [$startDate, $endDate])
                ->groupBy('status')
                ->get();

            // Purchase by supplier
            $purchaseBySupplier = PurchaseOrder::with('supplier')
                ->select('supplier_id', DB::raw('count(*) as order_count'), DB::raw('SUM(total) as total_amount'))
                ->whereBetween('order_date', [$startDate, $endDate])
                ->groupBy('supplier_id')
                ->orderBy('total_amount', 'desc')
                ->limit(10)
                ->get();

            // Daily purchase trend
            $dailyPurchases = PurchaseOrder::select(
                DB::raw('DATE(order_date) as date'),
                DB::raw('count(*) as order_count'),
                DB::raw('SUM(total) as total_purchases')
            )
                ->whereBetween('order_date', [$startDate, $endDate])
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            // Top purchased products
            $topProducts = PurchaseOrderItem::with('product')
                ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
                ->select(
                    'purchase_order_items.product_id',
                    DB::raw('SUM(purchase_order_items.quantity) as total_quantity'),
                    DB::raw('SUM(purchase_order_items.subtotal) as total_cost')
                )
                ->whereBetween('purchase_orders.order_date', [$startDate, $endDate])
                ->groupBy('purchase_order_items.product_id')
                ->orderBy('total_cost', 'desc')
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

            $query = Inventory::with(['product', 'warehouse']);

            if ($warehouseId) {
                $query->where('warehouse_id', $warehouseId);
            }

            $inventory = $query->get();

            // Summary
            $summary = [
                'total_products' => $inventory->count(),
                'total_stock' => $inventory->sum('quantity_on_hand'),
                'allocated_stock' => $inventory->sum('quantity_allocated'),
                'available_stock' => $inventory->sum(function ($item) {
                    return $item->quantity_on_hand - $item->quantity_allocated;
                }),
                'low_stock_items' => $inventory->filter(function ($item) {
                    return $item->quantity_on_hand <= 20;
                })->count(),
                'out_of_stock' => $inventory->filter(function ($item) {
                    return $item->quantity_on_hand == 0;
                })->count(),
            ];

            // Inventory by warehouse
            $inventoryByWarehouse = Inventory::with('warehouse')
                ->select(
                    'warehouse_id',
                    DB::raw('SUM(quantity_on_hand) as total_stock'),
                    DB::raw('SUM(quantity_allocated) as allocated_stock'),
                    DB::raw('COUNT(DISTINCT product_id) as product_count')
                )
                ->groupBy('warehouse_id')
                ->get();

            // Low stock items
            $lowStockItems = Inventory::with(['product', 'warehouse'])
                ->whereColumn('quantity_on_hand', '<=', DB::raw('quantity_allocated + 10'))
                ->orWhere('quantity_on_hand', '<=', 20)
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
                ->orderBy('stock_value', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'summary' => $summary,
                'inventory_by_warehouse' => $inventoryByWarehouse,
                'low_stock_items' => $lowStockItems,
                'stock_value' => $stockValue,
                'inventory' => $inventory,
            ]);
        } catch (\Exception $e) {
            Log::error('Inventory report error: ' . $e->getMessage());

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
            $startDate = $request->get('start_date', Carbon::now()->startOfMonth()->toDateString());
            $endDate = $request->get('end_date', Carbon::now()->endOfMonth()->toDateString());

            // Top selling products
            $topSellingProducts = SalesOrderItem::with('product')
                ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
                ->select(
                    'sales_order_items.product_id',
                    DB::raw('SUM(sales_order_items.quantity) as total_quantity'),
                    DB::raw('SUM(sales_order_items.subtotal) as total_revenue'),
                    DB::raw('COUNT(DISTINCT sales_order_items.sales_order_id) as order_count')
                )
                ->whereBetween('sales_orders.order_date', [$startDate, $endDate])
                ->groupBy('sales_order_items.product_id')
                ->orderBy('total_quantity', 'desc')
                ->limit(20)
                ->get();

            // Products by revenue
            $productsByRevenue = SalesOrderItem::with('product')
                ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
                ->select(
                    'sales_order_items.product_id',
                    DB::raw('SUM(sales_order_items.quantity) as total_quantity'),
                    DB::raw('SUM(sales_order_items.subtotal) as total_revenue')
                )
                ->whereBetween('sales_orders.order_date', [$startDate, $endDate])
                ->groupBy('sales_order_items.product_id')
                ->orderBy('total_revenue', 'desc')
                ->limit(20)
                ->get();

            // Slow moving products
            $slowMovingProducts = Product::with(['salesOrderItems' => function ($query) use ($startDate, $endDate) {
                $query->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
                    ->whereBetween('sales_orders.order_date', [$startDate, $endDate]);
            }])
                ->select('products.*')
                ->leftJoin('sales_order_items', 'products.id', '=', 'sales_order_items.product_id')
                ->leftJoin('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
                ->selectRaw('COALESCE(SUM(sales_order_items.quantity), 0) as total_sold')
                ->whereBetween('sales_orders.order_date', [$startDate, $endDate])
                ->groupBy('products.id')
                ->orderBy('total_sold', 'asc')
                ->limit(20)
                ->get();

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

            return response()->json([
                'message' => 'Failed to generate product performance report',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}