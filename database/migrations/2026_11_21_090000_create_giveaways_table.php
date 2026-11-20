<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Samples, gifts, favours, staff and internal use: who got it, why, and who approved it.
 * One row per give-away write-off movement (the ledger holds the stock and the cost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reason_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('base_qty_per_unit')->default(1);
            $table->decimal('packs', 12, 3);
            $table->unsignedInteger('qty_base');
            // A customer from the list, or a name typed in (staff, a cafe that isn't a customer yet…).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->text('purpose');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('value', 12, 2)->default(0);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index('occurred_at');
            $table->index(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaways');
    }
};
