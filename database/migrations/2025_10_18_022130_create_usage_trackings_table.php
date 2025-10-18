<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_trackings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->string('action_type'); // 'product_created', 'inventory_adjusted', etc
            $table->string('resource_type'); // 'product', 'inventory', 'order'
            $table->string('resource_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('tracked_at');
            $table->timestamps();

            $table->index(['user_id', 'tracked_at']);
            $table->index(['subscription_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_trackings');
    }
};