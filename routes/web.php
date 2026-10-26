<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

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
