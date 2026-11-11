<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_listings', function (Blueprint $table) {
            // The channel's price for this listing's pack, ex GST: from the catalogue import and
            // refreshed from each order's line price. The margin report's fallback sell price.
            $table->decimal('price', 12, 4)->nullable()->after('safety_buffer');
            $table->timestamp('price_seen_at')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('channel_listings', fn (Blueprint $table) => $table->dropColumn(['price', 'price_seen_at']));
    }
};
