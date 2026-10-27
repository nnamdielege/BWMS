<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\Orders\CustomerController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\PickListController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductCostController;
use App\Http\Controllers\ProductPackController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\ReceiptController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\RangeController;
use App\Http\Controllers\Stock\AdjustmentController;
use App\Http\Controllers\Stock\CountTaskController;
use App\Http\Controllers\Stock\MovementController;
use App\Http\Controllers\Stock\StockController;
use App\Http\Controllers\Stock\StocktakeController;
use App\Http\Controllers\Stock\TransferController;
use App\Http\Controllers\Sync\SyncController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'active', '2fa.admin'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Catalogue
    Route::get('products', [ProductController::class, 'index'])
        ->middleware('can:viewAny,App\Models\Product')->name('products.index');
    Route::get('products/create', [ProductController::class, 'create'])
        ->middleware('can:create,App\Models\Product')->name('products.create');
    Route::post('products', [ProductController::class, 'store'])
        ->middleware('can:create,App\Models\Product')->name('products.store');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])
        ->middleware('can:viewAny,App\Models\Product')->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])
        ->middleware('can:update,product')->name('products.update');
    Route::get('products-search', [ProductController::class, 'search'])
        ->middleware('can:viewAny,App\Models\Product')->name('products.search');
    Route::get('barcodes/{barcode}', [ProductController::class, 'lookupBarcode'])
        ->middleware('can:viewAny,App\Models\Product')->name('barcodes.lookup');
    // Cost back-fill (D-11): report needs reports.view, changes need products.manage.
    Route::get('product-costs', [ProductCostController::class, 'index'])->middleware('can:reports.view')->name('product-costs.index');
    Route::get('product-costs/export', [ProductCostController::class, 'export'])->middleware('can:reports.view')->name('product-costs.export');
    Route::put('product-costs/{product}', [ProductCostController::class, 'update'])->middleware('can:products.manage')->name('product-costs.update');
    Route::post('product-costs/import/preview', [ProductCostController::class, 'preview'])->middleware('can:products.manage')->name('product-costs.preview');
    Route::post('product-costs/import/apply', [ProductCostController::class, 'apply'])->middleware('can:products.manage')->name('product-costs.apply');

    Route::get('product-packs', [ProductPackController::class, 'index'])->middleware('can:products.view')->name('product-packs.index');
    Route::get('product-packs/export', [ProductPackController::class, 'export'])->middleware('can:products.view')->name('product-packs.export');
    Route::post('product-packs/preview', [ProductPackController::class, 'preview'])->middleware('can:products.manage')->name('product-packs.preview');
    Route::post('product-packs/apply', [ProductPackController::class, 'apply'])->middleware('can:products.manage')->name('product-packs.apply');

    Route::post('ranges', [RangeController::class, 'store'])
        ->middleware('can:create,App\Models\Product')->name('ranges.store');
    Route::post('categories', [CategoryController::class, 'store'])
        ->middleware('can:create,App\Models\Product')->name('categories.store');

    Route::get('locations', [LocationController::class, 'index'])
        ->middleware('can:viewAny,App\Models\Location')->name('locations.index');
    Route::post('locations', [LocationController::class, 'store'])
        ->middleware('can:create,App\Models\Location')->name('locations.store');
    Route::put('locations/{location}', [LocationController::class, 'update'])
        ->middleware('can:update,location')->name('locations.update');

    // Stock
    Route::get('stock', [StockController::class, 'index'])->middleware('can:stock.view')->name('stock.index');
    Route::get('stock/movements', [MovementController::class, 'index'])->middleware('can:stock.view')->name('stock.movements');
    Route::get('stock/{product}', [StockController::class, 'show'])->middleware('can:stock.view')->name('stock.show');
    Route::post('stock/{product}/adjustments', [AdjustmentController::class, 'store'])->middleware('can:stock.adjust')->name('stock.adjust');
    Route::post('stock/{product}/transfers', [TransferController::class, 'store'])->middleware('can:stock.transfer')->name('stock.transfer');

    Route::get('stocktakes', [StocktakeController::class, 'index'])->middleware('can:stocktakes.count')->name('stocktakes.index');
    Route::post('stocktakes', [StocktakeController::class, 'store'])->middleware('can:stocktakes.manage')->name('stocktakes.store');
    Route::get('stocktakes/{stocktake}', [StocktakeController::class, 'show'])->middleware('can:stocktakes.count')->name('stocktakes.show');
    Route::put('stocktakes/{stocktake}/lines/{line}', [StocktakeController::class, 'count'])->middleware('can:stocktakes.count')->name('stocktakes.count');
    Route::post('stocktakes/{stocktake}/lines', [StocktakeController::class, 'addLine'])->middleware('can:stocktakes.count')->name('stocktakes.lines.store');
    Route::post('stocktakes/{stocktake}/post', [StocktakeController::class, 'post'])->middleware('can:stocktakes.manage')->name('stocktakes.post');
    Route::post('stocktakes/{stocktake}/cancel', [StocktakeController::class, 'cancel'])->middleware('can:stocktakes.manage')->name('stocktakes.cancel');
    Route::post('count-tasks/{countTask}/dismiss', [CountTaskController::class, 'dismiss'])->middleware('can:stocktakes.manage')->name('count-tasks.dismiss');

    // Purchasing
    Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.view')->name('suppliers.index');
    Route::get('suppliers/create', [SupplierController::class, 'create'])->middleware('can:suppliers.manage')->name('suppliers.create');
    Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.manage')->name('suppliers.store');
    Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->middleware('can:suppliers.view')->name('suppliers.edit');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.manage')->name('suppliers.update');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('can:purchase_orders.view')->name('purchase-orders.index');
    Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->middleware('can:purchase_orders.manage')->name('purchase-orders.create');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('can:purchase_orders.manage')->name('purchase-orders.store');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('can:purchase_orders.view')->name('purchase-orders.show');
    Route::get('purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])->middleware('can:purchase_orders.manage')->name('purchase-orders.edit');
    Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('can:purchase_orders.manage')->name('purchase-orders.update');
    Route::post('purchase-orders/{purchaseOrder}/sent', [PurchaseOrderController::class, 'markSent'])->middleware('can:purchase_orders.manage')->name('purchase-orders.sent');
    Route::post('purchase-orders/{purchaseOrder}/email', [PurchaseOrderController::class, 'email'])->middleware('can:purchase_orders.manage')->name('purchase-orders.email');
    Route::post('purchase-orders/{purchaseOrder}/emails/{email}/retry', [PurchaseOrderController::class, 'retryEmail'])->middleware('can:purchase_orders.manage')->name('purchase-orders.email.retry');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('can:purchase_orders.manage')->name('purchase-orders.cancel');
    Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'closeShort'])->middleware('can:purchase_orders.manage')->name('purchase-orders.close');
    Route::get('purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'pdf'])->middleware('can:purchase_orders.view')->name('purchase-orders.pdf');
    Route::get('purchase-orders/{purchaseOrder}/receive', [ReceiptController::class, 'create'])->middleware('can:receiving.perform')->name('purchase-orders.receive');
    Route::post('purchase-orders/{purchaseOrder}/receipts', [ReceiptController::class, 'store'])->middleware('can:receiving.perform')->name('purchase-orders.receipts.store');

    // Orders & fulfilment
    Route::get('customers', [CustomerController::class, 'index'])->middleware('can:orders.view')->name('customers.index');
    Route::get('customers/create', [CustomerController::class, 'create'])->middleware('can:orders.manage')->name('customers.create');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('can:orders.manage')->name('customers.store');
    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:orders.view')->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('can:orders.manage')->name('customers.update');

    Route::get('orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.index');
    Route::get('orders/create', [OrderController::class, 'create'])->middleware('can:orders.manage')->name('orders.create');
    Route::post('orders', [OrderController::class, 'store'])->middleware('can:orders.manage')->name('orders.store');
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->name('orders.show');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('can:orders.manage')->name('orders.cancel');
    Route::post('orders/{order}/clear-flags', [OrderController::class, 'clearFlags'])->middleware('can:orders.manage')->name('orders.clear-flags');

    Route::get('pick-lists', [PickListController::class, 'index'])->middleware('can:picking.perform')->name('pick-lists.index');
    Route::post('pick-lists', [PickListController::class, 'store'])->middleware('can:picking.perform')->name('pick-lists.store');
    Route::get('pick-lists/{pickList}', [PickListController::class, 'show'])->middleware('can:picking.perform')->name('pick-lists.show');
    Route::put('pick-lists/{pickList}/picked', [PickListController::class, 'record'])->middleware('can:picking.perform')->name('pick-lists.record');
    Route::post('pick-lists/{pickList}/dispatch', [PickListController::class, 'dispatch'])->middleware('can:picking.perform')->name('pick-lists.dispatch');
    Route::post('pick-lists/{pickList}/cancel', [PickListController::class, 'cancel'])->middleware('can:picking.perform')->name('pick-lists.cancel');

    Route::get('sync', [SyncController::class, 'index'])->middleware('can:sync.view')->name('sync.index');
    Route::post('sync/listings/{listing}/push', [SyncController::class, 'push'])->middleware('can:sync.manage')->name('sync.push');
    Route::put('sync/listings/{listing}', [SyncController::class, 'toggle'])->middleware('can:sync.manage')->name('sync.listings.update');
    Route::post('sync/listings-enable-all', [SyncController::class, 'enableAll'])->middleware('can:sync.manage')->name('sync.listings.enable-all');
    Route::post('sync/retry-failed', [SyncController::class, 'retryFailed'])->middleware('can:sync.manage')->name('sync.retry-failed');
    Route::post('sync/inbox/{inbox}/replay', [SyncController::class, 'replay'])->middleware('can:sync.manage')->name('sync.inbox.replay');
    Route::post('sync/unmapped/{variant}/map', [SyncController::class, 'map'])->middleware('can:sync.manage')->name('sync.unmapped.map');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('settings', [SettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.update');

        Route::get('users', [UserController::class, 'index'])
            ->middleware('can:viewAny,App\Models\User')->name('users.index');
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])
            ->middleware('can:updateRoles,user')->name('users.roles');
        Route::put('users/{user}/active', [UserController::class, 'setActive'])
            ->middleware('can:setActive,user')->name('users.active');
        Route::delete('users/{user}/two-factor', [UserController::class, 'resetTwoFactor'])
            ->middleware('can:setActive,user')->name('users.reset-two-factor');

        Route::post('invitations', [InvitationController::class, 'store'])
            ->middleware('can:create,App\Models\UserInvitation')->name('invitations.store');
        Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])
            ->middleware('can:create,App\Models\UserInvitation')->name('invitations.resend');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])
            ->middleware('can:create,App\Models\UserInvitation')->name('invitations.destroy');

        Route::get('audit-log', [AuditLogController::class, 'index'])
            ->middleware('can:viewAny,App\Models\AuditLog')->name('audit.index');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
