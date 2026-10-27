<?php

use App\Http\Controllers\Webhooks\OrdermentumWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Inbound webhooks: stateless (no session, cookies or CSRF), throttled, and
 * signature-verified (the path token is optional once signatures are proven).
 * They only store and queue (NFR-5).
 */
Route::post('webhooks/ordermentum/{token}', OrdermentumWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.ordermentum');
Route::post('webhooks/ordermentum', OrdermentumWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.ordermentum.signed');
