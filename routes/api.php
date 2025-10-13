<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BinController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\WarehouseLocationController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
        Route::post('/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);
    });

    // Dashboard
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard/inventory-stats', [DashboardController::class, 'inventoryStats']);
    Route::get('dashboard/transactions', [DashboardController::class, 'recentTransactions']);

    // Products
    Route::apiResource('products', ProductController::class);
    Route::get('products/{product}/inventory', [ProductController::class, 'getInventory']);
    Route::get('product-categories', [ProductController::class, 'getCategories']);
    Route::get('products-search', [ProductController::class, 'search']);
    Route::get('products-low-stock', [ProductController::class, 'lowStock']);
    Route::get('products-out-of-stock', [ProductController::class, 'outOfStock']);
    Route::get('products-statistics', [ProductController::class, 'statistics']);
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate']);
    Route::post('products-bulk-update', [ProductController::class, 'bulkUpdate']);
    Route::post('products-bulk-delete', [ProductController::class, 'bulkDelete']);
    Route::post('products-import', [ProductController::class, 'import']);
    Route::get('products-export', [ProductController::class, 'export']);
    Route::get('products/{product}/pricing-history', [ProductController::class, 'pricingHistory']);
    Route::get('products/{product}/movement-history', [ProductController::class, 'movementHistory']);

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index']);
    Route::get('inventory/product/{product}', [InventoryController::class, 'getByProduct']);
    Route::get('inventory/warehouse/{warehouse}', [InventoryController::class, 'getByWarehouse']);
    Route::post('inventory/adjust', [InventoryController::class, 'adjust']);
    Route::post('inventory/transfer', [InventoryController::class, 'transfer']);
    Route::get('inventory/transactions', [InventoryController::class, 'getTransactions']);
    Route::get('inventory/low-stock', [InventoryController::class, 'lowStock']);
    Route::get('inventory/out-of-stock', [InventoryController::class, 'outOfStock']);
    Route::get('inventory/summary', [InventoryController::class, 'summary']);
    Route::get('inventory/value', [InventoryController::class, 'inventoryValue']);
    Route::post('inventory/stock-count', [InventoryController::class, 'stockCount']);

    // Sales Orders
    Route::apiResource('sales-orders', SalesOrderController::class);
    Route::post('sales-orders/{id}/fulfill', [SalesOrderController::class, 'fulfill']);
    Route::post('sales-orders/{id}/cancel', [SalesOrderController::class, 'cancel']);

    // Purchase Orders
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive']);
    Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);

    // Customers
    Route::apiResource('customers', CustomerController::class);

    // Suppliers
    Route::apiResource('suppliers', SupplierController::class);

    // Warehouses
    Route::apiResource('warehouses', WarehouseController::class);

    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('/sales', [ReportController::class, 'salesReport']);
        Route::get('/purchases', [ReportController::class, 'purchaseReport']);
        Route::get('/inventory', [ReportController::class, 'inventoryReport']);
        Route::get('/product-performance', [ReportController::class, 'productPerformanceReport']);
    });

    // Settings
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
        Route::get('/{key}', [SettingController::class, 'show']);
        Route::post('/', [SettingController::class, 'store']);
        Route::put('/', [SettingController::class, 'update']);
        Route::get('/displaySettings', [SettingController::class, 'displaySettings']);
    });

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::put('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });

    // Route::get('/test-notifications', function () {
    //     return response()->json([
    //         'total_notifications' => \App\Models\Notification::count(),
    //         'user_id' => auth()->id(),
    //         'user_notifications' => \App\Models\Notification::where('user_id', auth()->id())->count(),
    //         'all_notifications' => \App\Models\Notification::all(),
    //     ]);
    // });

    // Route::post('/create-test-notification', function () {
    //     $user = auth()->user();

    //     $notification = \App\Models\Notification::create([
    //         'user_id' => $user->id,
    //         'type' => 'system',
    //         'title' => 'Test Notification',
    //         'message' => 'This is a test notification created at ' . now()->format('H:i:s'),
    //         'icon' => 'info',
    //         'color' => 'blue',
    //         'link' => '/dashboard',
    //         'is_read' => false,
    //     ]);

    //     return response()->json([
    //         'message' => 'Test notification created',
    //         'notification' => $notification
    //     ]);
    // });


    // Warehouse Locations
    Route::apiResource('warehouse-locations', WarehouseLocationController::class);
    Route::apiResource('bins', BinController::class);
});