<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The invoice and order numbers Ordermentum shows the café (external_ref is Ordermentum's
 * internal id, which never appears on a bank statement). Income allocation matches a bank
 * receipt to its order by them. Filled on ingest; orders:backfill-channel-numbers fills older ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel_invoice_number', 64)->nullable()->after('external_ref');
            $table->string('channel_order_number', 64)->nullable()->after('channel_invoice_number');
            $table->index('channel_invoice_number');
            $table->index('channel_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['channel_order_number']);
            $table->dropIndex(['channel_invoice_number']);
            $table->dropColumn(['channel_invoice_number', 'channel_order_number']);
        });
    }
};
