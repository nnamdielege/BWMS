<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash-aware purchasing: PO approval against a weekly budget, payment due dates and
 * "paid", supplier payment terms in days, per-product safety stock and an opt-out of
 * suggested POs. purchase_orders.status is a string, so the new statuses (suggested,
 * approved, paid) need no column change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // 0 = COD. payment_terms (free text) stays for display.
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('payment_terms');
        });

        Schema::table('products', function (Blueprint $table) {
            // Overrides the reorder settings' safety days; null = use the setting.
            $table->unsignedSmallInteger('safety_stock_days')->nullable()->after('order_multiple_base');
            $table->boolean('auto_reorder')->default(true)->after('safety_stock_days');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('payment_due_date')->nullable()->after('expected_date');
            $table->timestamp('approved_at')->nullable()->after('payment_due_date');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->string('override_reason', 500)->nullable()->after('approved_by'); // set when approved over budget
            $table->timestamp('paid_at')->nullable()->after('override_reason');
            $table->foreignId('paid_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->index('approved_at');
            $table->index(['status', 'payment_due_date']);
        });

        Schema::create('purchasing_budgets', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique(); // a Monday, Brisbane
            $table->decimal('budget', 12, 2);      // ex GST
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * The old code's status enum has no suggested, approved or paid, and would fail to load such a PO,
     * so they go back to their nearest old status first: suggested and approved (not yet sent) become
     * draft, paid (always fully received) becomes received. Approval, override reasons, paid marks,
     * payment due dates and budgets are lost.
     */
    public function down(): void
    {
        DB::table('purchase_orders')->whereIn('status', ['suggested', 'approved'])->update(['status' => 'draft']);
        DB::table('purchase_orders')->where('status', 'paid')->update(['status' => 'received']);

        Schema::dropIfExists('purchasing_budgets');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_due_date']);
            $table->dropIndex(['approved_at']);
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['payment_due_date', 'approved_at', 'override_reason', 'paid_at']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['safety_stock_days', 'auto_reorder']));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
    }
};
