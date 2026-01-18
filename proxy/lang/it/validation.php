<?php

return [
    'pages_range' => [
        'invalid_characters' => 'Il campo :attribute può contenere solo numeri, virgole e trattini.',
        'consecutive_separators' => 'Il campo :attribute non può contenere virgole o trattini consecutivi.',
        'invalid_start_end' => 'Il campo :attribute non può iniziare o terminare con virgola o trattino.',
        'empty_segment' => 'Il campo :attribute contiene segmenti vuoti.',
        'invalid_range' => 'Il campo :attribute contiene un intervallo non valido: :segment.',
        'range_order' => 'Nel campo :attribute, l\'intervallo :segment non è valido: il secondo numero deve essere maggiore del primo.',
        'duplicate_pages' => 'Il campo :attribute contiene pagine duplicate o sovrapposte.',
    ],
    'escriptorium' => [
        'new_model' => [
            'failed' => 'Impossibile creare il modello',
            'already_exists' => 'Il modello già esiste, si prega di scegliere un nome diverso',
        ],
        'process' => [
            'validation_failed' => 'Validazione fallita',
            'request_failed' => 'Richiesta fallita',
            'script_name' => [
                'required' => 'Il campo script name è obbligatorio.',
                'string' => 'Lo script deve essere una stringa.',
                'in' => 'Lo script name selezionato non è valido.',
            ],
            'pages' => [
                'nullable' => 'Il campo pagine è opzionale.',
                'string' => 'Le pagine devono essere una stringa.',
            ],
            'manifest_url' => [
                'required' => 'Il campo manifest URL è obbligatorio.',
                'string' => 'Il manifest URL deve essere una stringa.',
                'url' => 'Il manifest URL deve essere un URL valido.',
            ],
            'recognition_model_id' => [
                'required' => 'Il campo modello di riconoscimento è obbligatorio.',
                'integer' => 'Il modello di riconoscimento deve essere un numero intero.',
                'in' => 'Il modello di riconoscimento selezionato non è valido.',
            ],
            'segmentation_model_id' => [
                'nullable' => 'Il campo modello di segmentazione è opzionale.',
                'integer' => 'Il modello di segmentazione deve essere un numero intero.',
                'in' => 'Il modello di segmentazione selezionato non è valido.',
            ],
            'text_direction' => [
                'required' => 'Il campo direzione del testo è obbligatorio.',
                'string' => 'La direzione del testo deve essere una stringa.',
                'in' => 'La direzione del testo deve essere una tra: horizontal-lr, horizontal-rl, vertical-lr, vertical-rl o ttb.',
            ],
        ],
        'status' => [
            'not_found' => 'Trascrizione non trovata o non autorizzata.',
        ],
        'file' => [
            'types' => 'Il file deve essere un file di tipo :values.',
            'min' => 'Il file deve essere almeno :min kb.',
        ],
    ],
];
