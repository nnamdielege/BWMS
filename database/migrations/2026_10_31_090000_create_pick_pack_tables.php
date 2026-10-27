<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery areas: fixed weekdays per area, optional next-day delivery, suburb/postcode lists.
        Schema::create('delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->json('weekdays');                        // ISO weekdays 1 (Mon) .. 5 (Fri)
            $table->boolean('next_day_eligible')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('delivery_area_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_area_id')->constrained()->cascadeOnDelete();
            $table->string('postcode', 4);
            $table->string('suburb', 100)->default(''); // '' = the whole postcode
            $table->unique(['postcode', 'suburb']);
        });

        // A run = one area's deliveries on one date from one site.
        Schema::create('delivery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('locations');
            $table->foreignId('delivery_area_id')->constrained();
            $table->date('run_date');
            $table->string('status', 16)->default('open'); // open | dispatched
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'delivery_area_id', 'run_date']);
            $table->index(['run_date', 'status']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('delivery_street')->nullable()->after('address');
            $table->string('delivery_suburb', 100)->nullable()->after('delivery_street');
            $table->string('delivery_postcode', 4)->nullable()->after('delivery_suburb');
            $table->foreignId('delivery_area_id')->nullable()->after('delivery_postcode')->constrained()->nullOnDelete(); // override
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_run_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
            $table->string('run_assignment', 16)->default('unassigned')->after('delivery_run_id'); // auto | manual | unassigned
            $table->string('delivery_street')->nullable();
            $table->string('delivery_suburb', 100)->nullable();
            $table->string('delivery_postcode', 4)->nullable();
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->text('delivery_instructions')->nullable();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->string('barcode', 32)->nullable()->unique()->after('code');
            $table->unsignedInteger('walk_seq')->default(0)->after('sort_order');
        });

        Schema::table('products', function (Blueprint $table) {
            // No refrigerated transport: chilled lines are picked last.
            $table->boolean('is_chilled')->default(false)->after('is_stock_tracked');
        });

        Schema::table('pick_lists', function (Blueprint $table) {
            $table->foreignId('delivery_run_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
            $table->foreignId('claimed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by');
        });

        Schema::table('pick_list_lines', function (Blueprint $table) {
            $table->timestamp('bin_scanned_at')->nullable();
            $table->timestamp('product_scanned_at')->nullable();
            $table->string('override_reason', 32)->nullable();
            $table->foreignId('picked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_at')->nullable();
        });

        Schema::create('order_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pick_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cartons');
            $table->foreignId('packed_by')->constrained('users');
            $table->timestamp('packed_at');
            $table->timestamps();
            $table->unique(['order_id', 'pick_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_packs');
        Schema::table('pick_list_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('picked_by'));
        Schema::table('pick_list_lines', fn (Blueprint $t) => $t->dropColumn(['bin_scanned_at', 'product_scanned_at', 'override_reason', 'picked_at']));
        Schema::table('pick_lists', function (Blueprint $t) {
            $t->dropConstrainedForeignId('delivery_run_id');
            $t->dropConstrainedForeignId('claimed_by');
            $t->dropColumn('claimed_at');
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('is_chilled'));
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn(['barcode', 'walk_seq']));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('delivery_run_id');
            $t->dropColumn(['run_assignment', 'delivery_street', 'delivery_suburb', 'delivery_postcode', 'delivery_lat', 'delivery_lng', 'delivery_instructions']);
        });
        Schema::table('customers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('delivery_area_id');
            $t->dropColumn(['delivery_street', 'delivery_suburb', 'delivery_postcode']);
        });
        Schema::dropIfExists('delivery_runs');
        Schema::dropIfExists('delivery_area_places');
        Schema::dropIfExists('delivery_areas');
    }
};
