<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number')->unique();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity_before');
            $table->integer('quantity_adjusted');
            $table->integer('quantity_after');
            $table->enum('adjustment_type', ['increase', 'decrease'])->default('increase');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['warehouse_id', 'product_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_adjustments');
    }
};