<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Where the product is sold: none (purchase/internal), portal, ordermentum or both.
            // Drives pushes and "unmapped" warnings.
            $table->string('sell_channels', 16)->default('ordermentum')->after('is_active');
            // Services and the like: no stock, stocktakes, reservations, picking or pushes.
            $table->boolean('is_stock_tracked')->default(true)->after('sell_channels');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['sell_channels', 'is_stock_tracked']));
    }
};
