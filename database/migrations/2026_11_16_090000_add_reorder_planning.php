<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder suggestions (Purchasing → Reorder suggestions).
 *
 * Suppliers: when they're ordered from (order days, or a review period), the minimum
 * order value and the minimum per line (MOQ, in supplier packs). Products: the usual
 * supplier, a lead-time override and the supplier's order multiple.
 *
 * sales_history: sales from before BWMS, imported (Ordermentum now, portal CSV later),
 * one row per order line. Read only by the reorder maths; never orders or reservations.
 * BWMS's own sales are read from dispatch movements, not copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('order_days', 32)->nullable()->after('lead_time_days');    // ISO weekdays "1,4" = Mon, Thu
            $table->unsignedSmallInteger('review_period_days')->nullable()->after('order_days');
            $table->decimal('min_order_value', 12, 2)->nullable()->after('review_period_days'); // ex GST, at cost
            $table->unsignedInteger('moq_packs')->nullable()->after('min_order_value');          // per line, in supplier packs
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('range_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('reorder_qty');  // overrides the supplier's
            $table->unsignedInteger('order_multiple_base')->nullable()->after('lead_time_days'); // supplier pack, base units
        });

        Schema::create('sales_history', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);                 // ordermentum_history | portal_history
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('sale_date');                    // delivery date, Brisbane
            $table->unsignedInteger('qty_base');
            $table->string('external_ref', 64);           // the channel's order id
            $table->string('line_ref', 128);
            $table->timestamps();
            $table->unique(['source', 'external_ref', 'line_ref']);
            $table->index(['product_id', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_history');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['lead_time_days', 'order_multiple_base']);
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['order_days', 'review_period_days', 'min_order_value', 'moq_packs']);
        });
    }
};
