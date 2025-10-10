<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')
                ->constrained('sales_orders')
                ->onDelete('cascade');
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('cascade');
            // $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            // $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->integer('quantity_fulfilled')->default(0);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('subtotal', 10, 2);
            $table->string('bin_location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // $table->index(['sales_order_id', 'product_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales_order_items');
    }
};