<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_date_reason', 24)->nullable()->after('delivery_date_origin'); // consolidated
            // Where a consolidated order came from, so Undo restores it exactly.
            $table->date('original_delivery_date')->nullable()->after('delivery_date_reason');
            $table->string('original_delivery_date_origin', 16)->nullable()->after('original_delivery_date');
        });

        Schema::table('delivery_runs', function (Blueprint $table) {
            // Groups runs on the driver's manifest only. Runs stay date + area, so pick lists stay clean.
            $table->string('trip_label', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_runs', fn (Blueprint $table) => $table->dropColumn('trip_label'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['delivery_date_reason', 'original_delivery_date', 'original_delivery_date_origin']));
    }
};
