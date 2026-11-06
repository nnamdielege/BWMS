<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The one date an order is delivered. requested_delivery_date stays as what the source last sent.
            $table->date('delivery_date')->nullable()->after('requested_delivery_date')->index();
            $table->string('delivery_date_origin', 16)->nullable()->after('delivery_date'); // source | fallback | manager
            // Why needs_review is set, by key. Kept when flags are marked handled, so the same reason isn't raised twice.
            $table->json('review_notes')->nullable()->after('needs_review');
        });

        // What each order is effectively on today. New rules apply from the next change, or from orders:backfill-delivery-dates.
        DB::table('orders')->leftJoin('delivery_runs', 'delivery_runs.id', '=', 'orders.delivery_run_id')->update([
            'orders.delivery_date' => DB::raw('COALESCE(delivery_runs.run_date, orders.requested_delivery_date)'),
            'orders.delivery_date_origin' => DB::raw("CASE
                WHEN orders.run_assignment = 'manual' THEN 'manager'
                WHEN orders.requested_delivery_date IS NOT NULL THEN 'source'
                WHEN delivery_runs.run_date IS NOT NULL THEN 'fallback'
                ELSE NULL END"),
        ]);

        DB::table('reason_codes')->insertOrIgnore([
            'code' => 'BATCH_ENABLE', 'name' => 'Batch tracking enabled', 'movement_type' => 'adjustment', 'direction' => 'both',
            'requires_note' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The reason code stays: movements may reference it.
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_date', 'delivery_date_origin', 'review_notes']);
        });
    }
};
