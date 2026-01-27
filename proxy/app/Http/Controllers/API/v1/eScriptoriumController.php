<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\eScriptoriumStatusEnum;
use App\Enums\ProcessSourceEnum;
use App\Facades\eScriptorium;
use App\Http\Controllers\Controller;
use App\Http\Requests\eScriptorium\ImagesProcessRequest;
use App\Http\Requests\eScriptorium\ManifestProcessRequest;
use App\Http\Requests\eScriptorium\NewModelRequest;
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
 * ### 1. API Key Proxy
 * Chiave generata dal sistema proxy per integrazioni automatiche.
 * I progetti vengono **eliminati automaticamente** al termine del processo.
 *
 * ### 2. API Key eScriptorium
 * Chiave personale dal tuo account eScriptorium. In questa modalità:
 * - I progetti sono **persistenti sul tuo account eScriptorium personale**
 * - Puoi accedere ai progetti direttamente dalla piattaforma eScriptorium
 * - Supporta il riuso di progetti e documenti esistenti (Find or Create)
 */
#[Group('eScriptorium', description: 'API per trascrizione OCR automatica di manoscritti. Supporta API Key Proxy (temporaneo) o API Key eScriptorium (persistente sul tuo account).')]
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

        return response()->noContent($up ? 204 : 503);
    }

    /**
     * List OCR Models
     *
     * Restituisce l'elenco dei modelli di riconoscimento (OCR) e segmentazione (Layout) disponibili.
     * È fondamentale scegliere il modello giusto per la fase corretta del processo.
     *
     * ### Tipi di Job
     * - **segment**: Modelli per l'analisi del layout. Individuano righe, colonne e regioni di testo nell'immagine.
     * - **recognize**: Modelli per la trascrizione del testo (HTR/OCR). Trasformano le immagini delle righe in testo digitale.
     *
     * ### Visibilità Modelli
     * - **API Key eScriptorium**: Mostra i tuoi modelli privati + modelli condivisi.
     * - **API Key Proxy**: Mostra solo i modelli globali/di servizio configurati nel sistema.
     */
    #[Endpoint(operationId: 'listModels', title: 'Elenco modelli OCR')]
    #[Response(
        200,
        description: 'Elenco modelli recuperato con successo',
        type: 'array{results: array<array{id: int, name: string, accuracy_percent: string|null, job: string}>, count: int, status: int}',
        examples: [
            [
                'results' => [
                    ['id' => 142, 'name' => 'Catmus Medieval', 'accuracy_percent' => '97.2%', 'job' => 'recognize'],
                    ['id' => 98, 'name' => 'HTR-United Manu McFrench', 'accuracy_percent' => '95.8%', 'job' => 'recognize'],
                    ['id' => 45, 'name' => 'blla.mlmodel', 'accuracy_percent' => null, 'job' => 'segment'],
                ],
                'count' => 3,
                'status' => 200,
            ],
        ]
    )]
    #[Response(
        500,
        description: 'Errore di comunicazione con eScriptorium',
        type: 'array{results: array, status: int, message: string}',
        examples: [
            [
                'results' => [],
                'status' => 500,
                'message' => 'Connection to eScriptorium failed: timeout after 30s',
            ],
        ]
    )]
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
    #[Response(
        200,
        description: 'Elenco script recuperato con successo',
        type: 'array{results: array<array{pk: int, name: string}>, count: int, status: int}',
        examples: [
            [
                'results' => [
                    ['pk' => 1, 'name' => 'Latin'],
                    ['pk' => 2, 'name' => 'Arabic'],
                    ['pk' => 3, 'name' => 'Hebrew'],
                    ['pk' => 4, 'name' => 'Greek'],
                    ['pk' => 5, 'name' => 'Cyrillic'],
                ],
                'count' => 5,
                'status' => 200,
            ],
        ]
    )]
    #[Response(
        500,
        description: 'Errore di comunicazione con eScriptorium',
        type: 'array{results: array, status: int, message: string}',
        examples: [
            [
                'results' => [],
                'status' => 500,
                'message' => 'Connection to eScriptorium failed',
            ],
        ]
    )]
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
     * Carica un nuovo modello OCR personalizzato su eScriptorium.
     * Supporta file formato `.mlmodel` (compatibile con motore Kraken).
     *
     * ### Requisiti
     * - **File**: Deve essere un file binario valido `.mlmodel`.
     * - **Nome**: Deve essere univoco nel tuo account. Se esiste già, riceverai un errore `409 Conflict`.
     *
     * ### Nota Tecnica
     * L'upload avviene in due step: verifica preliminare e caricamento effettivo. Il sistema tenterà di usare
     * una simulazione browser se l'API standard non supporta certe feature (es. parsing accuracy).
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
     * Trascrizione da Manifest IIIF
     *
     * Avvia una trascrizione OCR scaricando le immagini da un Manifest IIIF pubblico.
     *
     * ### Workflow
     * 1. **Setup**: Creazione Progetto e Documento su eScriptorium
     * 2. **Import**: Download immagini dal server IIIF
     * 3. **Segmentation**: Analisi layout (righe e regioni)
     * 4. **Recognition**: Trascrizione OCR/HTR
     * 5. **Export**: Generazione TEI XML
     *
     * ### Persistenza Dati
     * | Tipo Chiave | Comportamento |
     * |-------------|---------------|
     * | **API Key Proxy** | Progetti temporanei, auto-eliminati al termine |
     * | **API Key eScriptorium** | Progetti persistenti **sul tuo account personale**, supporta Find or Create |
     */
    #[Endpoint(operationId: 'processManifest', title: 'Trascrizione da Manifest IIIF')]
    #[Response(
        201,
        description: 'Processo avviato correttamente',
        type: 'array{message: string, transcription_id: string, status: int}',
        examples: [
            [
                'message' => 'Process started successfully',
                'transcription_id' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 201,
            ],
        ]
    )]
    #[Response(
        422,
        description: 'Errore di validazione parametri',
        type: 'array{message: string, errors: array<string, array<string>>, status: int}',
        examples: [
            [
                'message' => 'Validation failed',
                'errors' => [
                    'manifest_url' => ['The manifest_url field is required.'],
                ],
                'status' => 422,
            ],
        ]
    )]
    #[Response(
        500,
        description: 'Errore interno del server',
        type: 'array{message: string, error: string, status: int}',
        examples: [
            [
                'message' => 'Process creation failed',
                'error' => 'Unable to create project on eScriptorium',
                'status' => 500,
            ],
        ]
    )]
    public function processManifest(ManifestProcessRequest $request): JsonResponse
    {
        return $this->executeProcess($request->validated(), $request);
    }

    /**
     * Trascrizione da Upload Immagini
     *
     * Avvia una trascrizione OCR caricando direttamente i file immagine.
     *
     * ### Workflow
     * 1. **Setup**: Creazione Progetto e Documento su eScriptorium
     * 2. **Upload**: Caricamento immagini sul server
     * 3. **Segmentation**: Analisi layout (righe e regioni)
     * 4. **Recognition**: Trascrizione OCR/HTR
     * 5. **Export**: Generazione TEI XML
     *
     * ### Limiti Upload
     * - **Max 20MB** per singolo file
     * - Formati supportati: JPEG, PNG, TIFF
     *
     * ### Persistenza Dati
     * | Tipo Chiave | Comportamento |
     * |-------------|---------------|
     * | **API Key Proxy** | Progetti temporanei, auto-eliminati al termine |
     * | **API Key eScriptorium** | Progetti persistenti **sul tuo account personale**, supporta Find or Create |
     */
    #[Endpoint(operationId: 'processImages', title: 'Trascrizione da Upload Immagini')]
    #[Response(
        201,
        description: 'Processo avviato correttamente',
        type: 'array{message: string, transcription_id: string, status: int}',
        examples: [
            [
                'message' => 'Process started successfully',
                'transcription_id' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 201,
            ],
        ]
    )]
    #[Response(
        422,
        description: 'Errore di validazione parametri',
        type: 'array{message: string, errors: array<string, array<string>>, status: int}',
        examples: [
            [
                'message' => 'Validation failed',
                'errors' => [
                    'images' => ['The images field is required.'],
                ],
                'status' => 422,
            ],
        ]
    )]
    #[Response(
        500,
        description: 'Errore interno del server',
        type: 'array{message: string, error: string, status: int}',
        examples: [
            [
                'message' => 'Process creation failed',
                'error' => 'Unable to create project on eScriptorium',
                'status' => 500,
            ],
        ]
    )]
    public function processImages(ImagesProcessRequest $request): JsonResponse
    {
        return $this->executeProcess($request->validated(), $request);
    }

    /**
     * Execute the OCR process (shared logic for manifest and images).
     */
    private function executeProcess(array $data, Request $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
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
            $documentId = $data['document_id'] ?? null;

            // VALIDATE document_id BEFORE transaction (Direct Mode only)
            if ($isApiKey && $documentId) {
                try {
                    $escriptoriumDocument = eScriptorium::getDocument((string) $documentId);
                    Log::info("♻️ [eScriptorium] Using existing document ID: {$documentId} (Name: {$escriptoriumDocument['name']})");

                    // Project info is implicit in the document
                    $escriptoriumProject = [
                        'slug' => $escriptoriumDocument['project'] ?? null,
                        'pk' => null,
                        'name' => null,
                    ];

                    // Extract text_direction from the document's main_script
                    // The Script model has text_direction: horizontal-lr, horizontal-rl, vertical-lr, vertical-rl, ttb
                    // The Document's read_direction (ltr/rtl) is about page order, not text direction
                    $mainScriptName = $escriptoriumDocument['main_script'] ?? null;
                    if ($mainScriptName) {
                        // Look up the script to get its text_direction
                        $scripts = eScriptorium::scripts();
                        foreach ($scripts as $script) {
                            if ($script['name'] === $mainScriptName) {
                                $data['text_direction'] = $script['text_direction'] ?? 'horizontal-lr';
                                Log::info("📐 [eScriptorium] Using script '{$mainScriptName}' text_direction: {$data['text_direction']}");
                                break;
                            }
                        }
                    }

                    // Fallback if no script found or no main_script set
                    if (empty($data['text_direction'])) {
                        // Use read_direction as a rough approximation (ltr/rtl only)
                        $readDirection = $escriptoriumDocument['read_direction'] ?? 'ltr';
                        $data['text_direction'] = $readDirection === 'rtl' ? 'horizontal-rl' : 'horizontal-lr';
                        Log::info("📐 [eScriptorium] No main_script, using read_direction fallback: {$readDirection} -> {$data['text_direction']}");
                    }
                } catch (\Exception $e) {
                    Log::error("❌ [eScriptorium] Failed to get document ID: {$documentId}", ['error' => $e->getMessage()]);

                    // Check if it's a 404 (not found) or 403 (forbidden) based on error message
                    $errorMessage = $e->getMessage();
                    if (str_contains($errorMessage, '404') || str_contains($errorMessage, 'Not found')) {
                        return response()->json([
                            'message' => __('validation.escriptorium.process.document_not_found'),
                            'error' => "Document with ID {$documentId} not found",
                            'status' => 404,
                        ], 404);
                    }

                    if (str_contains($errorMessage, '403') || str_contains($errorMessage, 'Forbidden')) {
                        return response()->json([
                            'message' => __('validation.escriptorium.process.document_not_accessible'),
                            'error' => "Document with ID {$documentId} is not accessible with your credentials",
                            'status' => 403,
                        ], 403);
                    }

                    // Generic error for other cases
                    return response()->json([
                        'message' => __('validation.escriptorium.process.document_not_found'),
                        'error' => "Document with ID {$documentId} not found or not accessible",
                        'status' => 404,
                    ], 404);
                }
            }

            $transcription = DB::transaction(function () use ($apiKey, $escriptoriumToken, $data, &$escriptoriumProject, &$escriptoriumDocument) {
                // CREATE NEW PROJECT AND DOCUMENT if not using existing document
                if (! $escriptoriumDocument) {
                    $projectName = Str::random(16);
                    $escriptoriumProject = eScriptorium::createProject($projectName);
                    Log::info("✨ [eScriptorium] Created new project: {$projectName}");

                    $documentName = Str::random(16);
                    $escriptoriumDocument = eScriptorium::createDocument(
                        $documentName,
                        $escriptoriumProject['slug'],
                        $data['script_name']
                    );
                    Log::info("✨ [eScriptorium] Created new document: {$documentName}");
                }

                $serviceData = [
                    'escriptorium' => [
                        'request' => $data,
                        'project' => $escriptoriumProject,
                        'document' => $escriptoriumDocument,
                        'transcription_name' => Str::random(16),
                    ],
                ];

                // Handle image uploads
                if ($data['source_type'] === ProcessSourceEnum::Images->value) {
                    $imagePaths = [];
                    if (isset($data['images']) && is_array($data['images'])) {
                        foreach ($data['images'] as $image) {
                            $path = $image->store('transcriptions/pending_uploads');
                            $imagePaths[] = $path;
                        }
                    }
                    $serviceData['escriptorium']['image_paths'] = $imagePaths;

                    $count = count($imagePaths);
                    if ($count > 0) {
                        $data['pages_array'] = range(1, $count);
                        $data['pages'] = "1-$count";
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
     * Recupera lo stato di avanzamento e il risultato finale di una trascrizione.
     *
     * ### Ciclo di Vita (`status`)
     * 1. **PENDING**: La richiesta è stata accettata ed è in coda di elaborazione.
     * 2. **IMPORTING**: È in corso il download delle immagini (da Manifest) o l'elaborazione dell'upload.
     * 3. **SEGMENTING**: Il modello di segmentazione sta analizzando il layout delle pagine.
     * 4. **TRANSCRIBING**: Il modello di riconoscimento sta leggendo il testo riga per riga.
     * 5. **DOWNLOADING**: Il proxy sta recuperando i risultati XML/TEI da eScriptorium.
     * 6. **PROCESSING**: Elaborazione finale, pulizia e formattazione del testo.
     * 7. **COMPLETED**: Processo terminato con successo. Il campo `text` contiene il risultato.
     *
     * ### Stati di Errore
     * - **FAILED**: Si è verificato un errore critico (es. file corrotto, timeout, errore server remoto).
     *
     * ### Output
     * Quando lo stato è `COMPLETED`, il campo `text` conterrà il testo piano estratto dal TEI.
     */
    #[Endpoint(operationId: 'getProcess', title: 'Dettagli trascrizione')]
    #[PathParameter('id', description: 'UUID della trascrizione', type: 'string', example: '550e8400-e29b-41d4-a716-446655440000')]
    #[Response(
        200,
        description: 'Dettagli trascrizione con stato e testo (se completata)',
        type: 'array{id: string, status: string, text: string}',
        examples: [
            'in_progress' => [
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 'TRANSCRIBING',
                'text' => '',
            ],
            'completed' => [
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 'COMPLETED',
                'text' => "In principio creavit Deus caelum et terram.\nTerra autem erat inanis et vacua...",
            ],
            'failed' => [
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'status' => 'FAILED',
                'text' => '',
            ],
        ]
    )]
    #[Response(
        404,
        description: 'Trascrizione non trovata o non accessibile',
        type: 'array{message: string, status: int}',
        examples: [
            [
                'message' => 'Transcription not found',
                'status' => 404,
            ],
        ]
    )]
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
