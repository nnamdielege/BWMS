<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('ordermentum_variant_id')->nullable()->unique()->after('id');
            $table->uuid('ordermentum_product_id')->nullable()->after('ordermentum_variant_id');
            $table->string('ordermentum_sku')->nullable()->after('sku');
            $table->string('sales_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['ordermentum_variant_id', 'ordermentum_product_id', 'ordermentum_sku', 'sales_code']);
        });
    }
};
