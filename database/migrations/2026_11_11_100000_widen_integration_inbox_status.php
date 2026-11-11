<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_inbox', function (Blueprint $table) {
            // + ignored_historical (18 characters; the column held 16)
            $table->string('status', 24)->default('received')->change();
        });
    }

    public function down(): void
    {
        Schema::table('integration_inbox', function (Blueprint $table) {
            $table->string('status', 16)->default('received')->change();
        });
    }
};
