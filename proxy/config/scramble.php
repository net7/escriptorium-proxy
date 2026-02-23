<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Your API path. By default, all routes starting with this path will be added to the docs.
     * If you need to change this behavior, you can add your custom routes resolver using `Scramble::routes()`.
     */
    'api_path' => 'api',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    'info' => [
        /*
         * API version.
         */
        'version' => env('API_VERSION', '1.0.0'),

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => <<<'DESC'
# eScriptorium Proxy API

Questo proxy semplifica radicalmente l'utilizzo di [eScriptorium](https://escriptorium.fr/) per la trascrizione OCR/HTR di documenti storici.

Con le API native di eScriptorium servono **decine di chiamate** coordinate (creazione progetto, documento, import immagini, segmentazione, trascrizione, export, download, cleanup). Il proxy riduce tutto a **2-3 chiamate REST**:

1. **Avvia** il processo con un singolo `POST` (manifest IIIF o upload immagini)
2. **Monitora** lo stato con polling `GET`
3. **Scarica** il risultato nel formato desiderato

Il proxy gestisce in autonomia l'intera pipeline asincrona: creazione risorse, polling dei task, connessione WebSocket per l'export, merge dei risultati e cleanup finale.

---

## Caratteristiche

| Feature | Descrizione |
|---------|-------------|
| **2-3 chiamate** | Un intero workflow OCR in sole 2-3 chiamate REST invece di decine |
| **IIIF Support** | Importa documenti direttamente da Manifest IIIF (v2) |
| **Upload Diretto** | Carica immagini raw (JPEG, PNG, TIFF, fino a 20MB) |
| **Multi-Formato** | Export in TEI XML, Plain Text, PAGE XML, ALTO XML, OpenITI mARkdown |
| **Modelli AI** | Selezione dinamica di modelli OCR/HTR e segmentazione |
| **Dual Auth** | API Key Proxy (temporaneo) o API Key eScriptorium (persistente) |
| **Asincrono** | Pipeline non bloccante con polling dello stato |

---

## Quick Start

### 1. Ottieni Script e Modelli
```bash
GET /v1/scripts    # Lista sistemi di scrittura
GET /v1/models     # Lista modelli OCR disponibili
```

### 2. Avvia Trascrizione
```bash
# Da Manifest IIIF
POST /v1/process/manifest
{
  "script_id": 1,
  "manifest_url": "https://example.com/iiif/manifest.json",
  "recognition_model_id": 142,
  "text_direction": "horizontal-lr",
  "export_format": "teixml"
}

# Da Upload Immagini
POST /v1/process/images  (multipart/form-data)
```

### 3. Monitora e Recupera Risultato
```bash
GET /v1/process/{id}
# Polling fino a status = "COMPLETED"
# → Risposta include: text, export_format, download_url
```

### 4. (Opzionale) Scarica il File Export
```bash
GET /v1/process/{id}/download
# → File ZIP o TXT a seconda del formato scelto
```

---

## Formati di Export

| Formato | Campo `text` | Download | Descrizione |
|---------|-------------|----------|-------------|
| `teixml` (default) | TEI XML completo | ZIP con XML per pagina | Standard TEI, merge automatico di tutte le pagine |
| `text` | Testo piano | File TXT | Contenuto testuale estratto |
| `pagexml` | Vuoto | ZIP con PAGE XML | Standard per HTR, un file XML per pagina |
| `alto` | Vuoto | ZIP con ALTO XML | Standard per OCR, un file XML per pagina |
| `openitimarkdown` | Vuoto | ZIP con .mARkdown | Formato OpenITI per testi orientali |

---

## Stati del Processo

| Stato | Descrizione |
|-------|-------------|
| `PENDING` | In coda di elaborazione |
| `IMPORTING` | Download/upload immagini in corso |
| `SEGMENTING` | Analisi layout delle pagine |
| `TRANSCRIBING` | Riconoscimento testo OCR/HTR |
| `DOWNLOADING` | Recupero risultati da eScriptorium |
| `PROCESSING` | Elaborazione finale |
| `COMPLETED` | Risultato disponibile |
| `FAILED` | Errore durante l'elaborazione |

---

## Autenticazione

Tutte le richieste richiedono l'header `X-API-Key`.

| Tipo | Comportamento |
|------|---------------|
| **API Key Proxy** | Progetti temporanei, auto-eliminati al termine dell'elaborazione |
| **API Key eScriptorium** | Progetti persistenti **sul tuo account eScriptorium personale** |

> **Nota**: Con API Key eScriptorium puoi accedere ai progetti creati direttamente dalla piattaforma eScriptorium, modificarli manualmente e riutilizzarli in future richieste.
DESC,
    ],

    /*
     * The list of servers of the API. By default (when `null`), server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     */
    'servers' => null,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],
];
