# API Key Authentication

Sistema di autenticazione API per il proxy Laravel eScriptorium.

## Quick Start

```bash
# Eseguire le migrations
php artisan migrate

# Generare una nuova API key
php artisan apikey:generate "Nome Applicazione"
```

## Autenticazione

Includere l'header `X-API-Key` in tutte le richieste:

```bash
curl -X GET http://localhost/api/v1/models \
  -H "X-API-Key: esk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

## Gestione Chiavi

### Generare una nuova chiave

```bash
# Interattivo (con prompts)
php artisan apikey:generate

# Non-interattivo
php artisan apikey:generate "App Name" \
  --permissions=models,scripts,process,status \
  --rate-limit=100 \
  --expires=2026-12-31
```

**Opzioni:**
| Flag | Descrizione | Default |
|------|-------------|---------|
| `--permissions` | Permessi separati da virgola | Tutti |
| `--rate-limit` | Richieste al minuto | 60 |
| `--expires` | Data scadenza (Y-m-d) | Mai |

### Elencare le chiavi

```bash
# Solo chiavi attive
php artisan apikey:list

# Tutte le chiavi (incluse revocate/scadute)
php artisan apikey:list --all
```

### Revocare una chiave

```bash
# Con conferma
php artisan apikey:revoke <key-id>

# Senza conferma
php artisan apikey:revoke <key-id> --force
```

## Permessi

| Permesso | Endpoint | Descrizione |
|----------|----------|-------------|
| `models` | `/v1/models/*` | Accesso ai modelli OCR |
| `scripts` | `/v1/scripts/*` | Accesso agli scripts |
| `process` | `/v1/process/*` | Invio job di processing |
| `status` | `/v1/status/*` | Controllo stato trascrizioni |

> **Nota:** Un array permessi vuoto garantisce accesso a tutti gli endpoint.

## Rate Limiting

Ogni chiave ha un limite di richieste al minuto configurabile. Gli header di risposta includono:

| Header | Descrizione |
|--------|-------------|
| `X-RateLimit-Limit` | Limite massimo richieste/minuto |
| `X-RateLimit-Remaining` | Richieste rimanenti |
| `Retry-After` | Secondi di attesa (se rate limited) |

## Risposte Errore

### 401 Unauthorized
```json
{
  "error": "Unauthorized",
  "message": "API key is required"
}
```

### 403 Forbidden
```json
{
  "error": "Forbidden",
  "message": "Insufficient permissions for: models"
}
```

### 429 Too Many Requests
```json
{
  "error": "Too Many Requests",
  "message": "Rate limit exceeded",
  "retry_after": 45
}
```

## Logging

Ogni richiesta viene loggata nella tabella `api_key_logs` con:
- Endpoint e metodo HTTP
- Indirizzo IP e User Agent
- Status code risposta
- Tempo di risposta (ms)

## Schema Database

### `api_keys`
| Colonna | Tipo | Descrizione |
|---------|------|-------------|
| `id` | UUID | Primary key |
| `name` | string | Nome identificativo |
| `key_hash` | string | Hash SHA256 della chiave |
| `key_prefix` | string | Primi 12 caratteri (per identificazione) |
| `permissions` | JSON | Array permessi |
| `rate_limit` | int | Limite richieste/minuto |
| `last_used_at` | timestamp | Ultimo utilizzo |
| `expires_at` | timestamp | Data scadenza |
| `is_active` | boolean | Stato attivo |

### `api_key_logs`
| Colonna | Tipo | Descrizione |
|---------|------|-------------|
| `id` | bigint | Primary key |
| `api_key_id` | UUID | Foreign key |
| `endpoint` | string | Path richiesta |
| `method` | string | GET, POST, etc. |
| `ip_address` | string | IP client |
| `user_agent` | string | Browser/client |
| `response_status` | int | HTTP status code |
| `response_time_ms` | int | Tempo risposta |
