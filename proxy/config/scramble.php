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

API RESTful per la trascrizione automatica OCR/HTR di manoscritti storici tramite [eScriptorium](https://escriptorium.fr/).

---

## Caratteristiche

| Feature | Descrizione |
|---------|-------------|
| **IIIF Support** | Importa documenti direttamente da Manifest IIIF |
| **Upload Diretto** | Carica immagini raw (JPEG, PNG, TIFF) |
| **Modelli AI** | Selezione dinamica di modelli OCR e segmentazione |
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
  "text_direction": "horizontal-lr"
}

# Da Upload Immagini
POST /v1/process/images  (multipart/form-data)
```

### 3. Monitora e Recupera Risultato
```bash
GET /v1/process/{id}
# Polling fino a status = "COMPLETED"
```

---

## Stati del Processo

| Stato | Descrizione |
|-------|-------------|
| `PENDING` | In coda di elaborazione |
| `IMPORTING` | Download/upload immagini in corso |
| `SEGMENTING` | Analisi layout delle pagine |
| `TRANSCRIBING` | Riconoscimento testo OCR/HTR |
| `DOWNLOADING` | Recupero risultati da eScriptorium |
| `PROCESSING` | Elaborazione finale TEI |
| `COMPLETED` | Testo disponibile nel campo `text` |
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
