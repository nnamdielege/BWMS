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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            // Get counts
            $stats = [
                'total_products' => Product::count(),
                'total_customers' => Customer::count(),
                'total_suppliers' => Supplier::count(),
                'total_warehouses' => Warehouse::count(),
            ];

            // Sales Orders Stats
            $salesOrders = [
                'total' => SalesOrder::count(),
                'pending' => SalesOrder::where('status', 'pending')->count(),
                'processing' => SalesOrder::where('status', 'processing')->count(),
                'fulfilled' => SalesOrder::where('status', 'fulfilled')->count(),
                'total_amount' => SalesOrder::sum('total'),
                'recent' => SalesOrder::with(['customer', 'warehouse'])
                    ->latest()
                    ->take(5)
                    ->get(),
            ];

            // Purchase Orders Stats
            $purchaseOrders = [
                'total' => PurchaseOrder::count(),
                'pending' => PurchaseOrder::where('status', 'pending')->count(),
                'received' => PurchaseOrder::where('status', 'received')->count(),
                'total_amount' => PurchaseOrder::sum('total'),
                'recent' => PurchaseOrder::with(['supplier', 'warehouse'])
                    ->latest()
                    ->take(5)
                    ->get(),
            ];

            // Inventory Stats
            $inventory = [
                'total_stock' => Inventory::sum('quantity_on_hand'),
                'allocated_stock' => Inventory::sum('quantity_allocated'),
                'available_stock' => DB::raw('SUM(quantity_on_hand - quantity_allocated)'),
                'low_stock_items' => Inventory::with('product')
                    ->whereColumn('quantity_on_hand', '<=', DB::raw('quantity_allocated + 10'))
                    ->orWhere('quantity_on_hand', '<=', 20)
                    ->take(10)
                    ->get(),
                'out_of_stock' => Inventory::where('quantity_on_hand', 0)->count(),
            ];

            // Calculate actual available stock
            $availableStock = Inventory::selectRaw('SUM(quantity_on_hand - quantity_allocated) as available')
                ->value('available') ?? 0;
            $inventory['available_stock'] = $availableStock;

            // Top Products by Stock Value
            $topProducts = Inventory::with('product', 'warehouse')
                ->join('products', 'inventory.product_id', '=', 'products.id')
                ->select(
                    'inventory.*',
                    DB::raw('(inventory.quantity_on_hand * products.price) as stock_value')
                )
                ->orderBy('stock_value', 'desc')
                ->take(5)
                ->get();

            // Recent Activity (last 7 days)
            $recentActivity = [
                'sales_orders' => SalesOrder::where('created_at', '>=', now()->subDays(7))->count(),
                'purchase_orders' => PurchaseOrder::where('created_at', '>=', now()->subDays(7))->count(),
                'products_added' => Product::where('created_at', '>=', now()->subDays(7))->count(),
            ];

            // Sales by Status
            $salesByStatus = SalesOrder::select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->get()
                ->pluck('count', 'status');

            // Monthly Sales Trend (last 6 months)
            $monthlySales = SalesOrder::select(
                DB::raw('DATE_FORMAT(order_date, "%Y-%m") as month'),
                DB::raw('SUM(total) as total_sales'),
                DB::raw('COUNT(*) as order_count')
            )
                ->where('order_date', '>=', now()->subMonths(6))
                ->groupBy('month')
                ->orderBy('month', 'asc')
                ->get();

            return response()->json([
                'stats' => $stats,
                'sales_orders' => $salesOrders,
                'purchase_orders' => $purchaseOrders,
                'inventory' => $inventory,
                'top_products' => $topProducts,
                'recent_activity' => $recentActivity,
                'sales_by_status' => $salesByStatus,
                'monthly_sales' => $monthlySales,
            ]);
        } catch (\Exception $e) {
            Log::error('Dashboard error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'message' => 'Failed to load dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}