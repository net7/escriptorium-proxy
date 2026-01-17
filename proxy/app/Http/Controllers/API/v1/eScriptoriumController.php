<?php

namespace App\Http\Controllers\API\v1;

use App\Enums\eScriptoriumStatusEnum;
use App\Facades\eScriptorium;
use App\Http\Controllers\Controller;
use App\Http\Requests\eScriptorium\NewModelRequest;
use App\Http\Requests\eScriptorium\ProcessRequest;
use App\Jobs\eScriptoriumImportDocumentJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Modules\LiteraryWork\Models\LiteraryWork;
use Modules\Project\Models\Project;
use Modules\Transcription\Models\Transcription;

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

        Log::info('🟢 [eScriptorium] Workflow triggered. Starting process...', [
            'data' => $data,
        ]);

        try {
            // ============================================================
            // STEP 1: Recupera le entità locali dal database
            // ============================================================

            // Recupera il progetto locale (usato per il nome del progetto eScriptorium)
            $project = Project::findOrFail($data['project_id']);

            // Recupera l'opera letteraria (usata per il nome del documento eScriptorium)
            $literaryWork = LiteraryWork::findOrFail($data['document_id']);

            // Variabili per memorizzare i dati di eScriptorium
            $escriptoriumProject = null;
            $escriptoriumDocument = null;

            // ============================================================
            // STEP 2: Crea risorse su eScriptorium in una transazione
            // ============================================================

            // Usa una transazione per garantire che la Transcription locale
            // venga creata solo se le chiamate a eScriptorium hanno successo
            $transcription = DB::transaction(function () use ($data, $project, $literaryWork, &$escriptoriumProject, &$escriptoriumDocument) {

                // Crea il progetto su eScriptorium
                // Il nome viene slugificato per essere URL-friendly
                // API: POST /api/projects/
                $escriptoriumProject = eScriptorium::createProject(Str::slug($project->name));

                // Crea il documento nel progetto eScriptorium
                // API: POST /api/documents/
                // Parametri:
                // - name: nome del documento (slug dell'opera letteraria)
                // - project: slug del progetto appena creato
                // - main_script: script/alfabeto selezionato dall'utente
                $escriptoriumDocument = eScriptorium::createDocument(
                    Str::slug($literaryWork->title),
                    $escriptoriumProject['slug'],
                    $data['script_name']
                );

                // Crea la Transcription locale per tracciare il processo
                // Memorizza tutti i dati di eScriptorium in service_data per riferimento futuro
                return Transcription::create([
                    'literary_work_id' => $literaryWork->id,
                    'title' => $data['name'],
                    'status' => eScriptoriumStatusEnum::Pending->value,
                    'service_used' => 'escriptorium',
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
            // STEP 3: Avvia il job di import che innesca la catena di job
            // ============================================================

            // Dispatcha il primo job della catena
            // La sequenza completa sarà:
            // ImportDocumentJob → CheckImportDocumentJob (polling)
            // → SegmentDocumentJob → CheckSegmentDocumentJob (polling)
            // → CreateTranscriptionJob
            // → TranscribeTranscriptionJob → CheckTranscribeTranscriptionJob (polling)
            // → COMPLETED
            dispatch(new eScriptoriumImportDocumentJob(
                $transcription,
                $project,
                $literaryWork,
            ));

            Log::info('🚀 [eScriptorium] Process started', ['transcription_id' => $transcription->id]);

            // Restituisce l'ID della trascrizione per permettere al client
            // di monitorare lo stato del processo
            return response()->json([
                'message' => 'Process started successfully',
                'transcription_id' => $transcription->id,
                'status' => 201,
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => __('validation.escriptorium.process.resource_not_found'),
                'error' => $e->getMessage(),
                'status' => 404,
            ], 404);

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
}
