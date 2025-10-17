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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type'); // invoice.payment_succeeded, customer.subscription.deleted, etc.
            $table->string('stripe_event_id')->unique(); // For idempotency
            $table->json('payload'); // Full webhook payload
            $table->string('status')->default('pending'); // pending, processed, failed
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index('status');
            $table->index('created_at');
        });

        // Add columns to subscriptions table if not exists
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->after('stripe_subscription_id');
            }
            if (!Schema::hasColumn('subscriptions', 'last_payment_at')) {
                $table->timestamp('last_payment_at')->nullable()->after('cancelled_at');
            }
            if (!Schema::hasColumn('subscriptions', 'failed_payment_count')) {
                $table->integer('failed_payment_count')->default(0)->after('last_payment_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'stripe_customer_id')) {
                $table->dropColumn('stripe_customer_id');
            }
            if (Schema::hasColumn('subscriptions', 'last_payment_at')) {
                $table->dropColumn('last_payment_at');
            }
            if (Schema::hasColumn('subscriptions', 'failed_payment_count')) {
                $table->dropColumn('failed_payment_count');
            }
        });
    }
};