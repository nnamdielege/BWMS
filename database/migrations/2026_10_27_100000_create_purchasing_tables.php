<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Gap-free, concurrency-safe document numbers (PO-2026-00001). D-12.
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('name', 32);
            $table->string('period', 8);
            $table->unsignedInteger('last_value')->default(0);
            $table->primary(['name', 'period']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('cc_emails', 512)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('payment_terms', 100)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });

        Schema::table('batches', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('supplier_ref', 64)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('legacy_status', 24)->nullable();
            $table->timestamps();
            $table->index(['status', 'expected_date']);
            $table->index(['supplier_id', 'order_date']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_ordered');            // in packs of product_unit
            $table->unsignedInteger('base_qty_per_unit');      // snapshot of the pack size
            $table->unsignedInteger('qty_ordered_base');
            $table->decimal('unit_cost', 12, 4);               // per pack, ex GST
            $table->decimal('tax_rate', 5, 2)->default(10);
            $table->decimal('line_subtotal', 12, 2);
            $table->unsignedInteger('qty_received_base')->default(0); // accepted + damaged
            $table->unsignedInteger('qty_rejected_base')->default(0);
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained('locations')->restrictOnDelete();
            $table->timestamp('received_at');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('supplier_ref', 64)->nullable(); // delivery docket
            $table->text('notes')->nullable();
            $table->uuid('client_token')->unique();
            $table->timestamps();
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_accepted_base')->default(0);
            $table->unsignedInteger('qty_damaged_base')->default(0);
            $table->unsignedInteger('qty_rejected_base')->default(0);
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('quarantine_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('discrepancy_reason', 24)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('to');
            $table->string('cc', 512)->nullable();
            $table->string('subject');
            $table->text('message')->nullable();
            $table->string('status', 16)->default('queued'); // queued | sent | failed
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('queued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_emails');
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::table('batches', fn (Blueprint $table) => $table->dropForeign(['supplier_id']));
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('settings');
    }
};
