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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Basic, Professional, Enterprise
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2); // Monthly price
            $table->integer('data_limit_gb'); // GB per month
            $table->integer('max_users'); // Max users allowed
            $table->integer('max_locations'); // Max warehouse locations
            $table->integer('max_products'); // Max SKUs
            $table->json('features')->nullable(); // Feature flags
            $table->boolean('is_active')->default(true);
            $table->integer('trial_days')->default(14); // Trial period
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};