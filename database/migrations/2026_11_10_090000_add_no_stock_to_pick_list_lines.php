<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pick_list_lines', function (Blueprint $table) {
            // A line with nothing to allocate: on the list so the picker sees it, in no bin, confirmed short.
            $table->boolean('no_stock')->default(false)->after('batch_key');
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('pick_list_lines')->where('no_stock', true)->delete();
        Schema::table('pick_list_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->dropColumn('no_stock');
        });
    }
};
