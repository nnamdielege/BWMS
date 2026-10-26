<?php

use App\Http\Controllers\Webhooks\OrdermentumWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Inbound webhooks: stateless (no session, cookies or CSRF), authenticated by
 * the secret in the path, throttled. They only store and queue (NFR-5).
 */
Route::post('webhooks/ordermentum/{token}', OrdermentumWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.ordermentum');
