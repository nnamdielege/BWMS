<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('address');
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('postal_code', 20);
            $table->string('country', 100);
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('manager')->nullable();  // Make sure this exists
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};