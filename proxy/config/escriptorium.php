<?php

return [
    'api' => [
        'base_url' => env('ESCRIPTORIUM_URL', 'http://localhost:8000'),
        'auth' => [
            'username' => env('ESCRITORIUM_USERNAME', 'admin'),
            'password' => env('ESCRITORIUM_PASSWORD', 'admin'),
        ],
        'endpoints' => [
            'token_auth' => env('ESCRITORIUM_TOKEN_AUTH_ENDPOINT', 'api/token-auth/'),
            'models' => env('ESCRITORIUM_MODELS_ENDPOINT', 'api/models/'),
            'scripts' => env('ESCRITORIUM_SCRIPTS_ENDPOINT', 'api/scripts/'),
            'projects' => env('ESCRITORIUM_PROJECTS_ENDPOINT', 'api/projects/'),
            'documents' => env('ESCRITORIUM_DOCUMENTS_ENDPOINT', 'api/documents/'),
            'import' => env('ESCRITORIUM_IMPORT_ENDPOINT', 'api/documents/{document_id}/import/'),
            'tasks' => env('ESCRITORIUM_TASKS_ENDPOINT', 'api/tasks/'),
            'parts' => env('ESCRITORIUM_PARTS_ENDPOINT', 'api/documents/{document_id}/parts/'),
            'segment' => env('ESCRITORIUM_SEGMENT_ENDPOINT', 'api/documents/{document_id}/segment/'),
            'transcribe' => env('ESCRITORIUM_TRANSCRIBE_ENDPOINT', 'api/documents/{document_id}/transcribe/'),
            'transcriptions' => env('ESCRITORIUM_TRANSCRIPTIONS_ENDPOINT', 'api/documents/{document_id}/transcriptions/'),
            'tei_export' => env('ESCRITORIUM_TEI_EXPORT_ENDPOINT', 'api/documents/{document_id}/transcriptions/{transcription_id}/tei/'),
            'export' => env('ESCRITORIUM_EXPORT_ENDPOINT', 'api/documents/{document_id}/export/'),
        ],
        'headers' => [
            'token_header' => env('ESCRITORIUM_TOKEN_HEADER', 'Token'),
        ],
    ],
    'cache' => [
        'ttl' => env('ESCRITORIUM_CACHE_TTL', 12),
        'prefix' => env('ESCRITORIUM_CACHE_PREFIX', '_escriptorium_'),
    ],
    'polling' => [
        'interval' => env('ESCRITORIUM_POLLING_INTERVAL', 30),
        'tries' => env('ESCRITORIUM_POLLING_TRIES', 3),
        'backoff' => [10, 30, 60],
        'max_attempts' => [
            'import' => env('ESCRITORIUM_POLLING_MAX_ATTEMPTS_IMPORT', 120),
            'segment' => env('ESCRITORIUM_POLLING_MAX_ATTEMPTS_SEGMENT', 240),
            'transcribe' => env('ESCRITORIUM_POLLING_MAX_ATTEMPTS_TRANSCRIBE', 360),
        ],
    ],
    'websocket' => [
        'base_url' => env('ESCRITORIUM_WEBSOCKET_BASE_URL', null), // If null, uses api.base_url
        'endpoint' => env('ESCRITORIUM_WEBSOCKET_ENDPOINT', 'ws/notif/'),
        'timeout' => env('ESCRITORIUM_WEBSOCKET_TIMEOUT', 600),
    ],
];
