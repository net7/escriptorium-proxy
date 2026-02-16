<?php

return [
    'api' => [
        'base_url' => env('ESCRIPTORIUM_URL', 'http://localhost:8000'),
        'auth' => [
            'username' => env('ESCRIPTORIUM_USERNAME', 'admin'),
            'password' => env('ESCRIPTORIUM_PASSWORD', 'admin'),
        ],
        'endpoints' => [
            'token_auth' => env('ESCRIPTORIUM_TOKEN_AUTH_ENDPOINT', 'api/token-auth/'),
            'models' => env('ESCRIPTORIUM_MODELS_ENDPOINT', 'api/models/'),
            'scripts' => env('ESCRIPTORIUM_SCRIPTS_ENDPOINT', 'api/scripts/'),
            'projects' => env('ESCRIPTORIUM_PROJECTS_ENDPOINT', 'api/projects/'),
            'documents' => env('ESCRIPTORIUM_DOCUMENTS_ENDPOINT', 'api/documents/'),
            'import' => env('ESCRIPTORIUM_IMPORT_ENDPOINT', 'api/documents/{document_id}/import/'),
            'tasks' => env('ESCRIPTORIUM_TASKS_ENDPOINT', 'api/tasks/'),
            'parts' => env('ESCRIPTORIUM_PARTS_ENDPOINT', 'api/documents/{document_id}/parts/'),
            'segment' => env('ESCRIPTORIUM_SEGMENT_ENDPOINT', 'api/documents/{document_id}/segment/'),
            'transcribe' => env('ESCRIPTORIUM_TRANSCRIBE_ENDPOINT', 'api/documents/{document_id}/transcribe/'),
            'transcriptions' => env('ESCRIPTORIUM_TRANSCRIPTIONS_ENDPOINT', 'api/documents/{document_id}/transcriptions/'),
            'tei_export' => env('ESCRIPTORIUM_TEI_EXPORT_ENDPOINT', 'api/documents/{document_id}/transcriptions/{transcription_id}/tei/'),
            'export' => env('ESCRIPTORIUM_EXPORT_ENDPOINT', 'api/documents/{document_id}/export/'),
        ],
        'headers' => [
            'token_header' => env('ESCRIPTORIUM_TOKEN_HEADER', 'Token'),
        ],
    ],
    'cache' => [
        'ttl' => env('ESCRIPTORIUM_CACHE_TTL', 12),
        'prefix' => env('ESCRIPTORIUM_CACHE_PREFIX', '_escriptorium_'),
    ],
    'polling' => [
        'interval' => env('ESCRIPTORIUM_POLLING_INTERVAL', 30),
        'tries' => env('ESCRIPTORIUM_POLLING_TRIES', 3),
        'backoff' => [10, 30, 60],
        'max_attempts' => [
            'import' => env('ESCRIPTORIUM_POLLING_MAX_ATTEMPTS_IMPORT', 120),
            'segment' => env('ESCRIPTORIUM_POLLING_MAX_ATTEMPTS_SEGMENT', 240),
            'transcribe' => env('ESCRIPTORIUM_POLLING_MAX_ATTEMPTS_TRANSCRIBE', 360),
        ],
    ],
    'websocket' => [
        'base_url' => env('ESCRIPTORIUM_WEBSOCKET_BASE_URL', null), // If null, uses api.base_url
        'endpoint' => env('ESCRIPTORIUM_WEBSOCKET_ENDPOINT', 'ws/notif/'),
        'timeout' => env('ESCRIPTORIUM_WEBSOCKET_TIMEOUT', 60),
    ],
    'media' => [
        'base_url' => env('ESCRIPTORIUM_MEDIA_BASE_URL', null), // If null, uses websocket.base_url or api.base_url
    ],
    'django' => [
        'secret_key' => env('ESCRIPTORIUM_DJANGO_SECRET_KEY', 'changeme'),
    ],
];
