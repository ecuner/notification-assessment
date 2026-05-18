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
        Schema::create('notification_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->unsignedSmallInteger('provider_status_code')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('provider_status')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->unique(['notification_id', 'attempt_number']);
            $table->index(['provider_status_code', 'attempted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_attempts');
    }
};
