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
            'in' => 'The text_direction field must be one of: horizontal-lr, horizontal-rl, vertical-lr, vertical-rl, ttb.',
        ],
    ],

    'body_parameters' => [
        'script_id' => 'ID of the writing system used in the document (e.g., Latin, Arabic, Hebrew, Greek). Required to correctly configure text recognition. Retrieve the list of available scripts with `GET /api/v1/scripts`.',
        'manifest_url' => 'Complete URL of a IIIF Manifest (v2 only, v3 not supported). The manifest must be publicly accessible without authentication. Example: `https://digi.vatlib.it/iiif/MSS_Vat.lat.3225/manifest.json`.',
        'pages' => 'Selection of pages to process from the manifest. Supports: single pages (`1,3,5`), ranges (`1-10`), or combinations (`1-3,7,10-12`). Page numbering starts at 1. If omitted, processes the first 10 pages by default.',
        'recognition_model_id' => 'ID of the HTR (Handwritten Text Recognition) or OCR model to use for text transcription. Retrieve available models with `GET /api/v1/models` and filter by `job=recognize`. The model must be compatible with the document\'s writing system.',
        'segmentation_model_id' => 'ID of the segmentation model for page layout analysis (detecting text lines, regions, paragraphs). Retrieve available models with `GET /api/v1/models` and filter by `job=segment`. If omitted, uses eScriptorium\'s default segmentation (blla.mlmodel).',
        'text_direction' => 'Reading direction of the text in the document. Values: `horizontal-lr` (left-to-right: Latin, Cyrillic, Greek), `horizontal-rl` (right-to-left: Arabic, Hebrew), `vertical-lr` (top-to-bottom, columns left-to-right), `vertical-rl` (top-to-bottom, columns right-to-left: traditional CJK), `ttb` (top-to-bottom). **Required unless `document_id` is provided** (in which case it is automatically retrieved from the document\'s main_script text_direction setting).',
        'document_id' => 'ID (pk) of an existing document on eScriptorium. **Only works with eScriptorium API Key (Direct Mode)**. When provided, images/manifest will be added to this existing document instead of creating a new one. Useful for adding pages to an existing document. **Note: when using document_id, the fields `script_id` and `text_direction` are ignored/auto-filled from the existing document metadata.** If omitted or if using a Service API Key, a new temporary document is created. Returns 404 if the document does not exist, 403 if not accessible.',
        'images' => 'Array of image files to transcribe. Supported formats: JPEG, PNG, TIFF, BMP, GIF. Maximum size: 20MB per file. Images are processed in the order they are uploaded. For best results, use high-resolution scans (300 DPI or higher).',
    ],
];
