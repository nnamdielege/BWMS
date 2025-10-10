<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('address_type', ['billing', 'shipping', 'both'])->default('both');
            $table->string('address_label')->nullable();
            $table->text('address_line1');
            $table->text('address_line2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('postal_code');
            $table->string('country')->default('AU');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'address_type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_addresses');
    }
};