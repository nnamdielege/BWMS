<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_push_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventory')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->string('sku');
            $table->integer('pushed_quantity');
            $table->string('status')->default('success'); // success, failed
            $table->timestamp('pushed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['inventory_id', 'pushed_at']);
            $table->index(['product_id', 'pushed_at']);
            $table->index(['warehouse_id', 'pushed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_push_logs');
    }
};
