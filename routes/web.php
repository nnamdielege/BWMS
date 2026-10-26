<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ProductController;
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
    Route::post('categories', [CategoryController::class, 'store'])
        ->middleware('can:create,App\Models\Product')->name('categories.store');

    Route::get('locations', [LocationController::class, 'index'])
        ->middleware('can:viewAny,App\Models\Location')->name('locations.index');
    Route::post('locations', [LocationController::class, 'store'])
        ->middleware('can:create,App\Models\Location')->name('locations.store');
    Route::put('locations/{location}', [LocationController::class, 'update'])
        ->middleware('can:update,location')->name('locations.update');

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
