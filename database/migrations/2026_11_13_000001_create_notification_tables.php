<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('count');
            $table->timestamp('created_at');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->nullable()->constrained('notification_batches');
            $table->string('correlation_id', 100);
            $table->string('channel', 8);
            $table->string('recipient');
            $table->text('content');
            $table->string('priority', 8);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('scheduled_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'id']);
            $table->index(['batch_id', 'status']);
            $table->index(['channel', 'id']);
            $table->index(['status', 'id']);
            $table->index('created_at');
            $table->index('finished_at');
        });

        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->string('key_hash', 64)->primary();
            $table->string('payload_hash', 64);
            $table->uuid('resource_id');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_batches');
    }
};
