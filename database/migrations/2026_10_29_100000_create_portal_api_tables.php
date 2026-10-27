<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Portal contract B4.4: a replay with the same key and body gets the stored response;
        // the same key with a different body is an idempotency conflict.
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('client', 24);
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['client', 'idempotency_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
