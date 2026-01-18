<?php

return [
    'pages_range' => [
        'invalid_characters' => 'The :attribute may only contain numbers, commas, and hyphens.',
        'consecutive_separators' => 'The :attribute cannot contain consecutive commas or hyphens.',
        'invalid_start_end' => 'The :attribute cannot start or end with a comma or hyphen.',
        'empty_segment' => 'The :attribute contains empty segments.',
        'invalid_range' => 'The :attribute contains an invalid range: :segment.',
        'range_order' => 'In the :attribute, the range :segment is invalid: the second number must be greater than the first.',
        'duplicate_pages' => 'The :attribute contains duplicate or overlapping pages.',
    ],
    'escriptorium' => [
        'new_model' => [
            'failed' => 'Failed to create model',
            'already_exists' => 'Model already exists, please choose a different name',
        ],
        'process' => [
            'validation_failed' => 'Validation failed',
            'request_failed' => 'Request failed',
            'script_name' => [
                'required' => 'The script name field is required.',
                'string' => 'The script must be a string.',
                'in' => 'The selected script name is invalid.',
            ],
            'pages' => [
                'nullable' => 'The pages field is optional.',
                'string' => 'The pages must be a string.',
            ],
            'manifest_url' => [
                'required' => 'The manifest URL field is required.',
                'string' => 'The manifest URL must be a string.',
                'url' => 'The manifest URL must be a valid URL.',
            ],
            'recognition_model_id' => [
                'required' => 'The recognition model field is required.',
                'integer' => 'The recognition model must be an integer.',
                'in' => 'The selected recognition model is invalid.',
            ],
            'segmentation_model_id' => [
                'nullable' => 'The segmentation model field is optional.',
                'integer' => 'The segmentation model must be an integer.',
                'in' => 'The selected segmentation model is invalid.',
            ],
            'text_direction' => [
                'required' => 'The text direction field is required.',
                'string' => 'The text direction must be a string.',
                'in' => 'The text direction must be one of: horizontal-lr, horizontal-rl, vertical-lr, vertical-rl, or ttb.',
            ],
        ],
        'status' => [
            'not_found' => 'Transcription not found or not authorized.',
        ],
        'file' => [
            'types' => 'The file must be a file with type :values.',
            'min' => 'The file must be at least :min kb.',
        ],
    ],
];
