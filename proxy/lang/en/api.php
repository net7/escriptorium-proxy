<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Translations - English
    |--------------------------------------------------------------------------
    |
    | API documentation and response messages translations.
    |
    */

    'responses' => [
        'success' => 'Operation completed successfully',
        'error' => 'An error occurred',
        'validation_failed' => 'Validation error',
        'not_found' => 'Resource not found',
        'unauthorized' => 'Unauthorized',
    ],

    'process' => [
        'started' => 'Process started successfully',
        'failed' => 'Unable to start process',
        'not_found' => 'Transcription not found',
    ],

    'models' => [
        'list_success' => 'Models list retrieved successfully',
        'list_error' => 'Error retrieving models',
    ],

    'scripts' => [
        'list_success' => 'Scripts list retrieved successfully',
        'list_error' => 'Error retrieving scripts',
    ],

    'validation' => [
        'script_id' => [
            'required' => 'The script_id field is required.',
            'integer' => 'The script_id field must be an integer.',
        ],
        'manifest_url' => [
            'required' => 'The manifest_url field is required.',
            'url' => 'The manifest_url field must be a valid URL.',
        ],
        'images' => [
            'required' => 'At least one image is required.',
            'array' => 'The images field must be an array.',
            'min' => 'At least one image is required.',
            'image' => 'All files must be valid images (JPEG, PNG, TIFF).',
            'max' => 'Each image cannot exceed 20MB.',
        ],
        'recognition_model_id' => [
            'required' => 'The recognition_model_id field is required.',
            'integer' => 'The recognition_model_id field must be an integer.',
        ],
        'text_direction' => [
            'required' => 'The text_direction field is required.',
            'in' => 'The text_direction field must be one of: horizontal-lr, horizontal-rl, vertical-lr, vertical-rl.',
        ],
    ],

    'body_parameters' => [
        'script_id' => 'Writing system ID. Get from `GET /v1/scripts`.',
        'manifest_url' => 'Full IIIF Manifest URL (v2 or v3). Must be publicly accessible.',
        'pages' => 'Pages selection. Supports ranges (`1-5`), lists (`1,3,5`), or combinations (`1-3,7,10-12`). Defaults to first 10 pages.',
        'recognition_model_id' => 'HTR/OCR model ID for text recognition. Get from `GET /v1/models` filtering by `job=recognize`.',
        'segmentation_model_id' => 'Segmentation model ID for layout analysis. Get from `GET /v1/models` filtering by `job=segment`. Uses system default if omitted.',
        'text_direction' => 'Text reading direction: `horizontal-lr` (Latin), `horizontal-rl` (Arabic, Hebrew), `vertical-lr`, `vertical-rl` (CJK).',
        'document_id' => 'Existing eScriptorium document ID (pk). Only works with eScriptorium API Key (Direct Mode). Images/manifest will be added to this document. If omitted, a new document is created.',
        'images' => 'Array of image files to transcribe. Supported formats: JPEG, PNG, TIFF. Limit: 20MB per file.',
    ],
];
