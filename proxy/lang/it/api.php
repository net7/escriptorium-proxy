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
            'in' => 'Il campo text_direction deve essere uno tra: horizontal-lr, horizontal-rl, vertical-lr, vertical-rl.',
        ],
    ],

    'body_parameters' => [
        'script_id' => 'ID del sistema di scrittura del documento. Ottenibile da `GET /v1/scripts`.',
        'manifest_url' => 'URL completo del Manifest IIIF (v2 o v3). Deve essere pubblicamente accessibile.',
        'pages' => 'Selezione pagine da elaborare. Supporta range (`1-5`), liste (`1,3,5`), o combinazioni (`1-3,7,10-12`). Se omesso, elabora le prime 10 pagine.',
        'recognition_model_id' => 'ID del modello HTR/OCR per il riconoscimento testo. Ottenibile da `GET /v1/models` filtrando per `job=recognize`.',
        'segmentation_model_id' => 'ID del modello di segmentazione per l\'analisi layout. Ottenibile da `GET /v1/models` filtrando per `job=segment`. Se omesso, usa il default del sistema.',
        'text_direction' => 'Direzione di lettura del testo: `horizontal-lr` (Latino, Italiano), `horizontal-rl` (Arabo, Ebraico), `vertical-lr`, `vertical-rl` (CJK).',
        'document_id' => 'ID (pk) di un documento esistente su eScriptorium. Funziona solo con API Key eScriptorium (Direct Mode). Le immagini/manifest verranno aggiunte a questo documento. Se omesso, viene creato un nuovo documento.',
        'images' => 'Array di file immagine da trascrivere. Formati supportati: JPEG, PNG, TIFF. Limite: 20MB per file.',
    ],
];
