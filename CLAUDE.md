# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Cosa fa questo progetto

**Laravel Proxy** semplifica l'uso di [eScriptorium](https://gitlab.com/scripta/escriptorium) (Django + Vue + PostgreSQL + Redis + Celery) per la trascrizione OCR/HTR di documenti.

**Problema risolto:** Con le API native di eScriptorium servono molte chiamate per ottenere una trascrizione. Con il proxy Laravel bastano **2 chiamate**:
1. `POST /api/v1/process/manifest` o `POST /api/v1/process/images` → avvia il processo
2. `GET /api/v1/process/{id}` → polling per ottenere il risultato

## Due modalità di autenticazione

| Header `X-API-Key` | Tipo | Utente usato | Progetto |
|--------------------|------|--------------|----------|
| `esk_*` (generata da Laravel) | Service Mode | Account di servizio | **Temporaneo** (eliminato a fine processo) |
| Token eScriptorium | Direct Mode | Tuo account | **Persistente** sul tuo account |

Laravel verifica se l'API key è di eScriptorium **interrogando direttamente PostgreSQL** (tabella `authtoken_token`).

## Comandi principali

```bash
# Setup iniziale (auto-detect piattaforma ARM64/AMD64)
make setup

# Development
make dev                # Avvia tutti i container
make dev-stop           # Ferma
make dev-logs           # Log
make dev-shell          # Shell nel container Laravel
make dev-artisan cmd="migrate"

# Database
make db-migrate
make db-fresh           # Reset completo con seed

# Test
docker compose -f docker-compose.development.yml exec proxy-php php artisan test
docker compose -f docker-compose.development.yml exec proxy-php php artisan test --filter=NomeTest

# Generare API key
docker compose -f docker-compose.development.yml exec proxy-php php artisan apikey:generate "Nome"
```

## Architettura

```
┌─────────────────────────────────────────────────────────┐
│                    NGINX (:8080)                        │
└─────────────────────────┬───────────────────────────────┘
                          │
         ┌────────────────┴────────────────┐
         ▼                                 ▼
┌─────────────────────┐          ┌─────────────────────────┐
│   Laravel Proxy     │          │     eScriptorium        │
│   ───────────────   │          │     ────────────        │
│   • PHP-FPM         │  HTTP    │     • Django (uWSGI)    │
│   • Queue Worker    │ ──────►  │     • Vue.js            │
│   • MariaDB         │          │     • PostgreSQL ◄──────┼── Laravel legge
│   • Redis (shared)  │          │     • Celery Workers    │   direttamente
└─────────────────────┘          │     • Redis (shared)    │
                                 └─────────────────────────┘
```

## Database

**Due connessioni configurate in `proxy/config/database.php`:**

- `mariadb` (default): dati Laravel (API keys, transcriptions, logs)
- `escriptorium`: accesso **read-only** a PostgreSQL di eScriptorium

```php
// Esempio: verificare se un token è di eScriptorium
DB::connection('escriptorium')
    ->table('authtoken_token')
    ->where('key', $token)
    ->first();
```

## Flusso di una trascrizione

```
CLIENT                          LARAVEL                         ESCRIPTORIUM
   │                               │                                  │
   │  POST /process/manifest       │                                  │
   │  {manifest_url, model_id}     │                                  │
   ├──────────────────────────────►│                                  │
   │                               │  1. Crea progetto                │
   │                               ├─────────────────────────────────►│
   │                               │  2. Crea documento               │
   │                               ├─────────────────────────────────►│
   │                               │  3. Import IIIF manifest         │
   │                               ├─────────────────────────────────►│
   │  202 {id: "uuid"}             │                                  │
   │◄──────────────────────────────┤                                  │
   │                               │                                  │
   │         ┌─────────────────────┤  JOBS ASINCRONI (Queue)          │
   │         │                     │  ════════════════════            │
   │         │  CheckImportJob ────┼─► Poll /api/tasks/               │
   │         │         │           │                                  │
   │         │         ▼           │                                  │
   │         │  SegmentJob ────────┼─► POST /segment/                 │
   │         │         │           │                                  │
   │         │         ▼           │                                  │
   │         │  CheckSegmentJob ───┼─► Poll /api/tasks/               │
   │         │         │           │                                  │
   │         │         ▼           │                                  │
   │         │  TranscribeJob ─────┼─► POST /transcribe/              │
   │         │         │           │                                  │
   │         │         ▼           │                                  │
   │         │  CheckTranscribeJob─┼─► Poll /api/tasks/               │
   │         │         │           │                                  │
   │         │         ▼           │                                  │
   │         │  DownloadJob ───────┼─► WebSocket + Export TEI         │
   │         │         │           │                                  │
   │         │         ▼           │                                  │
   │         │  ProcessTeiJob      │  (merge XML, extract text)       │
   │         │         │           │                                  │
   │         │         ▼           │                                  │
   │         │  [Se Service Mode]──┼─► DELETE /projects/{id}/         │
   │         └─────────────────────┤                                  │
   │                               │                                  │
   │  GET /process/{id}            │                                  │
   ├──────────────────────────────►│                                  │
   │  200 {status, text}           │                                  │
   │◄──────────────────────────────┤                                  │
```

## Struttura file principali

```
proxy/
├── app/
│   ├── Http/
│   │   ├── Controllers/API/v1/eScriptoriumController.php  # Endpoint
│   │   ├── Middleware/ValidateApiKey.php                  # Auth + rate limit
│   │   └── Requests/eScriptorium/                         # Validazione input
│   │
│   ├── Jobs/                      # Pipeline asincrona
│   │   ├── eScriptoriumImportDocumentJob.php      # Step 1: Import
│   │   ├── eScriptoriumUploadImagesJob.php        # Step 1 alt: Upload
│   │   ├── eScriptoriumCheckImportDocumentJob.php # Polling import
│   │   ├── eScriptoriumFetchPartsJob.php          # Recupera pagine
│   │   ├── eScriptoriumSegmentDocumentJob.php     # Step 2: Segmentazione
│   │   ├── eScriptoriumCheckSegmentDocumentJob.php
│   │   ├── eScriptoriumCreateTranscriptionJob.php # Crea layer
│   │   ├── eScriptoriumTranscribeTranscriptionJob.php # Step 3: OCR
│   │   ├── eScriptoriumCheckTranscribeTranscriptionJob.php
│   │   ├── eScriptoriumDownloadJob.php            # Step 4: Export TEI
│   │   └── eScriptoriumProcessTeiJob.php          # Step 5: Merge XML
│   │
│   ├── Services/
│   │   ├── eScriptoriumService.php           # Client HTTP per eScriptorium
│   │   ├── eScriptoriumServiceDataManager.php # Gestione stato job
│   │   └── DjangoSessionService.php          # Crea sessioni Django per WebSocket
│   │
│   ├── Models/
│   │   ├── Transcription.php    # Traccia stato processo
│   │   └── ApiKey.php           # Gestione API keys
│   │
│   └── Contexts/
│       └── ApiContext.php       # Service mode vs Direct mode
│
├── config/
│   ├── database.php             # Connessioni MariaDB + PostgreSQL
│   └── escriptorium.php         # URL, polling intervals, timeouts
│
└── routes/
    └── api.php                  # Route definitions

escriptorium/                    # Submodule Django (NON MODIFICARE)
docker/                          # Dockerfile, nginx configs
scripts/setup.sh                 # Setup automatico
```

## API Endpoints

Documentazione Swagger disponibile su `http://localhost:8080/docs` (Scalar UI)

| Method | Endpoint | Descrizione |
|--------|----------|-------------|
| GET | `/api/v1/up` | Health check |
| GET | `/api/v1/models` | Lista modelli OCR disponibili |
| GET | `/api/v1/scripts` | Lista sistemi di scrittura |
| POST | `/api/v1/process/manifest` | Avvia trascrizione da IIIF manifest |
| POST | `/api/v1/process/images` | Avvia trascrizione da upload immagini |
| GET | `/api/v1/process/{id}` | Stato e risultato trascrizione |

## Stati della trascrizione

```
Pending → Importing → Segmenting → Transcribing → Downloading → Processing → Completed
                                                                           ↘ Failed
```

Enum: `proxy/app/Enums/eScriptoriumStatusEnum.php`

## Configurazione polling

In `proxy/config/escriptorium.php`:

```php
'polling' => [
    'interval' => 30,        // secondi tra ogni check
    'max_attempts' => [
        'import' => 120,     // ~60 min max
        'segment' => 240,    // ~120 min max
        'transcribe' => 360, // ~180 min max
    ],
],
'django' => [
    // DEVE corrispondere a SECRET_KEY in escriptorium/variables.env
    'secret_key' => env('ESCRIPTORIUM_DJANGO_SECRET_KEY', 'changeme'),
],
```

## ApiContext - Gestione modalità auth

```php
// Service Mode (API key Laravel)
ApiContext::setServiceAuth();
ApiContext::isDirectMode(); // false
// → Usa credenziali servizio
// → Progetto eliminato a fine processo

// Direct Mode (token eScriptorium)
ApiContext::setDirectToken($token);
ApiContext::isDirectMode(); // true
// → Usa token utente
// → Progetto persistente
```

I job usano il trait `UsesEscriptoriumAuth` per ripristinare il contesto auth dalla Transcription.

---

# Dettagli Tecnici Approfonditi

## Flusso di Autenticazione (ValidateApiKey Middleware)

File: `proxy/app/Http/Middleware/ValidateApiKey.php`

```
┌─────────────────────────────────────────────────────────────────────────┐
│                      RICHIESTA HTTP con X-API-Key                       │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │ Query PostgreSQL eScriptorium │
                    │ SELECT * FROM authtoken_token │
                    │ WHERE key = $plainKey         │
                    └───────────────────────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
                    ▼                               ▼
          Token TROVATO                    Token NON TROVATO
                    │                               │
                    ▼                               ▼
    ┌───────────────────────────┐   ┌───────────────────────────┐
    │     DIRECT MODE           │   │     SERVICE MODE          │
    │                           │   │                           │
    │ ApiContext::setDirectToken│   │ Cerca in Laravel ApiKey   │
    │ getOrCreateVirtualApiKey  │   │ ApiContext::setServiceAuth│
    │                           │   │                           │
    │ → Usa token utente        │   │ → Usa credenziali servizio│
    │ → Progetto PERSISTENTE    │   │ → Progetto TEMPORANEO     │
    │ → document_id per riuso   │   │ → Eliminato a fine processo│
    └───────────────────────────┘   └───────────────────────────┘
                    │                               │
                    └───────────────┬───────────────┘
                                    ▼
                    ┌───────────────────────────────┐
                    │ Rate Limiting (per API key)   │
                    │ Request Logging (opzionale)   │
                    │ Bind api_key a Request        │
                    │ Merge is_escriptorium_api_key │
                    │ Merge escriptorium_token      │
                    └───────────────────────────────┘
```

**Virtual API Key**: In Direct Mode, Laravel crea una `ApiKey` virtuale per l'utente eScriptorium per riutilizzare l'infrastruttura di logging e rate limiting.

## Struttura service_data (JSON nella Transcription)

Questa struttura JSON viene salvata nel campo `service_data` della tabella `transcriptions` e traccia tutto lo stato del processo:

```json
{
  "escriptorium": {
    "request": {
      "source_type": "manifest|images",
      "manifest_url": "https://...",
      "script_name": "Latin",
      "recognition_model_id": 142,
      "segmentation_model_id": 45,
      "text_direction": "horizontal-lr",
      "pages": "1-10",
      "pages_array": [1, 2, 3, ...],
      "document_id": 456
    },
    "project": {
      "pk": 123,
      "slug": "my-project-abc123",
      "name": "My Project"
    },
    "document": {
      "pk": 456,
      "name": "My Document",
      "project": "my-project-abc123",
      "valid_block_types": [...]
    },
    "transcription_name": "HTR Output",
    "transcription": {
      "pk": 789,
      "name": "HTR Output"
    },
    "parts": [
      {"pk": 1001, "order": 1, "image": "...", "filename": "page1.jpg"},
      {"pk": 1002, "order": 2, "image": "...", "filename": "page2.jpg"}
    ],
    "image_paths": ["transcriptions/pending_uploads/abc.jpg"],
    "steps": {
      "import": {
        "started_at": "2024-01-01T10:00:00Z",
        "completed_at": "2024-01-01T10:05:00Z",
        "polling_attempt": 5,
        "last_tasks_response": {...}
      },
      "segment": {...},
      "transcribe": {...},
      "download": {
        "download_url": "/media/exports/...",
        "local_path": "escriptorium/exports/uuid/file.zip"
      }
    }
  }
}
```

## Pipeline dei Job con API Calls

### 1. ImportDocumentJob / UploadImagesJob

**Manifest Mode**:
```
POST /api/documents/{pk}/import/
{
  "mode": "iiif",
  "iiif_uri": "https://example.com/manifest.json",
  "name": "transcription_name"
}
```

**Images Mode**:
```
POST /api/documents/{pk}/parts/
Content-Type: multipart/form-data
image: <file>
```

### 2. CheckImportDocumentJob (Polling)

```
GET /api/tasks/?document={pk}
```

**Workflow States di eScriptorium**:
| State | Significato | Azione |
|-------|-------------|--------|
| 0 | Queued | Continua polling |
| 1 | Running | Continua polling |
| 2 | Crashed | FAIL - Interrompi |
| 3 | Finished | SUCCESS - Prossimo step |
| 4 | Canceled | FAIL - Interrompi |

**Task Methods monitorati**:
- Manifest: `imports.tasks.document_import`
- Images: `*convert` (qualsiasi metodo che finisce con "convert")

### 3. FetchPartsJob

```
GET /api/documents/{pk}/parts/?ordering=order&paginate_by=5000
```

Salva l'array `parts` con tutti i `pk` delle pagine in `service_data`.

### 4. SegmentDocumentJob

```
POST /api/documents/{pk}/segment/
{
  "steps": "both",          // "both" | "lines" | "masks" | "regions"
  "override": true,
  "text_direction": "horizontal-lr",
  "model": 45,              // opzionale, segmentation model pk
  "parts": [1001, 1002]     // opzionale, pk delle parti
}
```

### 5. CheckSegmentDocumentJob (Polling)

```
GET /api/tasks/?document={pk}
```

Monitora task con method contenente `segment`.

### 6. CreateTranscriptionJob

```
POST /api/documents/{pk}/transcriptions/
{
  "name": "HTR Output"
}
```

Operazione **sincrona** - ritorna subito il `pk` della transcription.

### 7. TranscribeTranscriptionJob

```
POST /api/documents/{pk}/transcribe/
{
  "model": 142,             // recognition model pk
  "transcription": 789,     // transcription pk (dove salvare output)
  "parts": [1001, 1002]     // opzionale
}
```

### 8. CheckTranscribeTranscriptionJob (Polling)

```
GET /api/tasks/?document={pk}
```

Monitora task con method contenente `transcribe`.

### 9. DownloadJob (WebSocket + Export)

**Step 1: Connessione WebSocket**
```
ws://escriptorium-nginx/ws/notif/
Headers:
  Cookie: sessionid={session_id}
  Origin: http://escriptorium-web:8000
```

**Step 2: Join Room**
```json
{"type": "join-room", "object_cls": "document", "object_pk": 456}
```

**Step 3: Trigger Export via API**
```
POST /api/documents/{pk}/export/
{
  "file_format": "teixml",
  "include_characters": false,
  "include_images": false,
  "transcription": 789,
  "parts": [1001, 1002],
  "region_types": ["Paragraph", "Undefined", "Orphan"]
}
```

**Step 4: Attesa messaggio WebSocket**
```json
{
  "type": "message",
  "text": "Export done!",
  "links": [{"src": "/media/exports/doc_456_export.zip"}]
}
```

**Step 5: Download file**
```
GET {base_url}/media/exports/doc_456_export.zip
Authorization: Token {token}
```

**Step 6: Cleanup (solo Service Mode)**
```
DELETE /api/projects/{pk}/
```

### 10. ProcessTeiJob

- Estrae ZIP
- Merge di tutti i file TEI XML in un unico documento
- Estrae plain text dal TEI
- Aggiorna `Transcription.text` con il risultato
- Imposta status a `COMPLETED`

## Gestione Polling e Job Obsoleti

Il sistema usa `eScriptoriumServiceDataManager` per gestire lo stato dei job e prevenire race conditions:

```php
// Ogni job di polling porta con sé il suo "pollingAttempt"
public function __construct(Transcription $transcription, int $pollingAttempt = 1)

// Prima di processare, verifica se questo tentativo è ancora valido
if (!$this->dataManager->isPollingAttemptValid(STEP_IMPORT, $this->pollingAttempt)) {
    return; // Job obsoleto - esce silenziosamente
}

// Aggiorna il contatore nel service_data
$this->dataManager->updatePolling(STEP_IMPORT, $this->pollingAttempt, $tasksResponse);
```

Questo previene situazioni in cui job vecchi in coda processano dati obsoleti.

## WebSocket: Dettagli Tecnici

Il WebSocket è necessario perché l'export di eScriptorium è **asincrono**: l'API `/export/` risponde subito con `200 OK`, ma il file viene generato in background da Celery.

**Notifiche eScriptorium**:
- `user.notify('Export done!', links=[...])` → invia alla **room dell'utente** (`notif-{user_pk}`) CON link download
- `send_event('document', pk, 'export:done', {})` → invia alla **room del documento** SENZA link

Per ricevere il link di download, il WebSocket DEVE essere autenticato come l'utente che ha lanciato l'export.

**Autenticazione WebSocket - Service Mode**:
1. GET `/login/` → ottieni `csrftoken` cookie
2. POST `/login/` con username/password service account → ottieni `sessionid` cookie
3. Connetti WebSocket con `Cookie: sessionid=...`

**Autenticazione WebSocket - Direct Mode**:
In Direct Mode abbiamo solo il token API dell'utente, non le sue credenziali. Per autenticare il WebSocket come quell'utente, creiamo una sessione Django direttamente nel database PostgreSQL:

1. `DjangoSessionService::createSessionForToken($apiToken)`:
   - Query `authtoken_token` + `users_user` per ottenere `user_id` e `password_hash`
   - Calcola `_auth_user_hash` (come fa Django)
   - Codifica session data nel formato Django
   - Inserisce in `django_session`
2. Connetti WebSocket con `Cookie: sessionid={session_key_creato}`

**Messaggi monitorati**:
```json
// Successo (ricevuto nella room dell'utente)
{"type": "message", "text": "Export done!", "links": [{"src": "/media/..."}]}

// Errore
{"type": "event", "name": "export:error", "data": {"reason": "..."}}
```

## DjangoSessionService: Creazione Sessioni Django da PHP

File: `proxy/app/Services/DjangoSessionService.php`

Questo servizio permette di creare sessioni Django valide direttamente da PHP, necessario per autenticare il WebSocket in Direct Mode.

**Formato sessione Django**:
```
{payload}:{timestamp_base62}:{signature}
```

Dove `payload` può essere:
- `{base64_json}` - dati non compressi
- `.{base64_zlib_json}` - dati compressi (il punto indica compressione)

**Session dict (dati nella sessione)**:
```json
{
  "_auth_user_id": "2",
  "_auth_user_backend": "django.contrib.auth.backends.ModelBackend",
  "_auth_user_hash": "6d063b35f3ee8e0be46c4403535041e6fd71563381825283b7c81265369c2d3b"
}
```

**Calcolo `_auth_user_hash`**:
```php
// Django: AbstractBaseUser.get_session_auth_hash()
$keySalt = 'django.contrib.auth.models.AbstractBaseUser.get_session_auth_hash';
$key = hash('sha256', $keySalt . $secretKey, true);  // DEVE essere SHA256
$authUserHash = hash_hmac('sha256', $passwordHash, $key);
```

**Firma della sessione**:
```php
// Salt DEVE essere 'django.contrib.sessions.SessionStore' (NON backends.db!)
$salt = 'django.contrib.sessions.SessionStore';
$keySalt = $salt . 'signer';
$key = hash('sha256', $keySalt . $secretKey, true);
$signature = hash_hmac('sha256', $valueToSign, $key, true);
$base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
```

**Base62 (per timestamp)**:
```php
// Django usa questo alfabeto specifico
$alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
```

**Configurazione**:
```php
// config/escriptorium.php
'django' => [
    'secret_key' => env('ESCRIPTORIUM_DJANGO_SECRET_KEY', 'changeme'),
],
```

**IMPORTANTE**: `ESCRIPTORIUM_DJANGO_SECRET_KEY` DEVE corrispondere a `SECRET_KEY` in `escriptorium/variables.env`.

## eScriptoriumService: Metodi Principali

| Metodo | Descrizione | Sincrono |
|--------|-------------|----------|
| `isUp()` | Health check | ✅ |
| `getCurrentToken()` | Token corrente (service o direct) | ✅ |
| `getSessionCookie()` | Session Django per WebSocket | ✅ |
| `createWebSocketClient(timeout)` | Client WebSocket configurato | ✅ |
| `models()` | Lista modelli OCR | ✅ |
| `scripts()` | Lista sistemi scrittura | ✅ |
| `createProject(name)` | Crea progetto | ✅ |
| `createDocument(name, projectSlug, script)` | Crea documento | ✅ |
| `importDocument(docId, mode, iiifUri, name)` | Import da IIIF | ❌ |
| `uploadPart(docId, content, filename)` | Upload immagine | ✅ |
| `getDocumentParts(docId)` | Lista parti/pagine | ✅ |
| `segmentDocument(docId, parts, modelId, ...)` | Avvia segmentazione | ❌ |
| `createTranscription(docId, name)` | Crea layer trascrizione | ✅ |
| `transcribeTranscription(docId, parts, trId, modelId)` | Avvia OCR | ❌ |
| `tasks(docId)` | Polling stato task | ✅ |
| `exportDocument(docId, trId, parts, format, regions)` | Avvia export | ❌ |
| `deleteProject(projectId)` | Elimina progetto | ✅ |
| `mergeTeiContents(contents[])` | Merge TEI XML | ✅ |
| `extractPlainText(teiXml)` | Estrai testo | ✅ |
| `getProjects(name)` | Cerca progetti | ✅ |
| `getDocuments(projectId, name)` | Cerca documenti | ✅ |
| `getCurrentUser()` | Info utente corrente (Direct Mode) | ✅ |

---

## Porte development

| Porta | Servizio |
|-------|----------|
| 8080 | Laravel Proxy (main) |
| 8081 | phpMyAdmin |
| 8082 | eScriptorium diretto |
| 5050 | pgAdmin |
| 5173 | Vite dev server |
| 5555 | Flower (Celery monitor) |

## Note importanti

- `escriptorium/` è un **git submodule** - non modificare i file al suo interno
- `escriptorium/variables.env` viene generato da `scripts/setup.sh`
- La piattaforma (ARM64/AMD64) è auto-rilevata e configurata nel docker-compose
- I job di polling hanno logica per gestire job obsoleti/duplicati
- Il WebSocket è usato solo per ricevere notifica di export completato
- In Direct Mode, i progetti NON vengono eliminati (l'utente li vede nel suo account eScriptorium)
- `getOrCreateVirtualApiKey` crea una ApiKey Laravel "virtuale" per tracciare rate limit e log anche per token eScriptorium diretti
- **CRITICO**: `ESCRIPTORIUM_DJANGO_SECRET_KEY` deve corrispondere a `SECRET_KEY` di Django per la creazione di sessioni WebSocket in Direct Mode
- In Direct Mode, il WebSocket viene autenticato creando una sessione Django direttamente in PostgreSQL (via `DjangoSessionService`)
