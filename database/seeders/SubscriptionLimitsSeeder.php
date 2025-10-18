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
        // FREE PLAN
        $freePlan = SubscriptionPlan::where('name', 'Free')->first();
        if ($freePlan) {
            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $freePlan->id, 'action_type' => 'product_created'],
                [
                    'monthly_limit' => 10,
                    'description' => 'Products created per month',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $freePlan->id, 'action_type' => 'inventory_adjusted'],
                [
                    'monthly_limit' => 50,
                    'description' => 'Inventory adjustments per month',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $freePlan->id, 'action_type' => 'order_created'],
                [
                    'monthly_limit' => 5,
                    'description' => 'Orders created per month',
                ]
            );

            echo "✅ Free plan limits set: 10 products, 50 inventory, 5 orders\n";
        } else {
            echo "⚠️  Free plan not found\n";
        }

        // STARTER PLAN
        $starterPlan = SubscriptionPlan::where('name', 'Starter')->first();
        if ($starterPlan) {
            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $starterPlan->id, 'action_type' => 'product_created'],
                [
                    'monthly_limit' => 100,
                    'description' => 'Products created per month',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $starterPlan->id, 'action_type' => 'inventory_adjusted'],
                [
                    'monthly_limit' => 500,
                    'description' => 'Inventory adjustments per month',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $starterPlan->id, 'action_type' => 'order_created'],
                [
                    'monthly_limit' => 50,
                    'description' => 'Orders created per month',
                ]
            );

            echo "✅ Starter plan limits set: 100 products, 500 inventory, 50 orders\n";
        } else {
            echo "⚠️  Starter plan not found\n";
        }

        // BUSINESS PLAN
        $businessPlan = SubscriptionPlan::where('name', 'Business')->first();
        if ($businessPlan) {
            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $businessPlan->id, 'action_type' => 'product_created'],
                [
                    'monthly_limit' => 500,
                    'description' => 'Products created per month',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $businessPlan->id, 'action_type' => 'inventory_adjusted'],
                [
                    'monthly_limit' => 5000,
                    'description' => 'Inventory adjustments per month',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $businessPlan->id, 'action_type' => 'order_created'],
                [
                    'monthly_limit' => 500,
                    'description' => 'Orders created per month',
                ]
            );

            echo "✅ Business plan limits set: 500 products, 5000 inventory, 500 orders\n";
        } else {
            echo "⚠️  Business plan not found\n";
        }

        // ENTERPRISE PLAN (unlimited)
        $enterprisePlan = SubscriptionPlan::where('name', 'Enterprise')->first();
        if ($enterprisePlan) {
            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $enterprisePlan->id, 'action_type' => 'product_created'],
                [
                    'monthly_limit' => 999999,
                    'description' => 'Products created per month (Unlimited)',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $enterprisePlan->id, 'action_type' => 'inventory_adjusted'],
                [
                    'monthly_limit' => 999999,
                    'description' => 'Inventory adjustments per month (Unlimited)',
                ]
            );

            SubscriptionLimit::updateOrCreate(
                ['plan_id' => $enterprisePlan->id, 'action_type' => 'order_created'],
                [
                    'monthly_limit' => 999999,
                    'description' => 'Orders created per month (Unlimited)',
                ]
            );

            echo "✅ Enterprise plan limits set: Unlimited\n";
        } else {
            echo "⚠️  Enterprise plan not found\n";
        }

        echo "\n✅ Subscription limits seeded successfully!\n";
    }
}