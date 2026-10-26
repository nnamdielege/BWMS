<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy IDs make bwms:migrate-legacy re-runnable (suppliers, customers and POs already have them).
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->after('parent_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->after('is_active');
        });

        // One external sales-channel listing = one product + one pack size (spec B2.1, B5).
        Schema::create('channel_listings', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 24);
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->constrained()->restrictOnDelete();
            $table->string('external_product_id', 64)->nullable();
            $table->string('external_variant_id', 64);
            $table->string('external_sku', 128)->nullable();
            $table->string('external_location_id', 64)->nullable();
            // Off until the channel cutover: nothing is pushed for a listing until enabled.
            $table->boolean('sync_enabled')->default(false);
            $table->unsignedInteger('safety_buffer')->default(0);
            $table->integer('last_pushed_qty')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->integer('last_remote_qty')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_variant_id']);
            $table->unique(['channel', 'product_id', 'product_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_listings');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('legacy_id'));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('legacy_id'));
    }
};
