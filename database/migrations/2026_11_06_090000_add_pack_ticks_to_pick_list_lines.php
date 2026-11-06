<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A packer's tick on a line, saved as it's made so it survives leaving the pack screen.
        Schema::table('pick_list_lines', function (Blueprint $table) {
            $table->foreignId('ticked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ticked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pick_list_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ticked_by');
            $t->dropColumn('ticked_at');
        });
    }
};
