<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKeyLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_key_id',
        'endpoint',
        'method',
        'ip_address',
        'user_agent',
        'response_status',
        'response_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_time_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the API key that owns this log.
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * Update the response information after the request completes.
     */
    public function recordResponse(int $status, int $responseTimeMs): void
    {
        $this->update([
            'response_status' => $status,
            'response_time_ms' => $responseTimeMs,
        ]);
    }
}
