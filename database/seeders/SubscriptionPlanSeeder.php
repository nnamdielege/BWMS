<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run()
    {
        SubscriptionPlan::create([
            'name' => 'Starter',
            'description' => 'Perfect for small warehouses',
            'price_monthly' => 49,
            'data_limit_gb' => 10,
            'max_users' => 5,
            'max_locations' => 1,
            'max_products' => 500,
            'features' => ['basic_reporting', 'stock_tracking', 'invoices'],
            'is_active' => true,
            'trial_days' => 14,
        ]);

        SubscriptionPlan::create([
            'name' => 'Professional',
            'description' => 'For growing businesses',
            'price_monthly' => 99,
            'data_limit_gb' => 50,
            'max_users' => 20,
            'max_locations' => 3,
            'max_products' => 5000,
            'features' => ['advanced_reporting', 'multi_location', 'api_access', 'webhooks'],
            'is_active' => true,
            'trial_days' => 14,
        ]);

        SubscriptionPlan::create([
            'name' => 'Enterprise',
            'description' => 'For large operations',
            'price_monthly' => 199,
            'data_limit_gb' => 500,
            'max_users' => 100,
            'max_locations' => 10,
            'max_products' => 50000,
            'features' => ['priority_support', 'custom_integration', 'dedicated_account', 'sso'],
            'is_active' => true,
            'trial_days' => 14,
        ]);
    }
}