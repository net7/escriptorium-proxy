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
# eScriptorium Proxy API 🚀

Benvenuto nella documentazione ufficiale. Questa API semplifica radicalmente l'interazione con eScriptorium, offrendo un'interfaccia **stateless** e **user-friendly** per la trascrizione automatica di manoscritti.

---

### ✨ Caratteristiche Principali

*   🔥 **Processo One-Shot**: Da Manifest IIIF a XML TEI in una singola chiamata.
*   🖼️ **Upload Diretto**: Supporto per caricamento immagini raw (JPEG, PNG).
*   🧠 **Modelli AI**: Selezione dinamica dei modelli OCR e di segmentazione.
*   🔒 **Dual Auth**: Supporto per API Key Proxy o API Key Escriptorium.
*   📦 **Polling Automatico**: Monitoraggio intelligente dei task asincroni.

---

### 🛠 Workflow Tipico

1.  **Avvio Processo**: `POST /v1/process` (con Manifest URL o Immagini)
2.  **Monitoraggio**: `GET /v1/process/{id}` per seguire lo stato (Importing → Segmenting → Transcribing).
3.  **Risultato**: Quando lo stato è `COMPLETED`, ottieni il testo trascritto.

---

> _Powered by eScriptorium & Laravel_
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
