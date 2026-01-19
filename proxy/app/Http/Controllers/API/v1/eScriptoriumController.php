<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\eScriptoriumStatusEnum;
use App\Facades\eScriptorium;
use App\Http\Controllers\Controller;
use App\Http\Requests\eScriptorium\NewModelRequest;
use App\Http\Requests\eScriptorium\ProcessRequest;
use App\Jobs\eScriptoriumImportDocumentJob;
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
 */
#[Group('eScriptorium', description: 'API per trascrizione OCR automatica di manoscritti. Tutte le richieste richiedono autenticazione tramite header `X-API-Key`.')]
class eScriptoriumController extends Controller
{
    /**
     * Health Check
     *
     * Verifica se il servizio eScriptorium è attivo e raggiungibile.
     * Utile per monitoraggio e health checks automatici.
     */
    #[Endpoint(operationId: 'healthCheck', title: 'Verifica stato servizio')]
    #[Response(200, description: 'Il servizio è attivo e raggiungibile')]
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
     * Recupera l'elenco dei modelli OCR disponibili su eScriptorium.
     * I modelli sono divisi in due categorie:
     * - **segment**: modelli per la segmentazione automatica delle pagine
     * - **recognize**: modelli per il riconoscimento OCR del testo
     */
    #[Endpoint(operationId: 'listModels', title: 'Elenco modelli OCR')]
    #[Response(200, description: 'Elenco dei modelli disponibili', type: 'array{results: array<array{id: int, name: string, accuracy_percent: string|null, job: string}>, count: int, status: int}')]
    #[Response(500, description: 'Errore nel recupero dei modelli', type: 'array{results: array, status: int, message: string}')]
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
     * Recupera l'elenco degli script (sistemi di scrittura) disponibili.
     * Esempi: Latin, Arabic, Hebrew, Greek, Cyrillic, etc.
     *
     * Lo script definisce il sistema di scrittura del documento
     * ed è necessario per configurare correttamente il processo OCR.
     */
    #[Endpoint(operationId: 'listScripts', title: 'Elenco sistemi di scrittura')]
    #[Response(200, description: 'Elenco degli script disponibili', type: 'array{results: array<array{pk: int, name: string}>, count: int, status: int}')]
    #[Response(500, description: 'Errore nel recupero degli script', type: 'array{results: array, status: int, message: string}')]
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
     * Carica un nuovo modello OCR su eScriptorium.
     *
     * @hideFromAPIDocumentation
     */
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
     * Avvia il processo completo di trascrizione OCR.
     *
     * Il flusso automatico comprende:
     * 1. **Creazione progetto** - Crea un progetto su eScriptorium
     * 2. **Import IIIF** - Importa le immagini dal manifest IIIF
     * 3. **Segmentazione** - Identifica righe e regioni di testo
     * 4. **Riconoscimento OCR** - Trascrive il testo con il modello selezionato
     * 5. **Export TEI** - Genera il risultato in formato TEI XML
     *
     * Usa l'endpoint `/v1/status/{id}` per monitorare lo stato.
     */
    #[Endpoint(operationId: 'startProcess', title: 'Avvia trascrizione OCR')]
    #[Response(201, description: 'Processo avviato con successo', type: 'array{message: string, transcription_id: string, status: int}')]
    #[Response(422, description: 'Errore di validazione', type: 'array{message: string, errors: object, status: int}')]
    #[Response(500, description: 'Errore interno', type: 'array{message: string, error: string, status: int}')]
    public function process(ProcessRequest $request): JsonResponse
    {
        $data = $request->validated();
        $apiKey = $request->attributes->get('api_key');

        Log::info('🟢 [eScriptorium] Workflow triggered. Starting process...', [
            'data' => $data,
            'api_key_id' => $apiKey->id,
        ]);

        try {
            $escriptoriumProject = null;
            $escriptoriumDocument = null;

            $transcription = DB::transaction(function () use ($data, $apiKey, &$escriptoriumProject, &$escriptoriumDocument) {
                $projectName = Str::random(16);
                $escriptoriumProject = eScriptorium::createProject($projectName);

                $documentName = Str::random(16);
                $escriptoriumDocument = eScriptorium::createDocument(
                    $documentName,
                    $escriptoriumProject['slug'],
                    $data['script_name']
                );

                return Transcription::create([
                    'api_key_id' => $apiKey->id,
                    'script_name' => $data['script_name'],
                    'manifest_url' => $data['manifest_url'],
                    'pages' => $data['pages'] ?? null,
                    'recognition_model_id' => $data['recognition_model_id'],
                    'segmentation_model_id' => $data['segmentation_model_id'] ?? null,
                    'text_direction' => $data['text_direction'],
                    'status' => eScriptoriumStatusEnum::Pending->value,
                    'service_data' => [
                        'escriptorium' => [
                            'request' => $data,
                            'project' => $escriptoriumProject,
                            'document' => $escriptoriumDocument,
                        ],
                    ],
                ]);
            });

            dispatch(new eScriptoriumImportDocumentJob($transcription));

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
     * Get Transcription Status
     *
     * Recupera lo stato di avanzamento di una trascrizione.
     *
     * Stati possibili:
     * - `Pending` - In attesa di elaborazione
     * - `Importing` - Import immagini in corso
     * - `Segmenting` - Segmentazione in corso
     * - `Transcribing` - OCR in corso
     * - `Downloading` - Download risultati in corso
     * - `Processing` - Elaborazione finale
     * - `Completed` - Trascrizione completata
     * - `Failed` - Errore nel processo
     */
    #[Endpoint(operationId: 'getStatus', title: 'Stato trascrizione')]
    #[PathParameter('id', description: 'UUID della trascrizione restituito da POST /v1/process', type: 'string', example: '550e8400-e29b-41d4-a716-446655440000')]
    #[Response(200, description: 'Stato della trascrizione', type: 'array{id: string, status: string}')]
    #[Response(404, description: 'Trascrizione non trovata', type: 'array{message: string, status: int}')]
    public function status(Request $request, string $id): JsonResponse
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
        ]);
    }

    /**
     * Get Transcription Content
     *
     * Recupera il contenuto testuale di una trascrizione completata.
     *
     * Il testo è restituito in formato TEI XML, uno standard
     * internazionale per la codifica di testi umanistici.
     *
     * > **Nota**: Il campo `text` sarà vuoto se la trascrizione
     * > non è ancora completata. Verificare prima lo `status`.
     */
    #[Endpoint(operationId: 'getContent', title: 'Contenuto trascrizione')]
    #[PathParameter('id', description: 'UUID della trascrizione', type: 'string', example: '550e8400-e29b-41d4-a716-446655440000')]
    #[Response(200, description: 'Contenuto della trascrizione in formato TEI', type: 'array{id: string, text: string, status: string}')]
    #[Response(404, description: 'Trascrizione non trovata', type: 'array{message: string, status: int}')]
    public function content(Request $request, string $id): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');

        $transcription = Transcription::where('id', $id)
            ->where('api_key_id', $apiKey->id)
            ->first();

        if (! $transcription) {
            return response()->json([
                'message' => __('validation.escriptorium.content.not_found'),
                'status' => 404,
            ], 404);
        }

        return response()->json([
            'id' => $transcription->id,
            'text' => $transcription->text ?? '',
            'status' => $transcription->status->getLabel(),
        ]);
    }
}
