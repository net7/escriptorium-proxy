<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Translations - Italian
    |--------------------------------------------------------------------------
    |
    | Traduzioni per la documentazione API e messaggi di risposta.
    |
    */

    'responses' => [
        'success' => 'Operazione completata con successo',
        'error' => 'Si è verificato un errore',
        'validation_failed' => 'Errore di validazione',
        'not_found' => 'Risorsa non trovata',
        'unauthorized' => 'Non autorizzato',
    ],

    'process' => [
        'started' => 'Processo avviato con successo',
        'failed' => 'Impossibile avviare il processo',
        'not_found' => 'Trascrizione non trovata',
    ],

    'models' => [
        'list_success' => 'Elenco modelli recuperato con successo',
        'list_error' => 'Errore nel recupero dei modelli',
    ],

    'scripts' => [
        'list_success' => 'Elenco script recuperato con successo',
        'list_error' => 'Errore nel recupero degli script',
    ],

    'validation' => [
        'script_id' => [
            'required' => 'Il campo script_id è obbligatorio.',
            'integer' => 'Il campo script_id deve essere un numero intero.',
        ],
        'manifest_url' => [
            'required' => 'Il campo manifest_url è obbligatorio.',
            'url' => 'Il campo manifest_url deve essere un URL valido.',
        ],
        'images' => [
            'required' => 'È necessario caricare almeno un\'immagine.',
            'array' => 'Il campo images deve essere un array.',
            'min' => 'È necessario caricare almeno un\'immagine.',
            'image' => 'Tutti i file devono essere immagini valide (JPEG, PNG, TIFF).',
            'max' => 'Ogni immagine non può superare i 20MB.',
        ],
        'recognition_model_id' => [
            'required' => 'Il campo recognition_model_id è obbligatorio.',
            'integer' => 'Il campo recognition_model_id deve essere un numero intero.',
        ],
        'text_direction' => [
            'required' => 'Il campo text_direction è obbligatorio.',
            'in' => 'Il campo text_direction deve essere uno tra: horizontal-lr, horizontal-rl, vertical-lr, vertical-rl, ttb.',
        ],
    ],

    'body_parameters' => [
        'script_id' => 'ID del sistema di scrittura utilizzato nel documento (es. Latino, Arabo, Ebraico, Greco). Necessario per configurare correttamente il riconoscimento del testo. Ottieni la lista degli script disponibili con `GET /api/v1/scripts`.',
        'manifest_url' => 'URL completo di un Manifest IIIF (solo v2, v3 non supportato). Il manifest deve essere pubblicamente accessibile senza autenticazione. Esempio: `https://digi.vatlib.it/iiif/MSS_Vat.lat.3225/manifest.json`.',
        'pages' => 'Selezione delle pagine da elaborare dal manifest. Supporta: pagine singole (`1,3,5`), intervalli (`1-10`), o combinazioni (`1-3,7,10-12`). La numerazione parte da 1. Se omesso, elabora le prime 10 pagine di default.',
        'recognition_model_id' => 'ID del modello HTR (Handwritten Text Recognition) o OCR da utilizzare per la trascrizione del testo. Ottieni i modelli disponibili con `GET /api/v1/models` e filtra per `job=recognize`. Il modello deve essere compatibile con il sistema di scrittura del documento.',
        'segmentation_model_id' => 'ID del modello di segmentazione per l\'analisi del layout della pagina (rilevamento righe di testo, regioni, paragrafi). Ottieni i modelli disponibili con `GET /api/v1/models` e filtra per `job=segment`. Se omesso, usa la segmentazione di default di eScriptorium (blla.mlmodel).',
        'text_direction' => 'Direzione di lettura del testo nel documento. Valori: `horizontal-lr` (sinistra-destra: Latino, Cirillico, Greco), `horizontal-rl` (destra-sinistra: Arabo, Ebraico), `vertical-lr` (alto-basso, colonne sinistra-destra), `vertical-rl` (alto-basso, colonne destra-sinistra: CJK tradizionale), `ttb` (alto-basso). **Obbligatorio a meno che non sia fornito `document_id`** (in tal caso viene recuperato automaticamente dalla impostazione text_direction del main_script del documento).',
        'document_id' => 'ID (pk) di un documento esistente su eScriptorium. **Funziona solo con API Key eScriptorium (Direct Mode)**. Se fornito, le immagini/manifest verranno aggiunte a questo documento esistente invece di crearne uno nuovo. Utile per aggiungere pagine a un documento esistente. **Nota: quando si usa document_id, i campi `script_id` e `text_direction` vengono ignorati/auto-compilati dai metadati del documento esistente.** Se omesso o se si usa una Service API Key, viene creato un nuovo documento temporaneo. Restituisce 404 se il documento non esiste, 403 se non accessibile.',
        'images' => 'Array di file immagine da trascrivere. Formati supportati: JPEG, PNG, TIFF, BMP, GIF. Dimensione massima: 20MB per file. Le immagini vengono elaborate nell\'ordine in cui sono caricate. Per risultati ottimali, usare scansioni ad alta risoluzione (300 DPI o superiore).',
    ],
];
