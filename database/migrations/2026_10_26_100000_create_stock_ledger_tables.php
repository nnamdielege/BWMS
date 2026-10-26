<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reason_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('movement_type', 24); // adjustment | write_off
            $table->string('direction', 8);       // in | out | both
            $table->boolean('requires_note')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('lot_code', 64);
            $table->date('best_before')->nullable();
            $table->date('received_on')->nullable();
            // FK added with the suppliers table in step 4.
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['product_id', 'lot_code', 'best_before']);
            $table->index(['product_id', 'best_before']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('qty'); // signed, base units, never 0
            $table->string('type', 24);
            $table->foreignId('reason_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->char('payload_hash', 64);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'location_id', 'batch_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['type', 'occurred_at']);
            $table->index('occurred_at');
        });

        // Projection of SUM(stock_movements.qty). batch_key = batch_id or 0, so
        // the unique key also covers untracked stock (MySQL allows duplicate NULLs).
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_key')->default(0);
            $table->integer('on_hand')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['product_id', 'location_id', 'batch_key']);
            $table->index(['location_id', 'product_id']);
        });

        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('scope_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 16)->default('counting'); // counting | posted | cancelled
            $table->timestamp('snapshot_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_key')->default(0);
            $table->integer('snapshot_qty')->default(0);
            $table->unsignedInteger('counted_qty')->nullable();
            // System quantity at the moment of counting; variance = counted - this.
            $table->integer('system_qty_at_count')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->integer('posted_variance')->nullable();
            $table->timestamps();
            $table->unique(['stocktake_id', 'product_id', 'location_id', 'batch_key'], 'stocktake_lines_unique_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('reason_codes');
    }
};
