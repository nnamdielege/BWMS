<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionLimit;

class SubscriptionLimitsSeeder extends Seeder
{
    public function run()
    {
        echo "\n🚀 Creating subscription plans and limits...\n\n";

        // Trial PLAN
        $trialPlan = SubscriptionPlan::updateOrCreate(['name' => 'Trial'], [
            'description' => 'Free trial for 14 days - no credit card required',
            'price_monthly' => 0,
            'data_limit_gb' => 5,
            'max_users' => 2,
            'max_locations' => 1,
            'max_products' => 100,
            'features' => ["basic_reporting", "stock_tracking", "email_support"],
            'trial_days' => 7,
            'is_active' => true,
        ]);

        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $trialPlan->id, 'action_type' => 'product_created'],
            ['monthly_limit' => 10, 'description' => 'Products created per month']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $trialPlan->id, 'action_type' => 'inventory_adjusted'],
            ['monthly_limit' => 50, 'description' => 'Inventory adjustments per month']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $trialPlan->id, 'action_type' => 'order_created'],
            ['monthly_limit' => 5, 'description' => 'Orders created per month']
        );
        echo "✅ Free plan: 10 products, 50 inventory, 5 orders\n";

        // STARTER PLAN
        $starterPlan = SubscriptionPlan::updateOrCreate(['name' => 'Starter'], [
            'description' => 'Starter tier',
            'price_monthly' => 29,
            'data_limit_gb' => 50,
            'max_users' => 5,
            'max_locations' => 3,
            'max_products' => 1000,
            'features' => ["basic_reporting", "stock_tracking", "invoices"],
            'trial_days' => 7,
            'is_active' => true,
        ]);

        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $starterPlan->id, 'action_type' => 'product_created'],
            ['monthly_limit' => 100, 'description' => 'Products created per month']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $starterPlan->id, 'action_type' => 'inventory_adjusted'],
            ['monthly_limit' => 500, 'description' => 'Inventory adjustments per month']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $starterPlan->id, 'action_type' => 'order_created'],
            ['monthly_limit' => 50, 'description' => 'Orders created per month']
        );
        echo "✅ Starter plan: 100 products, 500 inventory, 50 orders\n";

        // Professional PLAN
        $professionalPlan = SubscriptionPlan::updateOrCreate(['name' => 'Business'], [
            'description' => 'For growing businesses',
            'price_monthly' => 99,
            'data_limit_gb' => 200,
            'max_users' => 20,
            'max_locations' => 10,
            'max_products' => 5000,
            'features' => ["advanced_reporting", "multi_location", "api_access", "webhooks"],
            'trial_days' => 7,
            'is_active' => true,
        ]);

        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $professionalPlan->id, 'action_type' => 'product_created'],
            ['monthly_limit' => 500, 'description' => 'Products created per month']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $professionalPlan->id, 'action_type' => 'inventory_adjusted'],
            ['monthly_limit' => 5000, 'description' => 'Inventory adjustments per month']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $professionalPlan->id, 'action_type' => 'order_created'],
            ['monthly_limit' => 500, 'description' => 'Orders created per month']
        );
        echo "✅ Business plan: 500 products, 5000 inventory, 500 orders\n";

        // ENTERPRISE PLAN
        $enterprisePlan = SubscriptionPlan::updateOrCreate(['name' => 'Enterprise'], [
            'description' => 'Enterprise tier',
            'price_monthly' => 299,
            'data_limit_gb' => 999999,
            'max_users' => 999999,
            'max_locations' => 999999,
            'max_products' => 999999,
            'features' => ["priority_support", "custom_integration", "dedicated_account", "sso"],
            'trial_days' => 7,
            'is_active' => true,
        ]);

        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $enterprisePlan->id, 'action_type' => 'product_created'],
            ['monthly_limit' => 999999, 'description' => 'Products created per month (Unlimited)']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $enterprisePlan->id, 'action_type' => 'inventory_adjusted'],
            ['monthly_limit' => 999999, 'description' => 'Inventory adjustments per month (Unlimited)']
        );
        SubscriptionLimit::updateOrCreate(
            ['plan_id' => $enterprisePlan->id, 'action_type' => 'order_created'],
            ['monthly_limit' => 999999, 'description' => 'Orders created per month (Unlimited)']
        );
        echo "✅ Enterprise plan: Unlimited\n";

        echo "\n✅ All subscription plans and limits created successfully!\n\n";
    }
}