<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('operation');
            $table->string('request_hash');
            $table->uuid('notification_id')->nullable();
            $table->uuid('notification_batch_id')->nullable();
            $table->json('response_payload');
            $table->unsignedSmallInteger('status_code');
            $table->timestamps();

            $table->foreign('notification_id')->references('id')->on('notifications')->nullOnDelete();
            $table->foreign('notification_batch_id')->references('id')->on('notification_batches')->nullOnDelete();
            $table->index(['operation', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
