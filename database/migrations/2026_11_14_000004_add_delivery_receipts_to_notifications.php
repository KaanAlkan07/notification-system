<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('delivery_status', 24)->nullable();
            $table->string('delivery_error_code', 16)->nullable();
            $table->timestamp('delivery_updated_at')->nullable();
            $table->index(['delivery_status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['delivery_status', 'id']);
            $table->dropColumn(['delivery_status', 'delivery_error_code', 'delivery_updated_at']);
        });
    }
};
