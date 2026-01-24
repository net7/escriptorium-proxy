<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->boolean('is_escriptorium_direct')->default(false)->after('is_active');
            $table->unsignedInteger('escriptorium_user_id')->nullable()->after('is_escriptorium_direct');

            // Index per lookup veloci delle ApiKey virtuali
            $table->index(['is_escriptorium_direct', 'escriptorium_user_id'], 'api_keys_escriptorium_direct_index');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropIndex('api_keys_escriptorium_direct_index');
            $table->dropColumn(['is_escriptorium_direct', 'escriptorium_user_id']);
        });
    }
};
