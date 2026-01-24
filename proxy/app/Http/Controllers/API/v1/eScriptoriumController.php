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
     * Restituisce l'elenco dei modelli di riconoscimento (OCR) e segmentazione (Layout) disponibili.
     * È fondamentale scegliere il modello giusto per la fase corretta del processo.
     *
     * ### Tipi di Job
     * - **Segment**: Modelli per l'analisi del layout. Individuano righe, colonne e regioni di testo nell'immagine.
     * - **Recognize**: Modelli per la trascrizione del testo (HTR/OCR). Trasformano le immagini delle righe in testo digitale.
     *
     * ### Visibilità Modelli
     * - **API Key Escriptorium**: Mostra i tuoi modelli privati + modelli condivisi.
     * - **API Key Proxy**: Mostra solo i modelli globali/di servizio configurati nel sistema.
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
     * Start OCR Process
     *
     * Avvia un processo completo di trascrizione asincrono.
     *
     * Il flusso di lavoro include:
     * 1. **Setup**: Creazione (o riuso) di Progetto e Documento su eScriptorium.
     * 2. **Import**: Acquisizione immagini (da Manifest IIIF o Upload diretto).
     * 3. **Segmentation**: Analisi del layout (individuazione righe e regioni).
     * 4. **Recognition**: Trascrizione del testo (OCR/HTR) usando il modello specificato.
     * 5. **Export**: Generazione output TEI XML e estrazione testo puro.
     *
     * ### Autenticazione e Persistenza
     * Il comportamento cambia in base al tipo di chiave API utilizzata:
     * - **API Key Proxy** (Default):
     *    - Crea progetti **temporanei**.
     *    - I dati vengono eliminati da eScriptorium al termine (successo o fallimento).
     *    - Non permette il riuso di progetti esistenti.
     * - **API Key Escriptorium** (Personale):
     *    - Crea progetti **persistenti** nel tuo account.
     *    - Supporta la logica **Find or Create**: se `project_name` o `document_name` corrispondono a entità esistenti, vengono riutilizzate.
     *
     * ### Modalità di Input (`source_type`)
     * - **Manifest IIIF (`manifest`)**:
     *    - Scarica le immagini da un server IIIF esterno.
     *    - Supporta il filtro pagine tramite parametro `pages`.
     * - **Upload Immagini (`images`)**:
     *    - Richiede caricamento file raw via `multipart/form-data`.
     *    - Elabora automaticamente **tutte** le immagini caricate (ignora `pages`).
     *    - Limite dimensione: 20MB per file.
     *
     * ### Parametri Chiave
     * - `script_id`: Fondamentale per indicare la lingua/scrittura (ottiene da `/v1/scripts`).
     * - `recognition_model_id`: Il "cervello" che legge il testo (ottiene da `/v1/models`).
     * - `segmentation_model_id`: Opzionale, per layout complessi.
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
            $transcription = DB::transaction(function () use ($apiKey, $escriptoriumToken, $data, &$escriptoriumProject, &$escriptoriumDocument, $isApiKey) {
                // REUSE LOGIC: Check for existing Project and Document if using persistent auth
                $projectName = $data['project_name'] ?? null;
                $documentName = $data['document_name'] ?? null;

                // 1. PROJECT HANDLING
                // If using persistent Escriptorium Auth and a specific project name is provided, try to find it
                if ($isApiKey && $projectName) {
                    $existingProjects = eScriptorium::getProjects($projectName);
                    if (! empty($existingProjects)) {
                        // Use the first match
                        $escriptoriumProject = $existingProjects[0];
                        Log::info("♻️ [eScriptorium] Reusing existing project: {$projectName} (PK: {$escriptoriumProject['pk']})");
                    }
                }

                // Create if not found or not reusing
                if (! $escriptoriumProject) {
                    $projectName = $projectName ?? Str::random(16);
                    $escriptoriumProject = eScriptorium::createProject($projectName);
                    Log::info("✨ [eScriptorium] Created new project: {$projectName}");
                }

                // 2. DOCUMENT HANDLING
                // If reusing project (and persistent auth), check for document reuse
                if ($isApiKey && $documentName && isset($escriptoriumProject['pk'])) {
                    $existingDocs = eScriptorium::getDocuments($escriptoriumProject['pk'], $documentName);
                    if (! empty($existingDocs)) {
                        $escriptoriumDocument = $existingDocs[0];
                        Log::info("♻️ [eScriptorium] Reusing existing document: {$documentName} (PK: {$escriptoriumDocument['pk']})");
                    }
                }

                if (! $escriptoriumDocument) {
                    $documentName = $documentName ?? Str::random(16);
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
