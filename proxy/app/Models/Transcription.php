<?php

namespace App\Models;

use App\Enums\eScriptoriumStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transcription extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'api_key_id',
        'direct_mode_token',
        'script_name',
        'manifest_url',
        'pages',
        'recognition_model_id',
        'segmentation_model_id',
        'text_direction',
        'status',
        'service_data',
        'text',
    ];

    protected function casts(): array
    {
        return [
            'recognition_model_id' => 'integer',
            'segmentation_model_id' => 'integer',
            'service_data' => 'array',
            'status' => eScriptoriumStatusEnum::class,
            'direct_mode_token' => 'encrypted',
        ];
    }

    /**
     * Get the API key that owns this transcription.
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by API key.
     */
    public function scopeForApiKey($query, string $apiKeyId)
    {
        return $query->where('api_key_id', $apiKeyId);
    }
}
