<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service per creare sessioni Django valide per l'autenticazione WebSocket.
 *
 * In Direct Mode, abbiamo solo il token API dell'utente, non le sue credenziali.
 * Per connetterci al WebSocket come quell'utente, dobbiamo creare una sessione Django
 * direttamente nel database PostgreSQL.
 */
class DjangoSessionService
{
    private string $secretKey;

    public function __construct()
    {
        $this->secretKey = config('escriptorium.django.secret_key', 'changeme');
    }

    /**
     * Crea una sessione Django per un utente dato il suo token API.
     *
     * @param  string  $apiToken  Il token API dell'utente eScriptorium
     * @return string|null Il session_key creato, o null se fallisce
     */
    public function createSessionForToken(string $apiToken): ?string
    {
        try {
            // 1. Get user_id and password hash from token
            $tokenData = DB::connection('escriptorium')
                ->table('authtoken_token')
                ->join('users_user', 'authtoken_token.user_id', '=', 'users_user.id')
                ->where('authtoken_token.key', $apiToken)
                ->select('users_user.id', 'users_user.password')
                ->first();

            if (! $tokenData) {
                Log::warning('DjangoSessionService: Token not found', ['token' => substr($apiToken, 0, 8).'...']);

                return null;
            }

            $userId = $tokenData->id;
            $passwordHash = $tokenData->password;

            // 2. Calculate _auth_user_hash (come fa Django)
            $authUserHash = $this->calculateAuthUserHash($passwordHash);

            // 3. Create session data
            $sessionDict = [
                '_auth_user_id' => (string) $userId,
                '_auth_user_backend' => 'django.contrib.auth.backends.ModelBackend',
                '_auth_user_hash' => $authUserHash,
            ];

            // 4. Encode session data (Django format)
            $sessionData = $this->encodeSessionData($sessionDict);

            // 5. Generate session key
            $sessionKey = $this->generateSessionKey();

            // 6. Insert into database
            $expireDate = now()->addDays(14)->toIso8601String();

            DB::connection('escriptorium')
                ->table('django_session')
                ->updateOrInsert(
                    ['session_key' => $sessionKey],
                    [
                        'session_data' => $sessionData,
                        'expire_date' => $expireDate,
                    ]
                );

            Log::info('DjangoSessionService: Session created', [
                'user_id' => $userId,
                'session_key' => $sessionKey,
            ]);

            return $sessionKey;

        } catch (\Exception $e) {
            Log::error('DjangoSessionService: Failed to create session', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Calcola _auth_user_hash come fa Django.
     * Usa salted_hmac con SHA256 per derivare la chiave.
     */
    private function calculateAuthUserHash(string $passwordHash): string
    {
        $keySalt = 'django.contrib.auth.models.AbstractBaseUser.get_session_auth_hash';

        // Django's salted_hmac with algorithm='sha256':
        // key = hashlib.sha256((key_salt + secret).encode()).digest()
        // hmac.new(key, value.encode(), digestmod=hashlib.sha256).hexdigest()
        $key = hash('sha256', $keySalt.$this->secretKey, true);

        return hash_hmac('sha256', $passwordHash, $key);
    }

    /**
     * Codifica i dati della sessione nel formato Django.
     * Formato: .{base64_zlib_compressed_json}:{timestamp_base62}:{signature}
     */
    private function encodeSessionData(array $sessionDict): string
    {
        // 1. JSON encode
        $json = json_encode($sessionDict, JSON_UNESCAPED_SLASHES);

        // 2. Compress with zlib
        $compressed = gzcompress($json);

        // 3. Base64 URL-safe encode (senza padding)
        $base64 = rtrim(strtr(base64_encode($compressed), '+/', '-_'), '=');

        // 4. Add dot prefix (indica compressione)
        $payload = '.'.$base64;

        // 5. Timestamp in base62
        $timestamp = $this->base62Encode(time());

        // 6. Sign
        $value = $payload.':'.$timestamp;
        $signature = $this->signSessionData($value);

        return $value.':'.$signature;
    }

    /**
     * Firma i dati della sessione come fa Django.
     *
     * Django uses salt = SessionStore.key_salt = 'django.contrib.sessions.SessionStore'
     * Then appends 'signer' to create the HMAC key salt.
     */
    private function signSessionData(string $value): string
    {
        // This MUST match Django's SessionStore.key_salt
        $salt = 'django.contrib.sessions.SessionStore';

        // Django's Signer uses: salted_hmac(salt + 'signer', value, secret, algorithm='sha256')
        $keySalt = $salt.'signer';
        $key = hash('sha256', $keySalt.$this->secretKey, true);
        $signature = hash_hmac('sha256', $value, $key, true);

        // Base64 URL-safe encode (senza padding)
        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * Codifica un numero in base62 (come fa Django).
     * Django usa: 0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz
     */
    private function base62Encode(int $num): string
    {
        // Django's BASE62_ALPHABET (from django.utils.baseconv)
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $base = strlen($alphabet);

        if ($num === 0) {
            return $alphabet[0];
        }

        $result = '';
        while ($num > 0) {
            $result = $alphabet[$num % $base].$result;
            $num = intdiv($num, $base);
        }

        return $result;
    }

    /**
     * Genera un session key casuale (32 caratteri alfanumerici lowercase).
     */
    private function generateSessionKey(): string
    {
        return Str::lower(Str::random(32));
    }

    /**
     * Elimina una sessione Django dal database.
     *
     * @param  string  $sessionKey  Il session_key da eliminare
     * @return bool True se la sessione è stata eliminata
     */
    public function deleteSession(string $sessionKey): bool
    {
        try {
            $deleted = DB::connection('escriptorium')
                ->table('django_session')
                ->where('session_key', $sessionKey)
                ->delete();

            if ($deleted) {
                Log::debug('DjangoSessionService: Session deleted', [
                    'session_key' => substr($sessionKey, 0, 8).'...',
                ]);
            }

            return $deleted > 0;
        } catch (\Exception $e) {
            Log::warning('DjangoSessionService: Failed to delete session', [
                'session_key' => substr($sessionKey, 0, 8).'...',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
