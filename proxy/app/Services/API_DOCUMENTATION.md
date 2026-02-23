# eScriptorium REST API Documentation

## Base URL
Tutte le API sono disponibili sotto il percorso `/api/`

---

## 📖 Indice

1. [Autenticazione](#-autenticazione)
2. [Operazioni Sincrone vs Asincrone](#-operazioni-sincrone-vs-asincrone)
3. [Workflow Completo di Esempio](#-workflow-completo-di-esempio)
4. [Task Reports e Stati](#-task-reports-e-stati)
5. [Risorse API](#risorse-api)
6. [Custom API Endpoints](#-custom-api-endpoints)
7. [Codici HTTP Comuni](#-codici-http-comuni)

---

## 🔐 Autenticazione

### Token Authentication
**Endpoint:** `POST /api/token-auth/`

**Descrizione:** Ottieni o rigenera un token di autenticazione.

**Parametri:**
| Campo | Tipo | Obbligatorio | Descrizione |
|-------|------|--------------|-------------|
| `username` | string | ✅ | Nome utente |
| `password` | string | ✅ | Password |
| `regenerate` | boolean | ❌ | Se `true`, rigenera un nuovo token |

**Response Successo (HTTP 200):**
```json
{
  "token": "your-auth-token"
}
```

**Response Errore - Credenziali non valide (HTTP 400):**
```json
{
  "non_field_errors": ["Unable to log in with provided credentials."]
}
```

**Header per richieste autenticate:**
```
Authorization: Token <your-token>
```

---

## ⚡ Operazioni Sincrone vs Asincrone

| Operazione | Tipo | Polling Richiesto |
|------------|------|-------------------|
| Crea Progetto | **Sincrono** | ❌ No |
| Crea Documento | **Sincrono** | ❌ No |
| Crea Trascrizione | **Sincrono** | ❌ No |
| Lista/Dettaglio risorse | **Sincrono** | ❌ No |
| Upload Immagine | **Sincrono** (avvia conversione async) | ⚠️ Opzionale |
| Import (IIIF/PDF/METS/XML) | **Asincrono** | ✅ Sì |
| Segmentazione | **Asincrono** | ✅ Sì |
| Trascrizione OCR | **Asincrono** | ✅ Sì |
| Training modelli | **Asincrono** | ✅ Sì |
| Allineamento | **Asincrono** | ✅ Sì |
| Export | **Asincrono** | ✅ Sì |

**Nota:** Per le operazioni asincrone, la risposta `200 OK` indica solo che il task è stato accodato. Usare `/api/tasks/` per verificare il completamento.

---

## 🔄 Workflow Completo di Esempio

Flusso tipico: Progetto → Documento → Import IIIF → Segmentazione → Trascrizione OCR

### Passo 1: Creare un Progetto

**Request:** `POST /api/projects/`
```json
{
  "name": "Il mio progetto"
}
```

**Response Successo (HTTP 201):**
```json
{
  "pk": 1,
  "name": "Il mio progetto",
  "slug": "il-mio-progetto",
  "owner": "username",
  "documents_count": 0,
  "tags": [],
  "shared_with_users": [],
  "shared_with_groups": [],
  "created_at": "2024-01-15T10:30:00.000000Z",
  "updated_at": "2024-01-15T10:30:00.000000Z"
}
```

**⚠️ Salvare:** `slug` per il passo successivo.

---

### Passo 2: Creare un Documento

**Request:** `POST /api/documents/`
```json
{
  "name": "Il mio documento",
  "project": "il-mio-progetto"
}
```

**Campi Obbligatori:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `name` | string | Nome del documento |
| `project` | string | **Slug** del progetto (non il PK!) |

**Campi Opzionali:**
| Campo | Tipo | Default | Descrizione |
|-------|------|---------|-------------|
| `main_script` | string | `null` | Nome script (es. "Latin", "Arabic") |
| `read_direction` | string | `"ltr"` | `"ltr"` o `"rtl"` |
| `line_offset` | integer | `0` | `0`=baseline, `1`=topline, `2`=centered |

**Response Successo (HTTP 201):**
```json
{
  "pk": 42,
  "name": "Il mio documento",
  "project": "il-mio-progetto",
  "project_name": "Il mio progetto",
  "project_id": 1,
  "main_script": null,
  "read_direction": "ltr",
  "line_offset": 0,
  "transcriptions": [],
  "valid_block_types": [],
  "valid_line_types": [],
  "valid_part_types": [],
  "parts_count": 0,
  "tags": [],
  "created_at": "2024-01-15T10:30:00.000000Z",
  "updated_at": "2024-01-15T10:30:00.000000Z",
  "shared_with_users": [],
  "shared_with_groups": []
}
```

**Response Errore - Nome mancante (HTTP 400):**
```json
{
  "name": ["This field is required."]
}
```

**Response Errore - Progetto non trovato (HTTP 400):**
```json
{
  "project": ["Object with slug=xxx does not exist."]
}
```

**⚠️ Salvare:** `pk` del documento per i passi successivi.

---

### Passo 3: Importare un Manifest IIIF

**Request:** `POST /api/documents/42/import/`
```json
{
  "mode": "iiif",
  "iiif_uri": "https://example.com/manifest.json",
  "name": "Trascrizione Principale"
}
```

**Response Successo (HTTP 201):**
```json
{
  "status": "ok"
}
```

**⚠️ Operazione ASINCRONA:** Verificare completamento con polling su `/api/tasks/?document=42`

**Response Errore - URI non valido (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "iiif_uri": ["FileImportError: Could not fetch manifest"]
  }
}
```

**Response Errore - Mode mancante (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "mode": ["This field is required."]
  }
}
```

---

### Passo 4: Segmentazione

**⏳ Attendere:** Completamento import (tutti i task con `workflow_state` != 0 e != 1)

**Request:** `POST /api/documents/42/segment/`
```json
{
  "steps": "both",
  "override": true
}
```

**Con modello specifico:**
```json
{
  "model": 15,
  "steps": "both",
  "override": true
}
```

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

**⚠️ Operazione ASINCRONA:** Verificare completamento con polling su `/api/tasks/?document=42`

**Response Errore - Documento già in elaborazione (HTTP 400):**
```json
{
  "status": "error",
  "error": "Already processing."
}
```

**Response Errore - Steps non valido (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "steps": ["\"invalid\" is not a valid choice."]
  }
}
```

**Response Errore - Modello non trovato (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "model": ["Invalid pk \"999\" - object does not exist."]
  }
}
```

**Response Errore - Quote CPU esaurite (HTTP 400):**
```json
{
  "status": "error",
  "error": ["You don't have any CPU minutes left."]
}
```

---

### Passo 5: Creare una Trascrizione

**⏳ Attendere:** Completamento segmentazione

**Request:** `POST /api/documents/42/transcriptions/`
```json
{
  "name": "OCR Output"
}
```

**Response Successo (HTTP 201):**
```json
{
  "pk": 5,
  "name": "OCR Output",
  "archived": false,
  "avg_confidence": null,
  "created_at": "2024-01-15T10:30:00.000000Z",
  "comments": ""
}
```

**✅ Operazione SINCRONA:** Il `pk` è immediatamente disponibile.

**Nota:** Se una trascrizione con lo stesso nome esiste già, restituisce quella esistente (stesso `pk`).

**Response Errore - Nome mancante (HTTP 400):**
```json
{
  "name": ["This field is required."]
}
```

**Response Errore - Nome vuoto (HTTP 400):**
```json
{
  "name": ["This field may not be blank."]
}
```

**⚠️ Salvare:** `pk` della trascrizione per il passo successivo.

---

### Passo 6: Trascrizione OCR

**Request:** `POST /api/documents/42/transcribe/`
```json
{
  "model": 25,
  "transcription": 5
}
```

**Campi Obbligatori:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `model` | integer | PK del modello OCR (tipo `recognize`) |
| `transcription` | integer | PK della trascrizione di destinazione |

**Campo Opzionale:**
| Campo | Tipo | Default | Descrizione |
|-------|------|---------|-------------|
| `parts` | array[integer] | Tutte | Lista PK delle parti da processare |

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

**⚠️ Operazione ASINCRONA:** Verificare completamento con polling su `/api/tasks/?document=42`

**Response Errore - Model mancante (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "model": ["This field is required."]
  }
}
```

**Response Errore - Transcription mancante (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "transcription": ["This field is required."]
  }
}
```

**Response Errore - Modello non è di tipo recognize (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "model": ["Invalid pk \"15\" - object does not exist."]
  }
}
```

**Response Errore - Trascrizione non appartiene al documento (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "transcription": ["Invalid pk \"99\" - object does not exist."]
  }
}
```

**Response Errore - Documento già in elaborazione (HTTP 400):**
```json
{
  "status": "error",
  "error": "Already processing."
}
```

**Response Errore - Quote CPU esaurite (HTTP 400):**
```json
{
  "status": "error",
  "error": ["You don't have any CPU minutes left."]
}
```

---

## 📊 Task Reports e Stati

### Endpoint: `GET /api/tasks/`

**Filtri:**
- `?document={pk}` - Filtra per documento

**Request Esempio:**
```
GET /api/tasks/?document=42
```

**Response:**
```json
{
  "count": 50,
  "next": "https://example.com/api/tasks/?document=42&page=2",
  "previous": null,
  "results": [
    {
      "pk": 101,
      "document": 42,
      "document_part": "page_001.jpg",
      "workflow_state": 3,
      "label": "Segmentation",
      "messages": "Found 25 lines, 3 regions",
      "queued_at": "2024-01-15T10:30:00.000000Z",
      "started_at": "2024-01-15T10:30:05.123456Z",
      "done_at": "2024-01-15T10:31:00.789012Z",
      "method": "core.tasks.segment",
      "user": 1
    }
  ]
}
```

### Stati `workflow_state`

| Valore | Nome | Significato |
|--------|------|-------------|
| `0` | **Queued** | ⏳ In coda, in attesa di esecuzione |
| `1` | **Running** | 🔄 In esecuzione |
| `2` | **Crashed** | ❌ Fallito con errore |
| `3` | **Finished** | ✅ Completato con successo |
| `4` | **Canceled** | 🚫 Annullato dall'utente |

### Valori `method`

| Method | Operazione |
|--------|------------|
| `core.tasks.segment` | Segmentazione |
| `core.tasks.transcribe` | Trascrizione OCR |
| `core.tasks.train` | Training riconoscimento |
| `core.tasks.segtrain` | Training segmentazione |
| `core.tasks.recalculate_masks` | Ricalcolo maschere |
| `core.tasks.convert` | Conversione immagine |
| `imports.tasks.document_import` | Import documento |

### Verifica Completamento Operazioni Asincrone

**Logica di polling:**
1. Chiamare `GET /api/tasks/?document={pk}`
2. Filtrare i task per `method` corrispondente all'operazione
3. L'operazione è **in corso** se esistono task con `workflow_state` = `0` o `1`
4. L'operazione è **terminata** quando tutti i task hanno `workflow_state` = `2`, `3`, o `4`
5. L'operazione ha **successo completo** se tutti i task hanno `workflow_state` = `3`

### Esempio Task per Stato

**Task in Coda:**
```json
{
  "pk": 101,
  "workflow_state": 0,
  "started_at": null,
  "done_at": null,
  "messages": ""
}
```

**Task in Esecuzione:**
```json
{
  "pk": 101,
  "workflow_state": 1,
  "started_at": "2024-01-15T10:30:05.000000Z",
  "done_at": null,
  "messages": "Processing..."
}
```

**Task Completato:**
```json
{
  "pk": 101,
  "workflow_state": 3,
  "started_at": "2024-01-15T10:30:05.000000Z",
  "done_at": "2024-01-15T10:31:00.000000Z",
  "messages": "Found 25 lines, 3 regions"
}
```

**Task Fallito:**
```json
{
  "pk": 101,
  "workflow_state": 2,
  "started_at": "2024-01-15T10:30:05.000000Z",
  "done_at": "2024-01-15T10:30:10.000000Z",
  "messages": "Error: Image too small for segmentation"
}
```

**Task Annullato:**
```json
{
  "pk": 101,
  "workflow_state": 4,
  "started_at": "2024-01-15T10:30:05.000000Z",
  "done_at": "2024-01-15T10:30:15.000000Z",
  "messages": "Canceled by user admin"
}
```

### Comportamento in Caso di Fallimento Parziale

- I task sono **indipendenti per ogni pagina**
- Se un task fallisce, gli altri continuano
- Le pagine con task falliti non avranno elaborazione
- È possibile ritentare solo le pagine fallite specificando `"parts": [pk1, pk2, ...]`

---

# Risorse API

## 👤 Users

### Endpoint: `/api/users/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/users/` | Lista utenti (admin: tutti, user: solo se stesso) |
| GET | `/api/users/{pk}/` | Dettaglio utente |
| POST | `/api/users/` | Crea utente (solo admin) |
| PUT/PATCH | `/api/users/{pk}/` | Aggiorna utente |
| DELETE | `/api/users/{pk}/` | Elimina utente (solo admin) |
| GET | `/api/users/current/` | Ottieni utente corrente |

**Campi User:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID (read-only) |
| `username` | string | Nome utente |
| `email` | string | Email |
| `first_name` | string | Nome |
| `last_name` | string | Cognome |
| `is_active` | boolean | Attivo |
| `date_joined` | datetime | Data registrazione (read-only) |
| `last_login` | datetime | Ultimo accesso (read-only) |
| `is_staff` | boolean | Staff (read-only) |
| `can_invite` | boolean | Può invitare (read-only) |

---

## 👥 Groups

### Endpoint: `/api/groups/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/groups/` | Lista gruppi dell'utente |
| GET | `/api/groups/{pk}/` | Dettaglio gruppo |
| POST | `/api/groups/` | Crea gruppo |
| PUT/PATCH | `/api/groups/{pk}/` | Aggiorna gruppo |
| DELETE | `/api/groups/{pk}/` | Elimina gruppo |

**Campi Group:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome gruppo |
| `users` | array[User] | Lista utenti (read-only) |
| `owner` | integer | ID owner (read-only) |

---

## 📁 Projects

### Endpoint: `/api/projects/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/projects/` | Lista progetti |
| GET | `/api/projects/{pk}/` | Dettaglio progetto |
| POST | `/api/projects/` | Crea progetto |
| PUT/PATCH | `/api/projects/{pk}/` | Aggiorna progetto |
| DELETE | `/api/projects/{pk}/` | Elimina progetto |
| POST | `/api/projects/{pk}/share/` | Condividi progetto |

**Campi Project:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID (read-only) |
| `name` | string | Nome progetto |
| `slug` | string | Slug (read-only) |
| `owner` | string | Username proprietario (read-only) |
| `documents_count` | integer | Numero documenti (read-only) |
| `tags` | array[integer] | IDs dei tag |
| `shared_with_users` | array[User] | Utenti condivisi (read-only) |
| `shared_with_groups` | array[Group] | Gruppi condivisi (read-only) |
| `created_at` | datetime | Data creazione |
| `updated_at` | datetime | Data aggiornamento |

**Filtri disponibili:**
- `?tags=1|2` (OR) oppure `?tags=1,2` (AND) oppure `?tags=none`

**Ordinamento:**
- `?ordering=created_at`, `documents_count`, `id`, `name`, `owner`, `updated_at` (prefisso `-` per desc)

### Share Project
**Endpoint:** `POST /api/projects/{pk}/share/`

**Parametri:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `group` | integer | PK del gruppo |
| `user` | string | Username dell'utente |

*Nota: Passare `group` OPPURE `user`, non entrambi.*

---

## 🏷️ Project Tags

### Endpoint: `/api/tags/project/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/tags/project/` | Lista tag del progetto |
| POST | `/api/tags/project/` | Crea tag |
| PUT/PATCH | `/api/tags/project/{pk}/` | Aggiorna tag |
| DELETE | `/api/tags/project/{pk}/` | Elimina tag |

**Campi ProjectTag:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome tag |
| `color` | string | Colore (hex) |

---

## 📄 Documents

### Endpoint: `/api/documents/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/documents/` | Lista documenti |
| GET | `/api/documents/{pk}/` | Dettaglio documento |
| POST | `/api/documents/` | Crea documento |
| PUT/PATCH | `/api/documents/{pk}/` | Aggiorna documento |
| DELETE | `/api/documents/{pk}/` | Elimina documento |

**Campi Document:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID (read-only) |
| `name` | string | Nome documento |
| `project` | string | Slug del progetto |
| `project_name` | string | Nome progetto (read-only) |
| `project_id` | integer | ID progetto (read-only) |
| `main_script` | string | Nome script (es. "Latin") |
| `read_direction` | string | Direzione lettura |
| `line_offset` | integer | Offset linee |
| `show_confidence_viz` | boolean | Mostra visualizzazione confidenza |
| `transcriptions` | array[Transcription] | Trascrizioni (read-only) |
| `valid_block_types` | array[BlockType] | Tipi blocco validi (read-only) |
| `valid_line_types` | array[LineType] | Tipi linea validi (read-only) |
| `valid_part_types` | array[PartType] | Tipi part validi (read-only) |
| `parts_count` | integer | Numero pagine (read-only) |
| `tags` | array[integer] | IDs dei tag |
| `created_at` | datetime | Data creazione (read-only) |
| `updated_at` | datetime | Data aggiornamento (read-only) |
| `shared_with_users` | array[User] | Utenti condivisi (read-only) |
| `shared_with_groups` | array[Group] | Gruppi condivisi (read-only) |

**Filtri:**
- `?project={slug}`
- `?tags=1|2` (OR) oppure `?tags=1,2` (AND) oppure `?tags=none`

**Ordinamento:**
- `?ordering=name`, `parts_count`, `updated_at`

---

### Azioni sui Documenti

#### Tasks List
**Endpoint:** `GET /api/documents/tasks/`

Ottiene lista documenti con task attivi.

**Query Params:**
| Parametro | Tipo | Descrizione |
|-----------|------|-------------|
| `user_id` | integer | Filtra per user (solo admin) |
| `name` | string | Filtra per nome documento |
| `task_state` | string | Filtra per stato task (`queued`, `started`, `error`, `done`) |

#### Cancel Tasks
**Endpoint:** `POST /api/documents/{pk}/cancel_tasks/`

**Parametri opzionali:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `task_report` | integer | PK del task specifico da annullare |

**Response Successo:**
```json
{
  "status": "canceled",
  "details": "Canceled 5 pending/running tasks linked to document Test."
}
```

#### Cancel Import
**Endpoint:** `POST /api/documents/{pk}/cancel_import/`

**Response Successo:**
```json
{
  "status": "canceled"
}
```

**Response Errore - Import già terminato:**
```json
{
  "status": "already stopped"
}
```

#### Cancel Training
**Endpoint:** `POST /api/documents/{pk}/cancel_training/`

**Response Successo:**
```json
{
  "status": "canceled"
}
```

---

#### Segment (Segmentazione)
**Endpoint:** `POST /api/documents/{pk}/segment/`

**⚠️ Operazione ASINCRONA**

**Parametri:**
| Campo | Tipo | Obbligatorio | Default | Descrizione |
|-------|------|--------------|---------|-------------|
| `parts` | array[integer] | ❌ | Tutte | Lista PK delle parti |
| `model` | integer | ❌ | null | PK del modello di segmentazione |
| `steps` | string | ❌ | `both` | `both`, `lines`, `masks`, `regions` |
| `override` | boolean | ❌ | false | Sovrascrive segmentazione esistente |
| `text_direction` | string | ❌ | `horizontal-lr` | Direzione testo |

**Opzioni `steps`:**
- `both` - Linee e regioni
- `lines` - Solo linee (baseline e maschere)
- `masks` - Solo maschere linee
- `regions` - Solo regioni

**Opzioni `text_direction`:**
- `horizontal-lr` - Orizzontale sinistra→destra
- `horizontal-rl` - Orizzontale destra→sinistra
- `vertical-lr` - Verticale sinistra→destra
- `vertical-rl` - Verticale destra→sinistra

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

**Possibili Errori:**
| Errore | Response |
|--------|----------|
| Già in elaborazione | `{"status": "error", "error": "Already processing."}` |
| Steps non valido | `{"status": "error", "error": {"steps": ["\"x\" is not a valid choice."]}}` |
| text_direction non valido | `{"status": "error", "error": {"text_direction": ["\"x\" is not a valid choice."]}}` |
| Modello non trovato | `{"status": "error", "error": {"model": ["Invalid pk \"x\" - object does not exist."]}}` |
| Parti non trovate | `{"status": "error", "error": {"parts": ["Invalid pk \"x\" - object does not exist."]}}` |
| Quote CPU esaurite | `{"status": "error", "error": ["You don't have any CPU minutes left."]}` |

---

#### Train (Training Riconoscimento)
**Endpoint:** `POST /api/documents/{pk}/train/`

**⚠️ Operazione ASINCRONA**

**Parametri:**
| Campo | Tipo | Obbligatorio | Descrizione |
|-------|------|--------------|-------------|
| `parts` | array[integer] | ✅ | Lista PK delle parti |
| `transcription` | integer | ✅ | PK della trascrizione |
| `model` | integer | ❌ | PK modello esistente |
| `model_name` | string | ❌ | Nome nuovo modello |
| `override` | boolean | ❌ | Sovrascrive modello esistente |

*Nota: `model` O `model_name` è obbligatorio.*

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

---

#### SegTrain (Training Segmentazione)
**Endpoint:** `POST /api/documents/{pk}/segtrain/`

**⚠️ Operazione ASINCRONA**

**Parametri:**
| Campo | Tipo | Obbligatorio | Descrizione |
|-------|------|--------------|-------------|
| `parts` | array[integer] | ✅ | Lista PK delle parti (min. 2) |
| `model` | integer | ❌ | PK modello esistente |
| `model_name` | string | ❌ | Nome nuovo modello |
| `override` | boolean | ❌ | Sovrascrive modello esistente |

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

**Response Errore - Meno di 2 parti (HTTP 400):**
```json
{
  "status": "error",
  "error": {
    "parts": ["Segmentation training requires at least 2 images."]
  }
}
```

---

#### Transcribe (Trascrizione OCR)
**Endpoint:** `POST /api/documents/{pk}/transcribe/`

**⚠️ Operazione ASINCRONA**

**Parametri:**
| Campo | Tipo | Obbligatorio | Descrizione |
|-------|------|--------------|-------------|
| `model` | integer | ✅ | PK modello OCR (tipo `recognize`) |
| `transcription` | integer | ✅ | PK trascrizione di destinazione |
| `parts` | array[integer] | ❌ | Lista PK delle parti (default: tutte) |

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

**Possibili Errori:**
| Errore | Response |
|--------|----------|
| Model mancante | `{"status": "error", "error": {"model": ["This field is required."]}}` |
| Transcription mancante | `{"status": "error", "error": {"transcription": ["This field is required."]}}` |
| Modello non trovato/non recognize | `{"status": "error", "error": {"model": ["Invalid pk \"x\" - object does not exist."]}}` |
| Trascrizione non del documento | `{"status": "error", "error": {"transcription": ["Invalid pk \"x\" - object does not exist."]}}` |
| Già in elaborazione | `{"status": "error", "error": "Already processing."}` |
| Quote CPU esaurite | `{"status": "error", "error": ["You don't have any CPU minutes left."]}` |

---

#### Align (Allineamento Testo)
**Endpoint:** `POST /api/documents/{pk}/align/`

**⚠️ Operazione ASINCRONA**

**Parametri:**
| Campo | Tipo | Obbligatorio | Default | Descrizione |
|-------|------|--------------|---------|-------------|
| `parts` | array[integer] | ❌ | Tutte | Lista PK delle parti |
| `transcription` | integer | ✅ | - | PK trascrizione da allineare |
| `witness_file` | file | ❌* | - | File di riferimento (.txt) |
| `existing_witness` | integer | ❌* | - | PK witness esistente |
| `layer_name` | string | ✅ | - | Nome nuovo layer trascrizione |
| `n_gram` | integer | ✅ | 25 | Lunghezza n-gram (2-25) |
| `max_offset` | integer | ❌ | 0 | Max offset caratteri (0-80) |
| `beam_size` | integer | ❌ | 20 | Beam size (0-100) |
| `gap` | integer | ✅ | 600 | Distanza n-gram (1-1000000) |
| `merge` | boolean | ❌ | false | Unisci con trascrizione esistente |
| `full_doc` | boolean | ❌ | true | Usa documento completo |
| `threshold` | float | ✅ | 0.8 | Soglia match (0.0-1.0) |
| `region_types` | array[string] | ✅ | - | Tipi regione da includere |

*Nota: `witness_file` OPPURE `existing_witness` obbligatorio.*

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

---

#### Forced Align
**Endpoint:** `POST /api/documents/{pk}/forced_align/`

**⚠️ Operazione ASINCRONA**

**Parametri:**
| Campo | Tipo | Obbligatorio | Descrizione |
|-------|------|--------------|-------------|
| `parts` | array[integer] | ❌ | Lista PK delle parti |
| `model` | integer | ✅ | PK modello |
| `transcription` | integer | ✅ | PK trascrizione |

**Response Successo (HTTP 200):**
```json
{
  "status": "success"
}
```

---

#### Export
**Endpoint:** `POST /api/documents/{pk}/export/`

**⚠️ Operazione ASINCRONA**

**Response Successo (HTTP 200):**
```json
{
  "status": "ok"
}
```

---

#### Modify Ontology
**Endpoint:** `PATCH /api/documents/{pk}/modify_ontology/`

**Parametri:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `valid_part_types` | array[integer] | PK tipi parte validi |
| `valid_line_types` | array[integer] | PK tipi linea validi |
| `valid_block_types` | array[integer] | PK tipi blocco validi |

*Almeno uno dei tre campi è obbligatorio.*

**Response Successo (HTTP 200):**
Restituisce l'intero oggetto Document aggiornato.

---

#### Bulk Move Parts
**Endpoint:** `POST /api/documents/{pk}/bulk_move_parts/`

**Parametri:**
| Campo | Tipo | Obbligatorio | Descrizione |
|-------|------|--------------|-------------|
| `parts` | array[integer] | ✅ | PK delle parti da spostare |
| `index` | integer | ✅ | Nuovo indice (-1 = fine) |

**Response Successo:**
```json
{
  "status": "moved"
}
```

---

#### Share Document
**Endpoint:** `POST /api/documents/{pk}/share/`

**Parametri:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `group` | integer | PK del gruppo |
| `user` | string | Username dell'utente |

*Passare `group` OPPURE `user`.*

**Response Successo (HTTP 201):**
Restituisce l'intero oggetto Document aggiornato.

---

#### Document Stats
**Endpoint:** `GET /api/documents/{pk}/stats/`

**Query Params:**
- `?ordering=frequency|-frequency|typology|-typology|taxonomy|-taxonomy`

**Response:**
```json
{
  "regions": [...],
  "lines": [...],
  "image_annotations": [...],
  "text_annotations": [...]
}
```

---

## 🏷️ Document Tags

### Endpoint: `/api/projects/{project_pk}/tags/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/projects/{project_pk}/tags/` | Lista tag documento |
| POST | `/api/projects/{project_pk}/tags/` | Crea tag |
| PUT/PATCH | `/api/projects/{project_pk}/tags/{pk}/` | Aggiorna tag |
| DELETE | `/api/projects/{project_pk}/tags/{pk}/` | Elimina tag |

**Campi DocumentTag:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome tag |
| `color` | string | Colore (hex) |

---

## 📋 Document Metadata

### Endpoint: `/api/documents/{document_pk}/metadata/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/documents/{document_pk}/metadata/` | Lista metadata |
| POST | `/api/documents/{document_pk}/metadata/` | Crea metadata |
| PUT/PATCH | `/api/documents/{document_pk}/metadata/{pk}/` | Aggiorna |
| DELETE | `/api/documents/{document_pk}/metadata/{pk}/` | Elimina |

**Campi DocumentMetadata:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `key` | object | `{name: string, cidoc_id: string}` |
| `value` | string | Valore metadata |

---

## 📝 Transcriptions

### Endpoint: `/api/documents/{document_pk}/transcriptions/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/documents/{document_pk}/transcriptions/` | Lista trascrizioni |
| GET | `/api/documents/{document_pk}/transcriptions/{pk}/` | Dettaglio |
| POST | `/api/documents/{document_pk}/transcriptions/` | Crea trascrizione |
| PUT/PATCH | `/api/documents/{document_pk}/transcriptions/{pk}/` | Aggiorna |
| DELETE | `/api/documents/{document_pk}/transcriptions/{pk}/` | Archivia |
| GET | `/api/documents/{document_pk}/transcriptions/{pk}/stats/` | Statistiche |

**Campi Transcription:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome trascrizione |
| `archived` | boolean | Archiviata |
| `avg_confidence` | float | Confidenza media |
| `created_at` | datetime | Data creazione |
| `comments` | string | Commenti |

### Transcription Stats
**Endpoint:** `GET /api/documents/{document_pk}/transcriptions/{pk}/stats/`

**Query Params:**
- `?ordering=frequency|-frequency|char|-char`

**Response:**
```json
{
  "line_count": 150,
  "characters": [
    {"char": "a", "frequency": 1250},
    {"char": "e", "frequency": 980}
  ]
}
```

---

## 📖 Document Parts (Pagine/Immagini)

### Endpoint: `/api/documents/{document_pk}/parts/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/documents/{document_pk}/parts/` | Lista parti |
| GET | `/api/documents/{document_pk}/parts/{pk}/` | Dettaglio parte |
| POST | `/api/documents/{document_pk}/parts/` | Upload immagine |
| PUT/PATCH | `/api/documents/{document_pk}/parts/{pk}/` | Aggiorna |
| DELETE | `/api/documents/{document_pk}/parts/{pk}/` | Elimina |

**Campi Part (lista):**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome |
| `filename` | string | Nome file (read-only) |
| `title` | string | Titolo |
| `typology` | integer | PK tipo parte |
| `image` | object | `{uri, size, thumbnails}` |
| `image_file_size` | integer | Dimensione file |
| `original_filename` | string | Nome originale |
| `workflow` | object | Stato workflow (read-only) |
| `order` | integer | Ordine |
| `recoverable` | boolean | Recuperabile |
| `transcription_progress` | integer | Progresso trascrizione |
| `source` | string | Sorgente |
| `max_avg_confidence` | float | Max confidenza media |
| `comments` | string | Commenti |
| `updated_at` | datetime | Data aggiornamento |

**Campi Aggiuntivi in Dettaglio:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `regions` | array[Block] | Lista regioni/blocchi |
| `lines` | array[Line] | Lista linee |
| `metadata` | array[Metadata] | Lista metadata |
| `previous` | integer | PK parte precedente |
| `next` | integer | PK parte successiva |

### Azioni sulle Parti

#### Get by Order
**Endpoint:** `GET /api/documents/{document_pk}/parts/byorder/?order={n}`

Redirect alla parte con ordine specificato.

#### Move Part
**Endpoint:** `POST /api/documents/{document_pk}/parts/{pk}/move/`

**Parametri:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `index` | integer | Nuovo indice |

**Response Successo:**
```json
{
  "status": "moved"
}
```

#### Cancel Part Tasks
**Endpoint:** `POST /api/documents/{document_pk}/parts/{pk}/cancel/`

**Response:**
```json
{
  "status": "canceled",
  "workflow": {...}
}
```

#### Reset Masks
**Endpoint:** `POST /api/documents/{document_pk}/parts/{pk}/reset_masks/`

**Query Params:**
- `?only=1,2,3` - Lista PK linee specifiche

**Response Successo:**
```json
{
  "status": "ok"
}
```

**Response Errore - Quote esaurite:**
```json
{
  "error": "You don't have any CPU minutes left."
}
```

#### Recalculate Ordering
**Endpoint:** `POST /api/documents/{document_pk}/parts/{pk}/recalculate_ordering/`

**Response:**
```json
{
  "status": "done",
  "lines": [{"pk": 1, "order": 0}, {"pk": 2, "order": 1}]
}
```

#### Rotate
**Endpoint:** `POST /api/documents/{document_pk}/parts/{pk}/rotate/`

**Parametri:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `angle` | integer | Angolo rotazione |

**Response Successo:**
```json
{
  "status": "done"
}
```

**Response Errore - Angolo mancante:**
```json
{
  "error": "Post an angle."
}
```

#### Crop
**Endpoint:** `POST /api/documents/{document_pk}/parts/{pk}/crop/`

**Parametri:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `x1` | integer | Top-left X |
| `y1` | integer | Top-left Y |
| `x2` | integer | Bottom-right X |
| `y2` | integer | Bottom-right Y |

**Response Successo:**
```json
{
  "status": "done"
}
```

**Response Errore - Parametri mancanti:**
```json
{
  "error": "Post corners as x1, y1 (top left) and x2, y2 (bottom right)."
}
```

---

## 📋 Part Metadata

### Endpoint: `/api/documents/{document_pk}/parts/{part_pk}/metadata/`

Stessa struttura di Document Metadata.

---

## 🟦 Blocks (Regioni)

### Endpoint: `/api/documents/{document_pk}/parts/{part_pk}/blocks/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../blocks/` | Lista blocchi |
| POST | `.../blocks/` | Crea blocco |
| PUT/PATCH | `.../blocks/{pk}/` | Aggiorna |
| DELETE | `.../blocks/{pk}/` | Elimina |

**Campi Block:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `document_part` | integer | PK parte |
| `external_id` | string | ID esterno |
| `order` | integer | Ordine |
| `box` | array | Coordinate `[x1, y1, x2, y2]` |
| `typology` | integer | PK tipo blocco (nullable) |

---

## 📏 Lines (Linee)

### Endpoint: `/api/documents/{document_pk}/parts/{part_pk}/lines/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../lines/` | Lista linee |
| GET | `.../lines/{pk}/` | Dettaglio (include trascrizioni) |
| POST | `.../lines/` | Crea linea |
| PUT/PATCH | `.../lines/{pk}/` | Aggiorna |
| DELETE | `.../lines/{pk}/` | Elimina |

**Campi Line:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `document_part` | integer | PK parte |
| `external_id` | string | ID esterno |
| `order` | integer | Ordine |
| `region` | integer | PK blocco (nullable) |
| `baseline` | array | Punti baseline `[[x,y], ...]` |
| `mask` | array | Punti maschera |
| `typology` | integer | PK tipo linea (nullable) |

**Campi Aggiuntivi in Dettaglio:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `transcriptions` | array[LineTranscription] | Trascrizioni linea |

### Azioni sulle Linee

#### Bulk Create
**Endpoint:** `POST /api/documents/{document_pk}/parts/{part_pk}/lines/bulk_create/`

**Parametri:**
```json
{
  "lines": [
    {
      "document_part": 1,
      "baseline": [[0,0], [100,0]],
      "transcriptions": [
        {"transcription": 1, "content": "testo"}
      ]
    }
  ]
}
```

**Response Successo:**
```json
{
  "status": "ok",
  "lines": [...]
}
```

#### Bulk Update
**Endpoint:** `PUT /api/documents/{document_pk}/parts/{part_pk}/lines/bulk_update/`

**Parametri:**
```json
{
  "lines": [
    {"pk": 1, "baseline": [[0,0], [100,0]]},
    {"pk": 2, "baseline": [[0,10], [100,10]]}
  ]
}
```

**Response Successo:**
```json
{
  "status": "ok",
  "lines": [...]
}
```

#### Bulk Delete
**Endpoint:** `POST /api/documents/{document_pk}/parts/{part_pk}/lines/bulk_delete/`

**Parametri:**
```json
{
  "lines": [1, 2, 3]
}
```

**Response Successo:**
```json
{
  "status": "ok",
  "lines": [...]
}
```

#### Merge Lines
**Endpoint:** `POST /api/documents/{document_pk}/parts/{part_pk}/lines/merge/`

**Parametri:**
```json
{
  "lines": [1, 2, 3]
}
```
*Max 10 linee.*

**Response Successo:**
```json
{
  "status": "ok",
  "lines": {
    "created": {...},
    "deleted": [...]
  }
}
```

**Response Errore - Troppe linee:**
```json
{
  "status": "error",
  "error": "Can't merge more than 10 lines"
}
```

**Response Errore - Linea senza baseline:**
```json
{
  "status": "error",
  "error": "Lines without a baseline cannot be merged"
}
```

#### Move Lines
**Endpoint:** `POST /api/documents/{document_pk}/parts/{part_pk}/lines/move/`

**Parametri:**
```json
{
  "lines": [
    {"pk": 1, "order": 5},
    {"pk": 2, "order": 6}
  ]
}
```

---

## 📝 Line Transcriptions

### Endpoint: `/api/documents/{document_pk}/parts/{part_pk}/transcriptions/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../transcriptions/` | Lista trascrizioni linea |
| POST | `.../transcriptions/` | Crea |
| PUT/PATCH | `.../transcriptions/{pk}/` | Aggiorna (crea versione) |
| DELETE | `.../transcriptions/{pk}/` | Elimina |

**Query Params:**
- `?transcription={pk}` - Filtra per trascrizione

**Campi LineTranscription:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `line` | integer | PK linea |
| `transcription` | integer | PK trascrizione |
| `content` | string | Contenuto testo (HTML consentito: em, strong, s, u) |
| `graphs` | object | Grafi |
| `avg_confidence` | float | Confidenza media |
| `versions` | array | Versioni precedenti |
| `version_author` | string | Autore versione |
| `version_source` | string | Sorgente versione |
| `version_updated_at` | datetime | Data aggiornamento versione |

### Azioni LineTranscriptions

#### Bulk Create
**Endpoint:** `POST .../transcriptions/bulk_create/`

**Parametri:**
```json
{
  "lines": [
    {"line": 1, "transcription": 1, "content": "testo1"},
    {"line": 2, "transcription": 1, "content": "testo2"}
  ]
}
```

**Response Successo:**
```json
{
  "status": "ok",
  "lines": [...]
}
```

#### Bulk Update
**Endpoint:** `PUT .../transcriptions/bulk_update/`

**Parametri:**
```json
{
  "lines": [
    {"pk": 1, "content": "nuovo testo"},
    {"pk": 2, "content": "altro testo"}
  ]
}
```

#### Bulk Delete
**Endpoint:** `POST .../transcriptions/bulk_delete/`

**Parametri:**
```json
{
  "lines": [1, 2, 3]
}
```
*Nota: svuota il contenuto invece di eliminare.*

**Response:** HTTP 204 No Content

---

## 🏷️ Annotation Taxonomies

### Endpoint: `/api/documents/{document_pk}/taxonomies/annotations/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../taxonomies/annotations/` | Lista tassonomie |
| POST | `.../taxonomies/annotations/` | Crea |
| PUT/PATCH | `.../taxonomies/annotations/{pk}/` | Aggiorna |
| DELETE | `.../taxonomies/annotations/{pk}/` | Elimina |

**Query Params:**
- `?target=image` - Solo tassonomie immagine
- `?target=text` - Solo tassonomie testo

**Campi AnnotationTaxonomy:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome |
| `abbreviation` | string | Abbreviazione |
| `marker_type` | string | Tipo marker |
| `marker_detail` | string | Dettaglio marker |
| `has_comments` | boolean | Ha commenti |
| `typology` | object | `{pk, name}` |
| `components` | array[integer] | PK componenti |

---

## 🧩 Annotation Components

### Endpoint: `/api/documents/{document_pk}/taxonomies/components/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../taxonomies/components/` | Lista componenti |
| POST | `.../taxonomies/components/` | Crea |
| PUT/PATCH | `.../taxonomies/components/{pk}/` | Aggiorna |
| DELETE | `.../taxonomies/components/{pk}/` | Elimina |

**Campi AnnotationComponent:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome |
| `allowed_values` | array | Valori consentiti |

---

## 🖼️ Image Annotations

### Endpoint: `/api/documents/{document_pk}/parts/{part_pk}/annotations/image/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../annotations/image/` | Lista annotazioni |
| POST | `.../annotations/image/` | Crea |
| PUT/PATCH | `.../annotations/image/{pk}/` | Aggiorna |
| DELETE | `.../annotations/image/{pk}/` | Elimina |

**Campi ImageAnnotation:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `part` | integer | PK parte |
| `comments` | string | Commenti |
| `taxonomy` | integer | PK tassonomia |
| `components` | array | `[{component, value}]` |
| `coordinates` | array | Coordinate |
| `as_w3c` | object | Formato W3C (read-only) |

---

## 📝 Text Annotations

### Endpoint: `/api/documents/{document_pk}/parts/{part_pk}/annotations/text/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../annotations/text/` | Lista annotazioni |
| POST | `.../annotations/text/` | Crea |
| PUT/PATCH | `.../annotations/text/{pk}/` | Aggiorna |
| DELETE | `.../annotations/text/{pk}/` | Elimina |

**Query Params:**
- `?transcription={pk}` - Filtra per trascrizione

**Campi TextAnnotation:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `part` | integer | PK parte |
| `comments` | string | Commenti |
| `taxonomy` | integer | PK tassonomia |
| `components` | array | `[{component, value}]` |
| `transcription` | integer | PK trascrizione |
| `start_line` | integer | PK linea inizio |
| `start_offset` | integer | Offset carattere inizio |
| `end_line` | integer | PK linea fine |
| `end_offset` | integer | Offset carattere fine |
| `as_w3c` | object | Formato W3C (read-only) |

---

## 🤖 OCR Models

### Endpoint: `/api/models/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/models/` | Lista modelli |
| GET | `/api/models/{pk}/` | Dettaglio modello |
| POST | `/api/models/` | Upload modello |
| PUT/PATCH | `/api/models/{pk}/` | Aggiorna |
| DELETE | `/api/models/{pk}/` | Elimina |
| POST | `/api/models/{pk}/cancel_training/` | Annulla training |

**Filtri:**
- `?documents={pk}` - Filtra per documento
- `?job=recognize` - Solo modelli OCR
- `?job=segment` - Solo modelli segmentazione

**Campi OcrModel:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome |
| `file` | file | File modello |
| `file_size` | integer | Dimensione file |
| `job` | string | `recognize` o `segment` |
| `owner` | string | Username proprietario (read-only) |
| `training` | boolean | In training (read-only) |
| `versions` | array | Versioni |
| `documents` | array[integer] | PK documenti associati |
| `accuracy_percent` | float | Accuratezza |
| `rights` | string | Permessi (read-only): `owner`, `public`, `user` |
| `script` | string | Nome script (read-only) |
| `parent` | string | Nome modello parent (read-only) |
| `can_share` | boolean | Può condividere (read-only) |

---

## 📊 Tasks & Reports

### Task Reports
**Endpoint:** `/api/tasks/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/tasks/` | Lista task dell'utente |
| GET | `/api/tasks/{pk}/` | Dettaglio task |

**Filtri:**
- `?document={pk}` - Filtra per documento
- `?group={pk}` - Filtra per gruppo task

**Ordinamento:**
- `?ordering=queued_at`, `started_at`, `done_at`

**Campi TaskReport:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `document` | integer | PK documento |
| `document_part` | string | Nome parte |
| `workflow_state` | integer | Stato (vedi tabella sotto) |
| `label` | string | Etichetta |
| `messages` | string | Messaggi/errori |
| `queued_at` | datetime | Data accodamento |
| `started_at` | datetime | Data inizio |
| `done_at` | datetime | Data fine |
| `method` | string | Metodo eseguito |
| `user` | integer | PK utente |

### Task Groups
**Endpoint:** `/api/documents/{document_pk}/task_groups/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `.../task_groups/` | Lista gruppi task |
| GET | `.../task_groups/{pk}/` | Dettaglio |

**Campi TaskGroup:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `method` | string | Metodo |
| `created_at` | datetime | Data creazione |
| `created_by` | string | Username creatore |
| `tasks` | array | Statistiche task per stato |
| `page_count` | integer | Numero pagine coinvolte |

---

## 📜 Scripts

### Endpoint: `/api/scripts/` (Read-Only)

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/scripts/` | Lista script |
| GET | `/api/scripts/{pk}/` | Dettaglio script |

**Campi Script:** Tutti i campi del modello Script.

---

## 📚 Textual Witnesses

### Endpoint: `/api/textual-witnesses/`

| Metodo | URL | Descrizione |
|--------|-----|-------------|
| GET | `/api/textual-witnesses/` | Lista witness |
| POST | `/api/textual-witnesses/` | Crea witness |
| PUT/PATCH | `/api/textual-witnesses/{pk}/` | Aggiorna |
| DELETE | `/api/textual-witnesses/{pk}/` | Elimina |

**Campi TextualWitness:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome |
| `file` | file | File testo |
| `owner` | string | Username proprietario (read-only) |

---

## 🏷️ Types (Tipologie)

### Block Types
**Endpoint:** `/api/types/block/`

### Line Types
**Endpoint:** `/api/types/line/`

### Annotation Types
**Endpoint:** `/api/types/annotations/`

### Part Types
**Endpoint:** `/api/types/part/`

Tutti supportano CRUD standard.

**Campi comuni:**
| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `pk` | integer | ID |
| `name` | string | Nome tipo |

---

## 📥 Import (Nested in Document)

### Endpoint: `/api/documents/{document_pk}/import/`

**Metodo:** POST

**⚠️ Operazione ASINCRONA**

**Parametri:**
| Campo | Tipo | Obbligatorio | Descrizione |
|-------|------|--------------|-------------|
| `mode` | string | ✅ | `pdf`, `iiif`, `mets`, `xml` |
| `transcription` | integer | ❌ | PK trascrizione esistente |
| `name` | string | ❌ | Nome nuova trascrizione |
| `override` | boolean | ❌ | Sovrascrive esistente |
| `upload_file` | file | Dipende | File da caricare |
| `iiif_uri` | URL | Dipende | URI manifest IIIF |
| `mets_type` | string | Dipende | `local` o `url` |
| `mets_uri` | URL | Dipende | URI file METS |

**Requisiti per mode:**
- `pdf`: richiede `upload_file`
- `iiif`: richiede `iiif_uri`
- `mets`: richiede `mets_type`, poi `mets_uri` (se url) o `upload_file` (se local)
- `xml`: richiede `upload_file`

**Response Successo (HTTP 201):**
```json
{
  "status": "ok"
}
```

**Response Errore - Mode mancante:**
```json
{
  "status": "error",
  "error": {
    "mode": ["This field is required."]
  }
}
```

**Response Errore - IIIF URI non valido:**
```json
{
  "status": "error",
  "error": {
    "iiif_uri": ["FileImportError(...)"]
  }
}
```

---

## 🔧 Custom API Endpoints

Endpoint custom che estendono le API core di eScriptorium.

### Export TEI XML
**Endpoint:** `GET /api/custom/documents/{document_pk}/transcriptions/{transcription_pk}/tei/`

**Descrizione:** Esporta una trascrizione in formato TEI XML in modo sincrono.

**Query Params:**
| Parametro | Tipo | Default | Descrizione |
|-----------|------|---------|-------------|
| `format` | string | `json` | `json` o `xml` per response raw |

**Response Successo (HTTP 200):**
```json
{
  "document_id": 42,
  "document_name": "Il mio documento",
  "transcription_id": 5,
  "transcription_name": "OCR Output",
  "tei_xml": "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<TEI xmlns=\"http://www.tei-c.org/ns/1.0\">...</TEI>"
}
```

**Response con format=xml:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<TEI xmlns="http://www.tei-c.org/ns/1.0">
  <teiHeader>...</teiHeader>
  <text>
    <body>...</body>
  </text>
</TEI>
```

**✅ Operazione SINCRONA:** Il contenuto TEI è immediatamente disponibile.

---

### Export Formati Disponibili
**Endpoint:** `GET /api/custom/export-formats/`

**Response:**
```json
{
  "formats": {
    "text": "Plain Text",
    "alto": "ALTO XML",
    "pagexml": "PAGE XML",
    "teixml": "OpenITI TEI XML"
  }
}
```

---

### Export Generico
**Endpoint:** `GET /api/custom/documents/{document_pk}/transcriptions/{transcription_pk}/export/{format}/`

**Formati supportati:** `text`, `alto`, `pagexml`, `teixml`

**Query Params:**
| Parametro | Tipo | Default | Descrizione |
|-----------|------|---------|-------------|
| `output` | string | `json` | `json` o `raw` |
| `region_types` | string | tutti | Lista separata da virgola |
| `include_orphans` | string | `true` | Include linee orfane |

**Response Successo (HTTP 200):**
```json
{
  "document_id": 42,
  "document_name": "Il mio documento",
  "transcription_id": 5,
  "transcription_name": "OCR Output",
  "format": "teixml",
  "format_label": "OpenITI TEI XML",
  "content": "..." 
}
```

---

## 🔴 Codici HTTP Comuni

| Codice | Significato |
|--------|-------------|
| `200` | ✅ Successo (GET, PUT, PATCH, azioni) |
| `201` | ✅ Creazione riuscita (POST) |
| `204` | ✅ Eliminazione riuscita (DELETE) |
| `400` | ❌ Errore di validazione / dati non validi |
| `401` | 🔒 Non autenticato (token mancante/scaduto) |
| `403` | 🚫 Non autorizzato (permessi insufficienti) |
| `404` | 🔍 Risorsa non trovata |
| `500` | 💥 Errore server interno |

### Errori Comuni

**Non autenticato (HTTP 401):**
```json
{
  "detail": "Authentication credentials were not provided."
}
```

**Token non valido (HTTP 401):**
```json
{
  "detail": "Invalid token."
}
```

**Non autorizzato (HTTP 403):**
```json
{
  "detail": "You do not have permission to perform this action."
}
```

**Risorsa non trovata (HTTP 404):**
```json
{
  "detail": "Not found."
}
```

**Errore validazione (HTTP 400):**
```json
{
  "field_name": ["Error message"],
  "non_field_errors": ["General error"]
}
```

---

## 📌 Note Generali

1. **Paginazione:** La maggior parte delle liste usa paginazione. Parametri:
   - `?page=1`
   - Risposte includono `count`, `next`, `previous`, `results`

2. **Formato Date:** ISO 8601 (es. `2024-01-15T10:30:00Z`)

3. **Permessi:** Gli utenti possono accedere solo ai propri documenti o quelli condivisi con loro.

4. **Quote:** Se le quote sono abilitate, verificare disponibilità CPU/GPU/Storage prima di operazioni pesanti.

5. **Identificatori:**
   - `pk` = Primary Key (ID numerico)
   - `slug` = Identificatore testuale URL-friendly (solo per progetti)
