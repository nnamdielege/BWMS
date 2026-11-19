<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spoilage: why stock went into quarantine, and the credits expected from suppliers
 * for stock returned to them. Write-offs themselves stay in the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per movement that put stock into a quarantine bin (a transfer in, or damaged on receipt).
        Schema::create('quarantine_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_key')->default(0);
            $table->string('reason', 16); // expired | damaged | recall | quality
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'location_id', 'batch_key']);
        });

        Schema::create('supplier_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            // The return_to_supplier movement that took the stock out.
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_base');
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('amount', 12, 2); // expected credit, ex GST, at cost when returned
            $table->string('status', 16)->default('open'); // open | received
            $table->text('note')->nullable();
            $table->string('credit_ref', 64)->nullable(); // the supplier's credit note number
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credits');
        Schema::dropIfExists('quarantine_entries');
    }
};
