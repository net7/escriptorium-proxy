<?php

namespace App\Contexts;

/**
 * Context per gestire l'autenticazione verso eScriptorium.
 *
 * Supporta due modalità:
 * - Direct Mode: usa un token API passato direttamente dal client
 * - Service Mode: usa le credenziali dell'utente di servizio configurato
 *
 * Questo context è un singleton statico che mantiene lo stato durante
 * l'esecuzione di una singola request HTTP o di un singolo job.
 */
class ApiContext
{
    private static ?string $directToken = null;

    private static bool $isDirectMode = false;

    /**
     * Imposta la modalità diretta con il token fornito.
     * In questa modalità, le chiamate API useranno il token dell'utente.
     */
    public static function setDirectToken(string $token): void
    {
        self::$directToken = $token;
        self::$isDirectMode = true;
    }

    /**
     * Imposta la modalità servizio.
     * In questa modalità, le chiamate API useranno le credenziali configurate.
     */
    public static function setServiceAuth(): void
    {
        self::$directToken = null;
        self::$isDirectMode = false;
    }

    /**
     * Verifica se siamo in modalità diretta.
     * In questa modalità NON si devono eliminare risorse create su eScriptorium.
     */
    public static function isDirectMode(): bool
    {
        return self::$isDirectMode;
    }

    /**
     * Ottiene il token diretto (null se in modalità servizio).
     */
    public static function getDirectToken(): ?string
    {
        return self::$directToken;
    }

    /**
     * Resetta il context allo stato iniziale.
     * Da chiamare alla fine di ogni request/job per evitare memory leaks.
     */
    public static function reset(): void
    {
        self::$directToken = null;
        self::$isDirectMode = false;
    }
}
