<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add new column as TEXT (encrypted values are longer)
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->text('direct_mode_token')->nullable()->after('api_key_id');
        });

        // Step 2: Migrate and encrypt existing data
        DB::table('transcriptions')
            ->whereNotNull('escriptorium_token')
            ->orderBy('id')
            ->chunk(100, function ($transcriptions) {
                foreach ($transcriptions as $transcription) {
                    DB::table('transcriptions')
                        ->where('id', $transcription->id)
                        ->update([
                            'direct_mode_token' => Crypt::encryptString($transcription->escriptorium_token),
                        ]);
                }
            });

        // Step 3: Drop old column
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn('escriptorium_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Add old column back
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->string('escriptorium_token')->nullable()->after('api_key_id');
        });

        // Step 2: Decrypt and migrate data back
        DB::table('transcriptions')
            ->whereNotNull('direct_mode_token')
            ->orderBy('id')
            ->chunk(100, function ($transcriptions) {
                foreach ($transcriptions as $transcription) {
                    try {
                        $decrypted = Crypt::decryptString($transcription->direct_mode_token);
                        DB::table('transcriptions')
                            ->where('id', $transcription->id)
                            ->update([
                                'escriptorium_token' => $decrypted,
                            ]);
                    } catch (\Exception $e) {
                        // If decryption fails, leave it null
                    }
                }
            });

        // Step 3: Drop new column
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn('direct_mode_token');
        });
    }
};
