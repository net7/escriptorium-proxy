<?php

use App\Enums\eScriptoriumStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('api_key_id');

            // Dalla ProcessRequest (campi necessari per tracciamento)
            $table->string('script_name');
            $table->string('manifest_url');
            $table->string('pages')->nullable();
            $table->unsignedInteger('recognition_model_id');
            $table->unsignedInteger('segmentation_model_id')->nullable();
            $table->string('text_direction');

            // Stato e risultato
            $table->string('status')->default(eScriptoriumStatusEnum::Pending->value);
            $table->json('service_data')->nullable();
            $table->longText('text')->nullable();

            $table->timestamps();

            $table->foreign('api_key_id')
                ->references('id')
                ->on('api_keys')
                ->cascadeOnDelete();

            $table->index(['api_key_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
