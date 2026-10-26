<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-site landing bin for accepted receipts. Default: the sellable STOCK bin,
        // so received stock is available at once; RECEIVING + put-away is opt-in.
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('receive_location_id')->nullable()->after('parent_id')->constrained('locations')->nullOnDelete();
        });

        foreach (DB::table('locations')->where('type', 'site')->whereNull('receive_location_id')->pluck('id') as $siteId) {
            $binId = DB::table('locations')->where('parent_id', $siteId)->where('type', 'bin')->where('code', 'STOCK')
                ->where('is_sellable', true)->where('is_active', true)->value('id');
            if ($binId) {
                DB::table('locations')->where('id', $siteId)->update(['receive_location_id' => $binId]);
            }
        }

        // A bin to physically count, raised when dispatch takes it below zero.
        // open_location_id keeps at most one open task per bin.
        Schema::create('count_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->string('status', 16)->default('open'); // open | done | dismissed
            $table->string('reason', 64);
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->integer('on_hand_at_raise')->nullable();
            $table->unsignedInteger('times_raised')->default(1);
            $table->foreignId('stocktake_id')->nullable()->constrained('stocktakes');
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE count_tasks ADD open_location_id BIGINT UNSIGNED AS (IF(status = 'open', location_id, NULL)) PERSISTENT");
        DB::statement('ALTER TABLE count_tasks ADD UNIQUE count_tasks_one_open_per_bin (open_location_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('count_tasks');
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receive_location_id');
        });
    }
};
