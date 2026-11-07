<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Stock is reserved, but the order can't be picked until it's paid or a manager releases it.
            $table->boolean('payment_hold')->default(false)->after('needs_review');
            $table->timestamp('pay_by')->nullable()->after('payment_hold');
            $table->timestamp('hold_released_at')->nullable()->after('pay_by');
            $table->foreignId('hold_released_by')->nullable()->after('hold_released_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hold_released_by');
            $table->dropColumn(['payment_hold', 'pay_by', 'hold_released_at']);
        });
    }
};
