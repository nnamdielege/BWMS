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
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseLocationController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Get subscription plans (public info)
Route::get('/subscription/plans', [SubscriptionController::class, 'getPlans']);

// Webhooks (no auth required)
Route::post('/webhooks/stripe', [SubscriptionController::class, 'handleStripeWebhook'])
    ->withoutMiddleware(VerifyCsrfToken::class);

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->name('webhooks.stripe');

Route::post('/webhooks/paypal', [SubscriptionController::class, 'handlePaypalWebhook'])
    ->withoutMiddleware(VerifyCsrfToken::class);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // -------------------------------
    // Auth
    // -------------------------------
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // -------------------------------
    // Dashboard
    // -------------------------------
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->middleware('permission:view-reports');
        Route::get('/inventory-stats', [DashboardController::class, 'inventoryStats'])->middleware('permission:view-reports');
        Route::get('/transactions', [DashboardController::class, 'recentTransactions'])->middleware('permission:view-reports');
    });

    // -------------------------------
    // Roles & Permissions - Admin only
    // -------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions', [RoleController::class, 'permissions']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
        Route::delete('roles/{role}/permissions/{permission}', [RoleController::class, 'removePermission']);
        Route::delete('roles/{role}/permissions', [RoleController::class, 'removeAllPermissions']);
        Route::delete('permissions/{permission}', [RoleController::class, 'destroyPermission']);
    });


    // -------------------------------
    // Users - Admin or Warehouse Manager
    // -------------------------------
    Route::middleware('role:admin,warehouse-manager')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users/{user}/assign-role', [UserController::class, 'assignRole']);
        Route::post('users/{user}/assign-roles', [UserController::class, 'assignRoles']);
        Route::delete('users/{user}/roles/{role}', [UserController::class, 'removeRole']);
        Route::delete('users/{user}/roles', [UserController::class, 'removeAllRoles']);
    });


    // -------------------------------
    // Products - Permission-based
    // -------------------------------

    // Route::apiResource('products', ProductController::class);
    Route::get('products', [ProductController::class, 'index'])->middleware('permission:view-products');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:create-products');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:edit-products');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:delete-products');

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


    // -------------------------------
    // Inventory
    // -------------------------------
    Route::get('inventory', [InventoryController::class, 'index'])->middleware('permission:view-inventory');
    Route::get('inventory/product/{product}', [InventoryController::class, 'getByProduct'])->middleware('permission:view-inventory');
    Route::get('inventory/warehouse/{warehouse}', [InventoryController::class, 'getByWarehouse'])->middleware('permission:view-inventory');
    Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->middleware('permission:adjust-stock');
    Route::post('inventory/transfer', [InventoryController::class, 'transfer'])->middleware('permission:transfer-stock');
    Route::get('inventory/transactions', [InventoryController::class, 'getTransactions']);
    Route::get('inventory/low-stock', [InventoryController::class, 'lowStock']);
    Route::get('inventory/out-of-stock', [InventoryController::class, 'outOfStock']);
    Route::get('inventory/summary', [InventoryController::class, 'summary']);
    Route::get('inventory/value', [InventoryController::class, 'inventoryValue']);
    Route::post('inventory/stock-count', [InventoryController::class, 'stockCount']);


    // -------------------------------
    // Sales Orders
    // -------------------------------
    Route::apiResource('sales-orders', SalesOrderController::class);
    Route::post('sales-orders/{id}/fulfill', [SalesOrderController::class, 'fulfill'])->middleware('permission:approve-orders');
    Route::post('sales-orders/{id}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:approve-orders');

    // -------------------------------
    // Purchase Orders
    // -------------------------------
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->middleware('permission:approve-orders');
    Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:approve-orders');

    // -------------------------------
    // Customers & Suppliers
    // -------------------------------
    Route::apiResource('customers', CustomerController::class)->middleware('permission:view-customers');
    Route::apiResource('suppliers', SupplierController::class)->middleware('permission:view-suppliers');

    // -------------------------------
    // Warehouses
    // -------------------------------
    Route::apiResource('warehouses', WarehouseController::class)->middleware('permission:view-warehouses');
    Route::apiResource('warehouse-locations', WarehouseLocationController::class)->middleware('permission:view-warehouses');
    Route::apiResource('bins', BinController::class)->middleware('permission:view-warehouses');

    // -------------------------------
    // Reports
    // -------------------------------
    Route::prefix('reports')->group(function () {
        Route::get('/sales', [ReportController::class, 'salesReport'])->middleware('permission:view-reports');
        Route::get('/purchases', [ReportController::class, 'purchaseReport'])->middleware('permission:view-reports');
        Route::get('/inventory', [ReportController::class, 'inventoryReport'])->middleware('permission:view-reports');
        Route::get('/product-performance', [ReportController::class, 'productPerformanceReport'])->middleware('permission:view-reports');
    });

    // -------------------------------
    // Settings - Admin only
    // -------------------------------
    Route::prefix('settings')->middleware('role:admin')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
        Route::get('/{key}', [SettingController::class, 'show']);
        Route::post('/', [SettingController::class, 'store']);
        Route::put('/', [SettingController::class, 'update']);
        Route::get('/displaySettings', [SettingController::class, 'displaySettings']);
    });

    // -------------------------------
    // Profile
    // -------------------------------
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
        Route::post('/avatar', [ProfileController::class, 'uploadAvatar']);
        Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']);
    });

    // -------------------------------
    // Notifications
    // -------------------------------
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::put('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });

    // -------------------------------
    // Export & Import
    // -------------------------------
    Route::prefix('export')->group(function () {
        Route::get('/products', [ExportController::class, 'exportProducts']);
        Route::get('/customers', [ExportController::class, 'exportCustomers']);
        Route::get('/suppliers', [ExportController::class, 'exportSuppliers']);
        Route::get('/sales-orders', [ExportController::class, 'exportSalesOrders']);
        Route::get('/purchase-orders', [ExportController::class, 'exportPurchaseOrders']);
        Route::get('/inventory', [ExportController::class, 'exportInventory']);
    });

    Route::prefix('import')->group(function () {
        Route::post('/products', [ImportController::class, 'importProducts']);
        Route::post('/customers', [ImportController::class, 'importCustomers']);
        Route::get('/template/{type}', [ImportController::class, 'downloadTemplate']);
    });


    // -------------------------------
    // Global Search
    // -------------------------------
    Route::get('/search', [SearchController::class, 'search']);


    // ───────────────────────────────────────────────────────────
    // SUBSCRIPTION ROUTES
    // ───────────────────────────────────────────────────────────



    // Get current user subscription
    Route::get('/subscription/current', [SubscriptionController::class, 'getCurrentSubscription']);

    // Start free trial
    Route::post('/subscription/start-trial', [SubscriptionController::class, 'startTrial']);

    // Create Stripe checkout
    Route::post('/subscription/stripe/checkout', [SubscriptionController::class, 'createStripeCheckout']);

    // Cancel subscription
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancelSubscription']);

    // Get invoices
    Route::get('/subscription/invoices', [SubscriptionController::class, 'getInvoices']);

    // Get usage
    Route::get('/subscription/usage', [SubscriptionController::class, 'getUsage']);

    Route::get('/subscription/stripe/success', [SubscriptionController::class, 'handleStripeSuccess']);


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


    // Route::get('/test-search', function () {
    //     try {
    //         $salesOrders = \App\Models\SalesOrder::with('customer')->limit(5)->get();

    //         return response()->json([
    //             'success' => true,
    //             'count' => $salesOrders->count(),
    //             'sample' => $salesOrders->first(),
    //         ]);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString(),
    //         ], 500);
    //     }
    // });

});