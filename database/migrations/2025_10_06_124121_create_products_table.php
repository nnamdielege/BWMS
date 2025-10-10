<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('slug')->unique()->nullable();
            $table->string('barcode')->unique()->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('product_categories')->onDelete('restrict');
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->string('unit_of_measure', 50);
            $table->integer('reorder_point')->nullable();
            $table->integer('reorder_quantity')->nullable();
            $table->decimal('weight', 10, 2)->nullable();  // ← Make sure this exists
            $table->string('dimensions')->nullable();      // ← And this
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};