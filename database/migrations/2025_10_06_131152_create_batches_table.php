<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number')->unique();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->integer('quantity')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'batch_number']);
            $table->index('expiry_date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('batches');
    }
};