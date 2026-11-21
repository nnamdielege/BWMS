<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff payroll in Income allocation: what each period's wages and super need in the Payroll
 * account (PF8). Only what the estimate needs is kept: no TFNs, bank details or other personal
 * data; payroll itself stays in Xero Payroll. rate is per hour (hourly) or per year (salary).
 * The Payroll account is on while any staff member is active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_staff', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('role', 100)->nullable();
            $table->string('pay_type', 10); // hourly, salary
            $table->decimal('rate', 12, 2);
            $table->decimal('hours_per_week', 5, 2)->nullable();
            $table->decimal('super_percent', 5, 2)->default(12);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('allocation_accounts')->insert([
            'role' => 'payroll', 'name' => 'PF8 Payroll', 'xero_code' => null, 'purpose' => 'Staff wages, super and PAYG withholding',
            'weight' => null, 'is_active' => false, 'sort' => 8, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('allocation_run_lines')->where('role', 'payroll')->update(['allocation_account_id' => null]);
        DB::table('allocation_accounts')->where('role', 'payroll')->delete();
        Schema::dropIfExists('allocation_staff');
    }
};
