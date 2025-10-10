<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For MySQL
        DB::statement("ALTER TABLE inventory_transactions MODIFY COLUMN type ENUM('adjustment', 'damage', 'count', 'transfer_in', 'transfer_out', 'sale', 'purchase', 'return') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE inventory_transactions MODIFY COLUMN type VARCHAR(50) NOT NULL");
    }
};