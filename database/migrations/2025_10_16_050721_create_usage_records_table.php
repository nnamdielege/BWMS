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
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('data_used_mb', 10, 2)->default(0);
            $table->integer('api_calls')->default(0);
            $table->integer('items_tracked')->default(0);
            $table->dateTime('reset_at')->nullable(); // When monthly limit resets
            $table->date('recorded_date'); // YYYY-MM-DD for daily tracking
            $table->timestamps();
            $table->unique(['subscription_id', 'recorded_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};