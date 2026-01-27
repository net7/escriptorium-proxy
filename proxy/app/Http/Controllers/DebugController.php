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
                        ['name' => 'script_id', 'dataType' => 'number', 'required' => true, 'description' => 'Writing system ID from GET /scripts (e.g., Latin, Arabic)'],
                        ['name' => 'manifest_url', 'type' => 'text', 'required' => true, 'placeholder' => 'https://domain.com/manifest.json', 'description' => 'IIIF Manifest URL (v2 only). Must be publicly accessible'],
                        ['name' => 'recognition_model_id', 'dataType' => 'number', 'required' => true, 'description' => 'HTR/OCR model ID from GET /models (job=recognize)'],
                        ['name' => 'text_direction', 'type' => 'select', 'required' => true, 'options' => ['horizontal-lr', 'horizontal-rl', 'vertical-lr', 'vertical-rl'], 'description' => 'horizontal-lr: Latin | horizontal-rl: Arabic/Hebrew | vertical-*: CJK'],
                        ['name' => 'pages', 'type' => 'text', 'required' => false, 'placeholder' => '1-10 or 1,3,5', 'description' => 'Pages to process. Default: first 10 pages'],
                        ['name' => 'segmentation_model_id', 'dataType' => 'number', 'required' => false, 'description' => 'Layout model ID from GET /models (job=segment). Default: blla.mlmodel'],
                        ['name' => 'document_id', 'dataType' => 'number', 'required' => false, 'description' => 'Existing document ID to add pages to (Direct Mode only). If omitted, creates new document'],
                    ],
                ],
                [
                    'method' => 'POST',
                    'path' => '/api/v1/process/images',
                    'description' => 'Start OCR transcription from uploaded image files',
                    'contentType' => 'multipart/form-data',
                    'autoPolling' => true,
                    'fields' => [
                        ['name' => 'script_id', 'dataType' => 'number', 'required' => true, 'description' => 'Writing system ID from GET /scripts (e.g., Latin, Arabic)'],
                        ['name' => 'images', 'type' => 'file', 'required' => true, 'multiple' => true, 'accept' => 'image/*', 'description' => 'Image files (JPEG, PNG, TIFF). Max 20MB each. 300+ DPI recommended'],
                        ['name' => 'recognition_model_id', 'dataType' => 'number', 'required' => true, 'description' => 'HTR/OCR model ID from GET /models (job=recognize)'],
                        ['name' => 'text_direction', 'type' => 'select', 'required' => true, 'options' => ['horizontal-lr', 'horizontal-rl', 'vertical-lr', 'vertical-rl'], 'description' => 'horizontal-lr: Latin | horizontal-rl: Arabic/Hebrew | vertical-*: CJK'],
                        ['name' => 'segmentation_model_id', 'dataType' => 'number', 'required' => false, 'description' => 'Layout model ID from GET /models (job=segment). Default: blla.mlmodel'],
                        ['name' => 'document_id', 'dataType' => 'number', 'required' => false, 'description' => 'Existing document ID to add images to (Direct Mode only). If omitted, creates new document'],
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
