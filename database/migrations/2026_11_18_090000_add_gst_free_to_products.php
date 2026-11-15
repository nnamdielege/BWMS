<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST-free products (fresh food and the like). Sets the default GST rate of a PO line
 * (0% or 10%; the buyer can still change it) and grosses up the cash-out inflow.
 * Filled from Xero item tax codes with products:import-gst-flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('gst_free')->default(false)->after('sell_price');
            $table->index('gst_free');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['gst_free']);
            $table->dropColumn('gst_free');
        });
    }
};
