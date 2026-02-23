<?php

namespace App\Jobs\Concerns;

use App\Contexts\ApiContext;
use App\Models\Transcription;

/**
 * Trait per configurare l'autenticazione eScriptorium nei job.
 *
 * I job vengono eseguiti in processi separati dove l'ApiContext non è
 * automaticamente disponibile. Questo trait legge il token dalla
 * Transcription e configura il context appropriatamente.
 *
 * Uso:
 * ```php
 * class MyJob implements ShouldQueue
 * {
 *     use UsesEscriptoriumAuth;
 *
 *     public function handle(): void
 *     {
 *         $this->setupEscriptoriumAuth($this->transcription);
 *         // ... le chiamate a eScriptorium useranno il token corretto
 *     }
 *
 *     public function failed(?\Throwable $exception): void
 *     {
 *         $this->cleanupEscriptoriumAuth();
 *         // ... gestione errore
 *     }
 * }
 * ```
 */
trait UsesEscriptoriumAuth
{
    /**
     * Configura il context di autenticazione basandosi sulla Transcription.
     *
     * Se la transcription ha un token Direct Mode salvato, lo usa.
     * Altrimenti usa l'autenticazione del servizio.
     */
    protected function setupEscriptoriumAuth(Transcription $transcription): void
    {
        if ($transcription->direct_mode_token) {
            ApiContext::setDirectToken($transcription->direct_mode_token);
        } else {
            ApiContext::setServiceAuth();
        }
    }

    /**
     * Verifica se siamo in modalità diretta.
     * In questa modalità NON si devono eliminare risorse.
     */
    protected function isDirectMode(): bool
    {
        return ApiContext::isDirectMode();
    }

    /**
     * Resetta il context di autenticazione.
     * Da chiamare nel metodo failed() per pulizia.
     */
    protected function cleanupEscriptoriumAuth(): void
    {
        ApiContext::reset();
    }
}
