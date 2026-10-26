<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            // Pending push: set on any stock change, cleared when that figure is pushed.
            // One drain job pushes everything pending, in bulk where possible.
            $table->timestamp('push_requested_at', 6)->nullable()->after('safety_buffer');
            $table->boolean('push_force')->default(false)->after('push_requested_at');
            // Ordermentum only decrements tracked items (confirmed 2026-10-27): om:probe tracking / nightly check.
            $table->boolean('remote_tracked')->nullable()->after('last_remote_qty');
            $table->string('remote_status', 16)->nullable()->after('remote_tracked'); // tracked | untracked | missing | error
            $table->index('push_requested_at');
        });

        Schema::table('integration_inbox', function (Blueprint $table) {
            // e.g. {"variants": [...]} for an order held for mapping, so its other SKUs are not pushed meanwhile.
            $table->json('meta')->nullable()->after('error');
            // Ordermentum signs webhooks (header and algorithm unconfirmed): valid | invalid | missing | unconfigured
            $table->string('signature_status', 16)->nullable()->after('headers');
        });
    }

    public function down(): void
    {
        Schema::table('integration_inbox', fn (Blueprint $table) => $table->dropColumn(['meta', 'signature_status']));
        Schema::table('channel_listings', function (Blueprint $table) {
            $table->dropIndex(['push_requested_at']);
            $table->dropColumn(['push_requested_at', 'push_force', 'remote_tracked', 'remote_status']);
        });
    }
};
