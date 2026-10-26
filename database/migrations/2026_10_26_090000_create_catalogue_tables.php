<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['parent_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            // Every ledger quantity for this product is an integer count of this unit.
            $table->string('base_unit', 32)->default('each');
            $table->boolean('is_batch_tracked')->default(false);
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->unsignedSmallInteger('short_dated_days')->nullable();
            $table->unsignedInteger('reorder_point')->nullable();
            $table->unsignedInteger('reorder_qty')->nullable();
            // Weighted average cost per base unit, maintained by receipts (step 4).
            $table->decimal('avg_cost', 12, 4)->default(0);
            $table->decimal('sell_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'name']);
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name', 64);
            $table->string('code', 64)->nullable()->unique();
            $table->unsignedInteger('base_qty');
            $table->boolean('is_base')->default(false);
            $table->boolean('purchasable')->default(true);
            $table->boolean('sellable')->default(true);
            $table->boolean('default_purchase')->default(false);
            $table->boolean('default_sale')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'base_qty']);
            $table->unique(['product_id', 'name']);
        });

        Schema::create('barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_unit_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 64)->unique();
            $table->string('type', 16)->default('other');
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('type', 8); // site | zone | bin
            $table->string('code', 32);
            $table->string('name');
            // Only bins hold stock. Flags drive available-to-sell and default flows.
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_quarantine')->default(false);
            $table->boolean('is_receiving')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['parent_id', 'code']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcodes');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('locations');
    }
};
