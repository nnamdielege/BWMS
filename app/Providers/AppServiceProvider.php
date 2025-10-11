<?php

namespace App\Providers;

use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Models\Inventory;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Observers\InventoryObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\SalesOrderObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InventoryService::class);
        $this->app->singleton(OrderService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        SalesOrder::observe(SalesOrderObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        Inventory::observe(InventoryObserver::class);
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}