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
 * Job per avviare la segmentazione del documento su eScriptorium.
 *
 * La segmentazione analizza le immagini del documento per identificare:
 * - Regioni (blocchi di testo, immagini, margini, etc.)
 * - Linee di testo (baseline detection)
 * - Maschere delle linee (area del testo)
 *
 * Flusso:
 * CheckImportDocumentJob → [QUESTO JOB] → CheckSegmentDocumentJob → ...
 *
 * API eScriptorium chiamata: POST /api/documents/{pk}/segment/
 *
 * L'operazione è ASINCRONA su eScriptorium.
 */
class eScriptoriumSegmentDocumentJob implements ShouldQueue
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
     *
     * @param  Transcription  $transcription  La trascrizione da elaborare
     */
    public function __construct(private Transcription $transcription) {}

    /**
     * Esegue il job di segmentazione.
     */
    public function handle(): void
    {
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);
        // STEP 1: Aggiorna lo status
        // ============================================================
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Segmenting->value]);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_SEGMENT);

        // ============================================================
        // STEP 2: Recupera i parametri necessari
        // ============================================================
        $documentId = $this->dataManager->getDocumentId();
        if (! $documentId) {
            $this->handleError('Document ID not found');

            return;
        }

        $textDirection = $this->dataManager->getTextDirection();
        if (! $textDirection) {
            $this->handleError('Text direction not found');

            return;
        }

        $partsPks = $this->dataManager->getPartsPks();
        if (! $partsPks) {
            $this->handleError('Parts Pks not found');

            return;
        }

        $segmentationModelId = $this->dataManager->getSegmentationModelId();

        try {
            // ============================================================
            // STEP 3: Chiama l'API di segmentazione
            // ============================================================
            $response = eScriptorium::segmentDocument(
                $documentId,
                $partsPks,
                $segmentationModelId,
                'both',
                true,
                $textDirection
            );

            // ============================================================
            // STEP 4: Verifica la risposta
            // ============================================================
            if (! $response || empty($response) || $response['status'] !== 'ok') {
                throw new \Exception('Segmentation failed: '.($response['error'] ?? 'Unknown error'));
            }

            // ============================================================
            // STEP 5: Salva la risposta e dispatcha il polling
            // ============================================================
            $this->dataManager->setStepResponse(eScriptoriumServiceDataManager::STEP_SEGMENT, $response);
            dispatch(new eScriptoriumCheckSegmentDocumentJob($this->transcription));
        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Segmentation dispatch failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_SEGMENT, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Gestisce un errore.
     */
    private function handleError(string $message): void
    {
        Log::error('❌ [eScriptorium] Segmentation error', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_SEGMENT, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('❌ [eScriptorium] Segmentation permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_SEGMENT, $exception?->getMessage() ?? 'Unknown error');
    }
}
