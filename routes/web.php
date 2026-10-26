<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RangeController;
use App\Http\Controllers\Stock\AdjustmentController;
use App\Http\Controllers\Stock\MovementController;
use App\Http\Controllers\Stock\StockController;
use App\Http\Controllers\Stock\StocktakeController;
use App\Http\Controllers\Stock\TransferController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'active'])->group(function () {
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
    Route::get('barcodes/{barcode}', [ProductController::class, 'lookupBarcode'])
        ->middleware('can:viewAny,App\Models\Product')->name('barcodes.lookup');
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

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])
            ->middleware('can:viewAny,App\Models\User')->name('users.index');
        Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])
            ->middleware('can:updateRoles,user')->name('users.roles');
        Route::put('users/{user}/active', [UserController::class, 'setActive'])
            ->middleware('can:setActive,user')->name('users.active');

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
