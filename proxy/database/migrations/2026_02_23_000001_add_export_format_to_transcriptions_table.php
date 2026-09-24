<?php

use App\Enums\ExportFormatEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->string('export_format')
                ->default(ExportFormatEnum::TeiXml->value)
                ->after('text_direction');

            $table->string('export_file_path')
                ->nullable()
                ->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('transcriptions', function (Blueprint $table) {
            $table->dropColumn(['export_format', 'export_file_path']);
        });
    }
};
