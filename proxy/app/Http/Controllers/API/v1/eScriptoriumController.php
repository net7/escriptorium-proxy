<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\eScriptoriumStatusEnum;
use App\Enums\ProcessSourceEnum;
use App\Facades\eScriptorium;
use App\Http\Controllers\Controller;
use App\Http\Requests\eScriptorium\NewModelRequest;
use App\Http\Requests\eScriptorium\ProcessRequest;
use App\Jobs\eScriptoriumImportDocumentJob;
use App\Jobs\eScriptoriumUploadImagesJob;
use App\Models\Transcription;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * API per la gestione delle trascrizioni OCR tramite eScriptorium.
 *
 * Questa API permette di:
 * - Verificare lo stato del servizio
 * - Ottenere l'elenco dei modelli OCR disponibili
 * - Avviare processi di trascrizione automatica
 * - Monitorare lo stato delle trascrizioni
 * - Recuperare i risultati in formato TEI
 *
 * ## Modalità di Autenticazione
 *
 * L'API supporta due tipi di autenticazione tramite header `X-API-Key`:
 *
 * ### 1. API Key Proxy (Default)
 * Usa una API key generata dal proxy. Il progetto creato su eScriptorium
 * verrà **eliminato automaticamente** al termine del processo.
 *
 * ### 2. API Key Escriptorium
 * Usa direttamente un token API di eScriptorium. In questa modalità:
 * - Il progetto viene creato nel tuo account eScriptorium
 * - Il progetto **NON viene eliminato** al termine del processo
 * - Puoi accedere al progetto direttamente su eScriptorium
 */
#[Group('eScriptorium', description: 'API per trascrizione OCR automatica di manoscritti. Supporta autenticazione con API key Proxy o API key Escriptorium (progetto persistente).')]
class eScriptoriumController extends Controller
{
    /**
     * Health Check
     *
     * Verifica la disponibilità del servizio eScriptorium.
     * Restituisce **204 No Content** se il servizio è operativo, oppure **503 Service Unavailable** se non è raggiungibile.
     */
    #[Endpoint(operationId: 'healthCheck', title: 'Verifica stato servizio')]
    #[Response(204, description: 'Il servizio è operativo (nessun contenuto)')]
    #[Response(503, description: 'Il servizio non è disponibile')]
    public function up(): HttpResponse
    {
        try {
            $up = eScriptorium::isUp();
        } catch (\RuntimeException $e) {
            return response()->noContent(503);
        }

        return response()->noContent($up ? 200 : 503);
    }

    /**
     * List OCR Models
     *
     * Restituisce l'elenco dei modelli di segmentazione e riconoscimento disponibili per l'utente corrente.
     *
     * - **ID**: Identificativo univoco del modello (da usare in `recognition_model_id` o `segmentation_model_id`).
     * - **Job**: Tipo di modello (`Segment` o `Recognize`).
     * - **Accuracy**: Percentuale di accuratezza del modello (se disponibile).
     *
     * > **Nota**: Se autenticato con API Key Escriptorium, vedi i modelli del tuo account. Altrimenti, vedi i modelli globali/di servizio.
     */
    #[Endpoint(operationId: 'listModels', title: 'Elenco modelli OCR')]
    #[Response(200, description: 'Elenco modelli recuperato con successo', type: 'array{results: array<array{id: int, name: string, accuracy_percent: string|null, job: string}>, count: int, status: int}')]
    #[Response(500, description: 'Errore di comunicazione con eScriptorium', type: 'array{results: array, status: int, message: string}')]
    public function models(): JsonResponse
    {
        try {
            $models = eScriptorium::models();
        } catch (\Exception $e) {
            return response()->json([
                'results' => [],
                'status' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }

        $models = array_map(function ($model): array {
            $isAccuracyPercentZero = Number::format($model['accuracy_percent'], 0) === '0';

            return [
                'id' => $model['pk'],
                'name' => $model['name'],
                'accuracy_percent' => $isAccuracyPercentZero ? null : Number::format($model['accuracy_percent'], 1).'%',
                'job' => Str::lower($model['job']),
            ];
        }, $models) ?? [];

        return response()->json([
            'results' => $models,
            'count' => \count($models),
            'status' => 200,
        ]);
    }

    /**
     * List Scripts
     *
     * Restituisce l'elenco dei sistemi di scrittura (Script) supportati da eScriptorium.
     *
     * Ogni script ha un **ID univoco** (`pk`) che deve essere utilizzato nel campo `script_id`
     * quando si avvia un processo di trascrizione.
     */
    #[Endpoint(operationId: 'listScripts', title: 'Elenco sistemi di scrittura')]
    #[Response(200, description: 'Elenco script recuperato con successo', type: 'array{results: array<array{pk: int, name: string}>, count: int, status: int}')]
    #[Response(500, description: 'Errore di comunicazione con eScriptorium', type: 'array{results: array, status: int, message: string}')]
    public function scripts(): JsonResponse
    {
        try {
            $scripts = eScriptorium::scripts();
        } catch (\Exception $e) {
            return response()->json([
                'results' => [],
                'status' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'results' => $scripts,
            'count' => \count($scripts),
            'status' => 200,
        ]);
    }

    /**
     * Upload New Model
     *
     * Carica un file modello `.mlmodel` (compatibile Kraken) su eScriptorium.
     *
     * La richiesta deve essere **multipart/form-data**.
     *
     * @param  NewModelRequest  $request
     *                                    - **name**: Nome univoco per il modello.
     *                                    - **file**: Il file binario (.mlmodel).
     */
    #[Endpoint(operationId: 'newModel', title: 'Carica nuovo modello')]
    #[Response(201, description: 'Modello creato con successo (201 No Content)')]
    #[Response(409, description: 'Esiste già un modello con questo nome')]
    #[Response(500, description: 'Errore durante il caricamento')]
    public function newModel(NewModelRequest $request): HttpResponse|JsonResponse
    {
        $allModels = eScriptorium::models();

        if (\in_array($request->validated('name'), array_column($allModels, 'name'))) {
            return response()->json([
                'message' => __('validation.escriptorium.new_model.already_exists'),
                'status' => 409,
            ], 409);
        }

        try {
            eScriptorium::newModelViaBrowser(
                $request->validated('name'),
                $request->validated('file')
            );
        } catch (\RuntimeException $e) {
            try {
                eScriptorium::newModel(
                    $request->validated('name'),
                    $request->validated('file')
                );
            } catch (\Exception $e) {
                Log::error('❌ [eScriptorium] Model upload failed', ['error' => $e->getMessage()]);

                return response()->json([
                    'message' => __('validation.escriptorium.new_model.failed'),
                    'status' => 500,
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        return response()->noContent(201);
    }

    /**
     * Start OCR Process
     *
     * Avvia un processo completo di trascrizione asincrono.
     *
     * Il flusso di lavoro include:
     * 1. Creazione Progetto e Documento (nomi personalizzabili o casuali).
     * 2. Import immagini da Manifest IIIF.
     * 3. Segmentazione e Riconoscimento testo (OCR).
     * 4. Generazione output TEI XML.
     *
     * ### Modalità di Input (`source_type`)
     * **1. Manifest IIIF (`manifest`)**
     * Richiede `manifest_url`. Importa le immagini da un server esterno.
     *
     * **2. Upload Immagini (`images`)**
     * Richiede `images` (array di file).
     * **Nota**: La richiesta deve essere `multipart/form-data`.
     * Inviare le immagini come array: `images[]=@file1.jpg`, `images[]=@file2.jpg`.
     *
     * ### Parametri Opzionali
     * È possibile specificare nomi personalizzati per le entità create su eScriptorium:
     * - `project_name`: Nome del progetto contenitore
     * - `document_name`: Nome del documento
     * - `transcription_name`: Nome del layer di trascrizione
     *
     * ### Persistenza vs Temporaneo
     * - **API Key Proxy**: Progetto temporaneo (eliminato a fine processo).
     * - **API Key Escriptorium**: Progetto persistente nel tuo account (nomi utili per organizzazione).
     */
    #[Endpoint(operationId: 'startProcess', title: 'Avvia trascrizione OCR')]
    #[Response(201, description: 'Processo avviato correttamente', type: 'array{message: string, transcription_id: string, status: int}')]
    #[Response(422, description: 'Errore di validazione parametri', type: 'array{message: string, errors: array<string, array<string>>, status: int}')]
    #[Response(500, description: 'Errore interno del server', type: 'array{message: string, error: string, status: int}')]
    public function process(ProcessRequest $request): JsonResponse
    {
        $data = $request->validated();
        $apiKey = $request->attributes->get('api_key');
        // Se usa API Key Laravel, is_escriptorium_api_key = true
        // In questo caso il token è quello del service eScriptorium
        // Se usa token diretto, il token è quello dell'utente passato dal middleware.

        // Usage of data_get to avoid Scramble auto-discovery of internal parameters
        $isApiKey = data_get($request, 'is_escriptorium_api_key', false);
        $escriptoriumToken = data_get($request, 'escriptorium_token');

        if (! $escriptoriumToken) {
            // Fallback or Error
        }

        Log::info('🟢 [eScriptorium] Workflow triggered. Starting process...', [
            'data' => $data,
            'api_key_id' => $apiKey->id,
            'is_direct_mode' => $isApiKey,
        ]);

        try {
            $escriptoriumProject = null;
            $escriptoriumDocument = null;

            // Transaction per garantire consistenza
            $transcription = DB::transaction(function () use ($apiKey, $escriptoriumToken, $data, &$escriptoriumProject, &$escriptoriumDocument) {
                $projectName = $data['project_name'] ?? Str::random(16);
                $escriptoriumProject = eScriptorium::createProject($projectName);

                $documentName = $data['document_name'] ?? Str::random(16);
                $escriptoriumDocument = eScriptorium::createDocument(
                    $documentName,
                    $escriptoriumProject['slug'],
                    $data['script_name']
                );

                $serviceData = [
                    'escriptorium' => [
                        'request' => $data,
                        'project' => $escriptoriumProject,
                        'document' => $escriptoriumDocument,
                        'transcription_name' => $data['transcription_name'] ?? null,
                    ],
                ];

                if ($data['source_type'] === ProcessSourceEnum::Images->value) {
                    $imagePaths = [];
                    // Images are UploadedFile objects
                    if (isset($data['images']) && is_array($data['images'])) {
                        foreach ($data['images'] as $image) {
                            // Store in a temporary location for the job to pick up
                            // Using a distinct folder per request to avoid collisions
                            $path = $image->store('transcriptions/pending_uploads');
                            $imagePaths[] = $path;
                        }
                    }
                    $serviceData['escriptorium']['image_paths'] = $imagePaths;

                    // FIX: Override pages/pages_array to match exact number of uploaded images
                    $count = count($imagePaths);
                    if ($count > 0) {
                        $data['pages_array'] = range(1, $count);
                        $data['pages'] = "1-$count";
                        // Update request data in service_data as well to reflect the actual range
                        $serviceData['escriptorium']['request']['pages_array'] = $data['pages_array'];
                        $serviceData['escriptorium']['request']['pages'] = $data['pages'];
                    }
                }

                return Transcription::create([
                    'api_key_id' => $apiKey->id,
                    'escriptorium_token' => $escriptoriumToken,
                    'script_name' => $data['script_name'],
                    'manifest_url' => $data['manifest_url'] ?? null,
                    'pages' => $data['pages'] ?? null,
                    'recognition_model_id' => $data['recognition_model_id'],
                    'segmentation_model_id' => $data['segmentation_model_id'] ?? null,
                    'text_direction' => $data['text_direction'],
                    'status' => eScriptoriumStatusEnum::Pending->value,
                    'service_data' => $serviceData,
                ]);
            });

            if ($data['source_type'] === ProcessSourceEnum::Images->value) {
                dispatch(new eScriptoriumUploadImagesJob($transcription));
            } else {
                dispatch(new eScriptoriumImportDocumentJob($transcription));
            }

            Log::info('🚀 [eScriptorium] Process started', ['transcription_id' => $transcription->id]);

            return response()->json([
                'message' => 'Process started successfully',
                'transcription_id' => $transcription->id,
                'status' => 201,
            ], 201);

        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Process failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => __('validation.escriptorium.process.request_failed'),
                'error' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    /**
     * Get Transcription Details
     *
     * Recupera lo stato corrente e, se completato, il testo trascritto.
     *
     * ### Stati (`status`):
     * - `PENDING`: In coda.
     * - `IMPORTING`: Importazione immagini in corso.
     * - `SEGMENTING`: Analisi layout in corso.
     * - `TRANSCRIBING`: Riconoscimento testo in corso.
     * - `DOWNLOADING`: Recupero risultati da eScriptorium.
     * - `PROCESSING`: Elaborazione finale.
     * - `COMPLETED`: Completato con successo (campo `text` disponibile).
     * - `FAILED`: Errore durante il processo.
     */
    #[Endpoint(operationId: 'getProcess', title: 'Dettagli trascrizione')]
    #[PathParameter('id', description: 'UUID della trascrizione', type: 'string', example: '550e8400-e29b-41d4-a716-446655440000')]
    #[Response(200, description: 'Dettagli trascrizione', type: 'array{id: string, status: string, text: string}')]
    #[Response(404, description: 'Trascrizione non trovata o non accessibile', type: 'array{message: string, status: int}')]
    public function show(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');

        $transcription = Transcription::where('id', $id)
            ->where('api_key_id', $apiKey->id)
            ->first();

        if (! $transcription) {
            return response()->json([
                'message' => __('validation.escriptorium.status.not_found'),
                'status' => 404,
            ], 404);
        }

        return response()->json([
            'id' => $transcription->id,
            'status' => $transcription->status->getLabel(),
            'text' => $transcription->text ?? '',
        ]);
    }
}
