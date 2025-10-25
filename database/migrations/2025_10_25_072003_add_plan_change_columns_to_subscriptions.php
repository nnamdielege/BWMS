<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Add stripe_item_id if it doesn't exist
            if (!Schema::hasColumn('subscriptions', 'stripe_item_id')) {
                $table->string('stripe_item_id')->nullable()->after('stripe_subscription_id');
            }

            // Add amount column if it doesn't exist
            if (!Schema::hasColumn('subscriptions', 'amount')) {
                $table->decimal('amount', 10, 2)->nullable()->after('payment_method_id');
            }

            // Add credit_balance column if it doesn't exist
            if (!Schema::hasColumn('subscriptions', 'credit_balance')) {
                $table->decimal('credit_balance', 10, 2)->default(0)->after('amount');
            }

            // Add last_plan_change_at column if it doesn't exist
            if (!Schema::hasColumn('subscriptions', 'last_plan_change_at')) {
                $table->dateTime('last_plan_change_at')->nullable()->after('cancelled_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_item_id',
                'amount',
                'credit_balance',
                'last_plan_change_at',
            ]);
        });
    }
};