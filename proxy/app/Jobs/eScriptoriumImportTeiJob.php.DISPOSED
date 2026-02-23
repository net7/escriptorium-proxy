<?php

namespace App\Jobs;

use App\Enums\eScriptoriumStatusEnum;
use App\Facades\eScriptorium;
use App\Models\Transcription;
use App\Services\eScriptoriumServiceDataManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job per importare il TEI XML da eScriptorium e salvarlo nella trascrizione.
 *
 * Questo è l'ULTIMO job della catena, eseguito dopo che tutti i task OCR
 * sono completati. Il job:
 * 1. Recupera il TEI XML tramite l'endpoint custom di eScriptorium
 * 2. Salva il contenuto TEI nel campo `text` della trascrizione locale
 * 3. Elimina il progetto da eScriptorium
 * 4. Marca la trascrizione come completata
 *
 * Flusso:
 * CheckTranscribeTranscriptionJob → [QUESTO JOB] → ✅ COMPLETED
 *
 * API eScriptorium chiamata: GET /api/custom/documents/{pk}/transcriptions/{pk}/tei/
 */
class eScriptoriumImportTeiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Numero massimo di tentativi in caso di errore.
     */
    public int $tries = 3;

    /**
     * Backoff esponenziale per i retry.
     *
     * @var array<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Manager per i dati di servizio.
     */
    private eScriptoriumServiceDataManager $dataManager;

    /**
     * Crea una nuova istanza del job.
     */
    public function __construct(private Transcription $transcription) {}

    /**
     * Esegue il job di import TEI.
     */
    public function handle(): void
    {
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);
        // STEP 1: Aggiorna lo status
        // ============================================================
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Importing->value]);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_IMPORT_TEI);

        // ============================================================
        // STEP 2: Recupera i parametri necessari
        // ============================================================
        $documentId = $this->dataManager->getDocumentId();
        $transcriptionId = $this->dataManager->getTranscriptionId();

        if (! $documentId) {
            $this->handleError('Document ID not found');

            return;
        }

        if (! $transcriptionId) {
            $this->handleError('eScriptorium Transcription ID not found');

            return;
        }

        try {
            // ============================================================
            // STEP 3: Recupera il TEI XML da eScriptorium
            // ============================================================
            $teiXml = eScriptorium::exportTeiXml((string) $documentId, (string) $transcriptionId);

            // ============================================================
            // STEP 4: Salva il TEI nel campo text della trascrizione
            // ============================================================
            $this->transcription->update(['text' => $teiXml]);
            $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_IMPORT_TEI);

            // ============================================================
            // STEP 5: Elimina il progetto da eScriptorium
            // ============================================================
            $this->deleteEscriptoriumProject();

            // ============================================================
            // STEP 6: Marca la trascrizione come completata
            // ============================================================
            $this->transcription->update(['status' => eScriptoriumStatusEnum::Completed->value]);

            Log::info('✅ [eScriptorium] Workflow completed successfully', [
                'transcription_id' => $this->transcription->id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] TEI import failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_IMPORT_TEI, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Elimina il progetto da eScriptorium.
     */
    private function deleteEscriptoriumProject(): void
    {
        $projectId = $this->dataManager->getProjectId();

        if (! $projectId) {
            return;
        }

        try {
            eScriptorium::deleteProject($projectId);
        } catch (\Exception $e) {
            // Non-critical - log warning but continue
            Log::warning('⚠️ [eScriptorium] Failed to delete project', [
                'transcription_id' => $this->transcription->id,
                'project_id' => $projectId,
            ]);
        }
    }

    /**
     * Gestisce un errore.
     */
    private function handleError(string $message): void
    {
        Log::error('❌ [eScriptorium] TEI import error', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_IMPORT_TEI, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('❌ [eScriptorium] TEI import permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_IMPORT_TEI, $exception?->getMessage() ?? 'Unknown error');
    }
}
