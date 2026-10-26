<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('ordermentum_retailer_id', 64)->nullable()->unique();
            $table->unsignedBigInteger('portal_user_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('channel', 16); // manual | portal | ordermentum
            $table->string('external_ref', 128)->nullable();
            $table->unsignedInteger('external_version')->default(0);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 24)->default('open');
            $table->boolean('oversold')->default(false);
            $table->timestamp('oversold_at')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('short_ship_policy', 16)->default('backorder'); // backorder | cancel
            $table->date('requested_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_ref']);
            $table->index(['status', 'site_id']);
            $table->index('oversold');
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('line_ref', 128)->nullable();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');                  // in packs
            $table->unsignedInteger('base_qty_per_unit');
            $table->unsignedInteger('qty_base');
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->unsignedInteger('qty_dispatched_base')->default(0);
            $table->unsignedInteger('qty_cancelled_base')->default(0); // released short / reduced after dispatch started
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['order_id', 'line_ref']);
        });

        // One row per order line; qty_base = what is still held for it.
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_id')->constrained('locations')->restrictOnDelete();
            $table->unsignedInteger('qty_base')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'site_id']);
        });

        Schema::create('pick_lists', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->foreignId('site_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 16)->default('open'); // open | dispatched | cancelled
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'site_id']);
        });

        Schema::create('pick_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pick_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('batch_key')->default(0);
            $table->unsignedInteger('qty_to_pick_base');
            $table->unsignedInteger('qty_picked_base')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'location_id', 'batch_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pick_list_lines');
        Schema::dropIfExists('pick_lists');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
    }
};
