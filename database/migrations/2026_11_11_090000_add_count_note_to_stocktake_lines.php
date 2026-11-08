<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            // Where the count came from when it wasn't typed in, e.g. "uploaded from counts.xlsx".
            $table->string('count_note', 255)->nullable()->after('counted_at');
        });
    }

    public function down(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->dropColumn('count_note');
        });
    }
};
