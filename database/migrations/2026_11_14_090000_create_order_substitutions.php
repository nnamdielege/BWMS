<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Substitutions: the customer accepts a different product in place of an oversold or short line.
 * The original keeps its line with the replaced quantity cancelled as "substituted"
 * (qty_substituted_base, part of qty_cancelled_base); the replacement is a line of its own
 * pointing back at it. order_substitutions keeps the record: who, why, prices, channel follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignId('substitute_for_line_id')->nullable()->after('order_id')->constrained('order_lines')->restrictOnDelete();
            // Part of qty_cancelled_base: cancelled because a substitute was accepted, not short.
            $table->unsignedInteger('qty_substituted_base')->default(0)->after('qty_cancelled_base');
        });

        Schema::create('order_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('original_line_id')->constrained('order_lines')->restrictOnDelete();
            // Null once undone: the substitute line is removed.
            $table->foreignId('substitute_line_id')->nullable()->constrained('order_lines')->nullOnDelete();
            $table->foreignId('original_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('substitute_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('substitute_unit_id')->constrained('product_units')->restrictOnDelete();
            $table->string('substitute_line_ref', 128);
            $table->unsignedInteger('original_qty_base');      // of the original, cancelled as substituted
            $table->unsignedInteger('original_per');
            $table->decimal('original_unit_price', 12, 4)->nullable();
            $table->unsignedInteger('substitute_qty_base');
            $table->unsignedInteger('substitute_per');
            $table->decimal('substitute_unit_price', 12, 4)->nullable();
            $table->boolean('kept_original_price')->default(false);
            $table->decimal('price_difference', 12, 2)->nullable(); // substitute value − original value, ex GST
            $table->string('note', 500);
            $table->foreignId('pick_list_id')->nullable()->constrained()->nullOnDelete(); // made from a short pick
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            // Ordermentum can't be changed from BWMS: staff tick it off once done by hand.
            $table->timestamp('channel_done_at')->nullable();
            $table->foreignId('channel_done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('channel_undo_done_at')->nullable();
            $table->foreignId('channel_undo_done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_substitutions');
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('substitute_for_line_id');
            $table->dropColumn('qty_substituted_base');
        });
    }
};
