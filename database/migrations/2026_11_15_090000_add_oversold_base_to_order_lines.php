<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which line of an oversold order is short, and by how much: base units of the line's
 * reservation not covered by sellable stock, under the same allocation as the order
 * flag (OversoldCheck). Kept by OversoldCheck; 0 when covered. Filled for existing
 * orders by `php artisan orders:recheck-oversold`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedInteger('oversold_base')->default(0)->after('qty_substituted_base');
            $table->index('oversold_base');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropIndex(['oversold_base']);
            $table->dropColumn('oversold_base');
        });
    }
};
