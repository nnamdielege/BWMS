<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance → Income allocation (Profit First). Advice only: nothing here moves money.
 *
 * allocation_accounts: the bank accounts receipts are split across. Only the last 4 digits of an
 * account number are ever stored. role says what the account does (income = where receipts land,
 * tax = calculated, the rest split by weight; debt_service is optional and off until switched on).
 * allocation_receipts: money that landed in Income, entered or imported from a bank CSV, waiting
 * until a run takes it (run_id). fingerprint de-duplicates CSV rows across imports.
 * allocation_runs: one per allocation day, with the whole calculation as it was shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('role', 20)->unique(); // income, tax, stock, owners_comp, opex, profit, vault, debt_service
            $table->string('name', 100);
            $table->string('xero_code', 20)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->decimal('weight', 6, 2)->nullable();        // split roles only
            $table->decimal('target_weight', 6, 2)->nullable(); // for the quarterly review; null = no target
            $table->string('institution', 100)->nullable();
            $table->char('last4', 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('allocation_runs', function (Blueprint $table) {
            $table->id();
            $table->date('allocation_date')->unique();
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('receipts_total', 12, 2);
            $table->string('gst_basis', 10);
            $table->json('snapshot');
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at');
            $table->timestamps();
        });

        Schema::create('allocation_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allocation_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 20);
            $table->string('name', 100);
            $table->string('xero_code', 20)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('reference', 60);
            $table->index('role');
        });

        Schema::create('allocation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);
            $table->string('source', 10); // manual, csv
            $table->string('fingerprint', 40)->nullable()->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['allocation_run_id', 'received_on']);
        });

        $now = now();
        $accounts = [
            ['income', 'PF1 Income', '1010', 'Receipts hub: every customer payment lands here', null],
            ['tax', 'PF3 Tax', '1012', 'GST and company income tax', null],
            ['stock', 'PF2 Stock', '1011', 'Supplier payments; overdraft attached', 73],
            ['owners_comp', "PF4 Owner's Comp", '1013', "Directors' pay", 10.5],
            ['opex', 'PF5 OpEx', '1014', 'Running costs', 5],
            ['profit', 'PF6 Profit', '1015', 'Profit: half distributed each quarter', 0.5],
            ['vault', 'PF7 Vault', '1016', 'Reserve of 4–8 weeks of fixed costs (separate bank)', 0.5],
            ['debt_service', 'Debt Service', null, 'Temporary: paying down debt in priority order', 0],
        ];
        foreach ($accounts as $i => [$role, $name, $code, $purpose, $weight]) {
            DB::table('allocation_accounts')->insert([
                'role' => $role, 'name' => $name, 'xero_code' => $code, 'purpose' => $purpose, 'weight' => $weight,
                'is_active' => $role !== 'debt_service', 'sort' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_receipts');
        Schema::dropIfExists('allocation_run_lines');
        Schema::dropIfExists('allocation_runs');
        Schema::dropIfExists('allocation_accounts');
    }
};
