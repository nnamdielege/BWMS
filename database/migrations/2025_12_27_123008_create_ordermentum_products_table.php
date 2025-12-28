<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordermentum_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique()->comment('Product SKU');
            $table->uuid('product_id')->comment('Product ID from Ordermentum');
            $table->uuid('variant_id')->unique()->comment('Variant ID from Ordermentum');
            $table->string('name')->comment('Variant name');
            $table->longText('description')->nullable();
            $table->decimal('price', 10, 4)->comment('Selling price');
            $table->decimal('cost', 10, 4)->default(0)->comment('Cost price');
            $table->string('tax_code')->nullable();
            $table->string('sales_code')->nullable();
            $table->string('categories')->nullable()->comment('Product categories');
            $table->boolean('enabled')->default(true);
            $table->decimal('base_plus_tax', 10, 4)->nullable();
            $table->boolean('has_image')->default(false);
            $table->timestamp('created_at_ordermentum')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('updated_at_ordermentum')->nullable();
            $table->string('updated_by')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('groups')->nullable();
            $table->string('sales_unit')->default('unit');
            $table->integer('multiples_of')->default(1);
            $table->decimal('weight', 10, 4)->default(1);
            $table->string('pricing_unit')->default('unit');
            $table->string('highlight_badge')->nullable();
            $table->integer('minimum_quantity')->default(1);
            $table->json('delivery_days')->nullable();
            $table->integer('shipped_quantity')->default(0);
            $table->string('stock_availability')->default('In Stock');
            $table->timestamps();

            // Indexes
            $table->index('product_id');
            $table->index('sku');
            $table->index('enabled');
            $table->index('stock_availability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordermentum_products');
    }
};
