<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {  // SINGULAR
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('cascade');
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('quantity_available')->default(0);
            $table->integer('quantity_allocated')->default(0);
            $table->integer('quantity_on_order')->default(0);
            $table->string('bin_location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Unique constraint: one inventory record per product per warehouse
            $table->unique(['product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');  // SINGULAR
    }
};