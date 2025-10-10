<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('bin_code')->unique();
            $table->string('aisle')->nullable();
            $table->string('rack')->nullable();
            $table->string('level')->nullable();
            $table->string('position')->nullable();
            $table->integer('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['warehouse_id', 'bin_code']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('bins');
    }
};