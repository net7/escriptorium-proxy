<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'key_hash',
        'key_prefix',
        'permissions',
        'rate_limit',
        'last_used_at',
        'expires_at',
        'is_active',
        'is_escriptorium_direct',
        'escriptorium_user_id',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'rate_limit' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'is_escriptorium_direct' => 'boolean',
            'escriptorium_user_id' => 'integer',
        ];
    }

    /**
     * Generate a new API key with secure random string.
     *
     * @return array{model: static, plain_key: string}
     */
    public static function generate(string $name, array $permissions = [], int $rateLimit = 60, ?\DateTimeInterface $expiresAt = null): array
    {
        $plainKey = 'esk_'.Str::random(40);

        $model = static::create([
            'name' => $name,
            'key_hash' => hash('sha256', $plainKey),
            'key_prefix' => Str::substr($plainKey, 0, 12),
            'permissions' => $permissions,
            'rate_limit' => $rateLimit,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        return [
            'model' => $model,
            'plain_key' => $plainKey,
        ];
    }

    /**
     * Find an API key by its plain text value.
     */
    public static function findByKey(string $plainKey): ?static
    {
        $hash = hash('sha256', $plainKey);

        return static::where('key_hash', $hash)->first();
    }

    /**
     * Check if the API key is currently valid.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if the API key has permission for a specific action.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        // Empty permissions means all access
        if (empty($permissions)) {
            return true;
        }

        // Check for wildcard
        if (in_array('*', $permissions)) {
            return true;
        }

        return in_array($permission, $permissions);
    }

    /**
     * Record usage of this API key.
     */
    public function recordUsage(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Log an API request.
     */
    public function logRequest(string $endpoint, string $method, ?string $ip = null, ?string $userAgent = null): ApiKeyLog
    {
        return $this->logs()->create([
            'endpoint' => $endpoint,
            'method' => $method,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Scope to only active keys.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to only non-expired keys.
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope to only valid keys (active and not expired).
     */
    public function scopeValid($query)
    {
        return $query->active()->notExpired();
    }

    /**
     * Get the logs for this API key.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ApiKeyLog::class);
    }

    /**
     * Get the transcriptions for this API key.
     */
    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }

    /**
     * Get the rate limiter key for this API key.
     */
    public function rateLimiterKey(): string
    {
        return 'api_key:'.$this->id;
    }

    /**
     * Revoke this API key.
     */
    public function revoke(): bool
    {
        return $this->update(['is_active' => false]);
    }
}
