<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('key_hash', 64)->unique()->comment('SHA256 hash of the API key');
            $table->string('key_prefix', 12)->comment('First 12 chars for identification');
            $table->json('permissions')->nullable()->comment('Granular permissions for endpoints');
            $table->unsignedInteger('rate_limit')->default(60)->comment('Requests per minute');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
            $table->index('key_prefix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
