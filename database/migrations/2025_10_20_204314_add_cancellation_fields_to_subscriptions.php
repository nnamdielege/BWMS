<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Add these columns if they don't exist
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
            $table->timestamp('cancels_at')->nullable()->after('cancelled_at'); // For future cancellation
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'cancels_at']);
        });
    }
};