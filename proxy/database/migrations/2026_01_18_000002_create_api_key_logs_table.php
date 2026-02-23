<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('api_key_id');
            $table->string('endpoint');
            $table->string('method', 10);
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('api_key_id')
                ->references('id')
                ->on('api_keys')
                ->cascadeOnDelete();

            $table->index(['api_key_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_logs');
    }
};
