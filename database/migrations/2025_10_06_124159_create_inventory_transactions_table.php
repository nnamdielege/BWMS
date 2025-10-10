<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['in', 'out', 'adjustment', 'transfer'])->default('adjustment');
            $table->integer('quantity');
            $table->integer('quantity_before')->default(0);
            $table->integer('quantity_after')->default(0);
            $table->string('reason')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'type']);
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('inventory_transactions');
    }
};