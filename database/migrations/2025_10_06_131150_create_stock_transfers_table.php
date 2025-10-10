<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->integer('quantity');
            $table->enum('status', ['pending', 'in_transit', 'completed', 'cancelled'])->default('pending');
            $table->date('transfer_date');
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['from_warehouse_id', 'to_warehouse_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_transfers');
    }
};