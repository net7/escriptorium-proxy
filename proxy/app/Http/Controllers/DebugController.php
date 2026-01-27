<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DebugController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Debug', [
            'endpoints' => [
                [
                    'method' => 'GET',
                    'path' => '/api/v1/up',
                    'description' => 'Health check endpoint - returns 204 if the service is running',
                    'fields' => [],
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/v1/models',
                    'description' => 'List all available OCR recognition and segmentation models',
                    'contentType' => 'application/json',
                    'fields' => [],
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/v1/scripts',
                    'description' => 'List all available writing scripts (Latin, Arabic, Hebrew, etc.)',
                    'contentType' => 'application/json',
                    'fields' => [],
                ],
                [
                    'method' => 'POST',
                    'path' => '/api/v1/process/manifest',
                    'description' => 'Start OCR transcription from a IIIF manifest URL',
                    'contentType' => 'application/json',
                    'autoPolling' => true,
                    'fields' => [
                        ['name' => 'script_id', 'dataType' => 'number', 'required' => true],
                        ['name' => 'manifest_url', 'type' => 'text', 'required' => true, 'placeholder' => 'https://domain.com/manifest.json'],
                        ['name' => 'recognition_model_id', 'dataType' => 'number', 'required' => true],
                        ['name' => 'text_direction', 'type' => 'select', 'required' => true, 'options' => ['horizontal-lr', 'horizontal-rl', 'vertical-lr', 'vertical-rl']],
                        ['name' => 'pages', 'type' => 'text', 'required' => false, 'placeholder' => '1-10 or 1,3,5'],
                        ['name' => 'segmentation_model_id', 'dataType' => 'number', 'required' => false],
                        ['name' => 'document_id', 'dataType' => 'number', 'required' => false, 'description' => 'Existing eScriptorium document ID (Direct Mode only)'],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/api/v1/process/images',
                    'description' => 'Start OCR transcription from uploaded image files',
                    'contentType' => 'multipart/form-data',
                    'autoPolling' => true,
                    'fields' => [
                        ['name' => 'script_id', 'dataType' => 'number', 'required' => true],
                        ['name' => 'images', 'type' => 'file', 'required' => true, 'multiple' => true, 'accept' => 'image/*'],
                        ['name' => 'recognition_model_id', 'dataType' => 'number', 'required' => true],
                        ['name' => 'text_direction', 'type' => 'select', 'required' => true, 'options' => ['horizontal-lr', 'horizontal-rl', 'vertical-lr', 'vertical-rl']],
                        ['name' => 'segmentation_model_id', 'dataType' => 'number', 'required' => false],
                        ['name' => 'document_id', 'dataType' => 'number', 'required' => false, 'description' => 'Existing eScriptorium document ID (Direct Mode only)'],
                    ],
                ],
                [
                    'method' => 'GET',
                    'path' => '/api/v1/process/{id}',
                    'description' => 'Get the current status and result of a transcription process',
                    'contentType' => 'application/json',
                    'fields' => [
                        ['name' => 'id', 'type' => 'text', 'required' => true, 'placeholder' => 'Transcription UUID', 'pathParam' => true],
                    ],
                ],
            ],
        ]);
    }
}
