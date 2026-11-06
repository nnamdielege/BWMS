<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A carton count typed on the pack screen, before Packed. Kept apart from
        // order_packs, where a row means the order is packed.
        Schema::create('order_pack_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pick_list_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cartons');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['order_id', 'pick_list_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_pack_drafts');
    }
};
