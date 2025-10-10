<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('location_code')->unique();
            $table->string('name');
            $table->enum('location_type', ['zone', 'aisle', 'rack', 'shelf', 'bin'])->default('bin');
            $table->foreignId('parent_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->integer('capacity')->nullable();
            $table->string('dimensions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['warehouse_id', 'location_type']);
            $table->index('location_code');
        });
    }

    public function down()
    {
        Schema::dropIfExists('warehouse_locations');
    }
};