<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw inbound events (NFR-5): stored before anything else happens, deduped, replayable.
        Schema::create('integration_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 24);
            $table->string('event', 64);
            $table->string('external_id', 64)->nullable();
            $table->char('dedupe_key', 64)->unique();
            // received | held | processed | needs_mapping | failed | ignored
            $table->string('status', 16)->default('received');
            $table->json('headers')->nullable();
            $table->longText('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['channel', 'status', 'id']);
            $table->index(['channel', 'external_id']);
        });

        // Outbound/inbound API calls (B7, NFR-12). Pruned after 90 days.
        Schema::create('sync_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 24);
            $table->string('operation', 24); // push | check | fetch_order | poll
            $table->foreignId('channel_listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 64)->nullable();
            $table->json('request')->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['channel', 'created_at']);
            $table->index(['success', 'created_at']);
        });

        // Channel variants seen on orders with no listing: the "needs mapping" queue.
        Schema::create('channel_unmapped_variants', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 24);
            $table->string('external_variant_id', 64);
            $table->string('external_sku', 128)->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'external_variant_id']);
        });

        // Ordermentum has no version number; updatedAt in milliseconds is used instead.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('external_version')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_unmapped_variants');
        Schema::dropIfExists('sync_attempts');
        Schema::dropIfExists('integration_inbox');
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('external_version')->default(0)->change();
        });
    }
};
