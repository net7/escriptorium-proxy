<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\eScriptoriumStatusEnum;
use App\Facades\eScriptorium;
use App\Http\Controllers\Controller;
use App\Http\Requests\eScriptorium\NewModelRequest;
use App\Http\Requests\eScriptorium\ProcessRequest;
use App\Jobs\eScriptoriumImportDocumentJob;
use App\Models\Transcription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Controller per la gestione delle API di eScriptorium.
 *
 * Questo controller espone endpoint per:
 * - Verificare lo stato del servizio eScriptorium
 * - Recuperare i modelli OCR disponibili
 * - Recuperare gli script disponibili
 * - Caricare nuovi modelli OCR
 * - Avviare il processo completo di trascrizione (import → segmentazione → OCR)
 */
class eScriptoriumController extends Controller
{
    /**
     * Verifica se il servizio eScriptorium è attivo e raggiungibile.
     *
     * @return Response HTTP 200 se il servizio è attivo, HTTP 503 se non raggiungibile
     */
    public function up(): Response
    {
        try {
            // Tenta di verificare la connessione con eScriptorium
            $up = eScriptorium::isUp();
        } catch (\RuntimeException $e) {
            // In caso di errore di connessione, restituisce Service Unavailable
            return response()->noContent(503);
        }

        // Restituisce 200 se attivo, 503 se non disponibile
        return response()->noContent($up ? 200 : 503);
    }

    /**
     * Recupera l'elenco dei modelli OCR disponibili su eScriptorium.
     *
     * I modelli vengono formattati per includere:
     * - id: identificativo del modello (pk)
     * - name: nome del modello
     * - accuracy_percent: percentuale di accuratezza (null se 0%)
     * - job: tipo di modello ('segment' o 'recognize')
     *
     * @return JsonResponse Lista dei modelli con count e status
     */
    public function models(): JsonResponse
    {
        try {
            // Recupera tutti i modelli dall'API di eScriptorium
            $models = eScriptorium::models();
        } catch (\Exception $e) {
            // In caso di errore, restituisce lista vuota con messaggio di errore
            return response()->json([
                'results' => [],
                'status' => 500,
                'message' => $e->getMessage(),
            ], 500);
        }

        // Mappa i modelli nel formato richiesto dal frontend
        $models = array_map(function ($model): array {
            // Controlla se l'accuratezza è zero per evitare di mostrare "0%"
            $isAccuracyPercentZero = Number::format($model['accuracy_percent'], 0) === '0';

            return [
                'id' => $model['pk'],
                'name' => $model['name'],
                // Mostra null invece di "0%" per modelli senza accuratezza calcolata
                'accuracy_percent' => $isAccuracyPercentZero ? null : Number::format($model['accuracy_percent'], 1).'%',
                // Normalizza il tipo di job in lowercase
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
     * Recupera l'elenco degli script (alfabeti/lingue) disponibili su eScriptorium.
     *
     * Gli script definiscono il sistema di scrittura del documento
     * (es. Latin, Arabic, Hebrew, etc.)
     *
     * @return JsonResponse Lista degli script disponibili
     */
    public function scripts(): JsonResponse
    {
        try {
            // Recupera tutti gli script dall'API di eScriptorium
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
     * Carica un nuovo modello OCR su eScriptorium.
     *
     * Il processo tenta prima l'upload via browser (che gestisce meglio il parsing
     * dell'accuratezza), e in caso di fallimento ricade sull'upload diretto via API.
     *
     * @param  NewModelRequest  $request  Request validata con name e file
     * @return Response|JsonResponse HTTP 201 se successo, HTTP 409 se modello esiste, HTTP 500 se errore
     */
    public function newModel(NewModelRequest $request): Response|JsonResponse
    {
        // Recupera tutti i modelli per verificare duplicati
        $allModels = eScriptorium::models();

        // Verifica se esiste già un modello con lo stesso nome
        if (\in_array($request->validated('name'), array_column($allModels, 'name'))) {
            return response()->json([
                'message' => __('validation.escriptorium.new_model.already_exists'),
                'status' => 409,
            ], 409);
        }

        try {
            // Primo tentativo: upload via browser (simula form HTML)
            eScriptorium::newModelViaBrowser(
                $request->validated('name'),
                $request->validated('file')
            );
        } catch (\RuntimeException $e) {
            // Fallback: upload diretto via API
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
     * Avvia il processo completo di trascrizione OCR su eScriptorium.
     *
     * Il flusso completo è:
     * 1. Crea un progetto su eScriptorium
     * 2. Crea un documento nel progetto
     * 3. Crea una Transcription locale per tracciare il processo
     * 4. Avvia il job di import che innesca la catena:
     *    Import IIIF → Segmentazione → Creazione Trascrizione → OCR
     *
     * @param  ProcessRequest  $request  Request validata con tutti i parametri necessari
     * @return JsonResponse ID della trascrizione creata e status
     */
    public function process(ProcessRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Recupera l'API key autenticata dal middleware
        $apiKey = $request->attributes->get('api_key');

        Log::info('🟢 [eScriptorium] Workflow triggered. Starting process...', [
            'data' => $data,
            'api_key_id' => $apiKey->id,
        ]);

        try {
            // Variabili per memorizzare i dati di eScriptorium
            $escriptoriumProject = null;
            $escriptoriumDocument = null;

            // ============================================================
            // STEP 1: Crea risorse su eScriptorium in una transazione
            // ============================================================

            // Usa una transazione per garantire che la Transcription locale
            // venga creata solo se le chiamate a eScriptorium hanno successo
            $transcription = DB::transaction(function () use ($data, $apiKey, &$escriptoriumProject, &$escriptoriumDocument) {

                // Crea il progetto su eScriptorium
                // Il nome è generato casualmente perché è temporaneo
                // API: POST /api/projects/
                $projectName = Str::random(16);
                $escriptoriumProject = eScriptorium::createProject($projectName);

                // Crea il documento nel progetto eScriptorium
                // API: POST /api/documents/
                $documentName = Str::random(16);
                $escriptoriumDocument = eScriptorium::createDocument(
                    $documentName,
                    $escriptoriumProject['slug'],
                    $data['script_name']
                );

                // Crea la Transcription locale per tracciare il processo
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
                            // Salva la request originale per accedere a model_id, etc.
                            'request' => $data,
                            // Salva i dati del progetto (pk, slug, etc.)
                            'project' => $escriptoriumProject,
                            // Salva i dati del documento (pk, name, etc.)
                            'document' => $escriptoriumDocument,
                        ],
                    ],
                ]);
            });

            // ============================================================
            // STEP 2: Avvia il job di import che innesca la catena di job
            // ============================================================

            // Dispatcha il primo job della catena
            // La sequenza completa sarà:
            // ImportDocumentJob → CheckImportDocumentJob (polling)
            // → SegmentDocumentJob → CheckSegmentDocumentJob (polling)
            // → CreateTranscriptionJob
            // → TranscribeTranscriptionJob → CheckTranscribeTranscriptionJob (polling)
            // → COMPLETED
            dispatch(new eScriptoriumImportDocumentJob($transcription));

            Log::info('🚀 [eScriptorium] Process started', ['transcription_id' => $transcription->id]);

            // Restituisce l'ID della trascrizione per permettere al client
            // di monitorare lo stato del processo
            return response()->json([
                'message' => 'Process started successfully',
                'transcription_id' => $transcription->id,
                'status' => 201,
            ], 201);

        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Process failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('validation.escriptorium.process.request_failed'),
                'error' => $e->getMessage(),
                'status' => 500,
            ], 500);
        }
    }

    /**
     * Recupera lo stato di una trascrizione.
     *
     * Verifica che la trascrizione appartenga all'API key autenticata.
     *
     * @param  string  $id  UUID della trascrizione
     * @return JsonResponse Stato della trascrizione
     */
    public function status(Request $request, string $id): JsonResponse
    {
        // Recupera l'API key autenticata dal middleware
        $apiKey = $request->attributes->get('api_key');

        // Cerca la trascrizione verificando che appartenga all'API key
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
}
