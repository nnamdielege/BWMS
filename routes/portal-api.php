<?php

use App\Http\Controllers\Api\PortalApiController;
use Illuminate\Support\Facades\Route;

/*
 * Portal -> BWMS API v1 (spec B4.4). Stateless (no session/cookies/CSRF),
 * authenticated by the HMAC signature middleware, throttled.
 */
Route::prefix('api/portal/v1')->middleware(['portal.signature', 'throttle:300,1'])->name('portal-api.')->group(function () {
    Route::post('reservations', [PortalApiController::class, 'reserve'])->name('reservations');
    Route::put('orders/{orderRef}', [PortalApiController::class, 'upsert'])->name('orders.upsert');
    Route::get('orders/{orderRef}', [PortalApiController::class, 'show'])->name('orders.show');
    Route::get('stock', [PortalApiController::class, 'stock'])->name('stock');
});
