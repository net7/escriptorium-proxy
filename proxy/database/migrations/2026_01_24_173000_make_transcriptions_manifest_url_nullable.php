<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->string('manifest_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting nullable change can be risky if nulls exist
    }
};
