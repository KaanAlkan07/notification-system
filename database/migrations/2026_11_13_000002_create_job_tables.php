<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue', 16);
            $table->longText('payload');
            $table->unsignedInteger('attempts');
            $table->unsignedBigInteger('reserved_at')->nullable();
            $table->unsignedBigInteger('available_at');
            $table->unsignedBigInteger('created_at');
            $table->index(['queue', 'reserved_at', 'available_at', 'id']);
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
    }
};
