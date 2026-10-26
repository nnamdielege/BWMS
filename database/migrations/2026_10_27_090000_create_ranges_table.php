<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional display-only grouping of products (e.g. "Dash syrups"). Flavours
 * are separate products; a range only groups them in lists. Spec B2.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('range_id')->nullable()->after('category_id')->constrained('ranges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('range_id'));
        Schema::dropIfExists('ranges');
    }
};
