<?php

namespace App\Jobs;

use App\Enums\eScriptoriumStatusEnum;
use App\Facades\eScriptorium;
use App\Services\eScriptoriumServiceDataManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Transcription\Models\Transcription;

/**
 * Job per avviare la trascrizione OCR su eScriptorium.
 *
 * Questo job avvia il processo di riconoscimento del testo (OCR)
 * utilizzando un modello di riconoscimento addestrato.
 *
 * Flusso:
 * CreateTranscriptionJob → [QUESTO JOB] → CheckTranscribeTranscriptionJob → COMPLETED
 *
 * API eScriptorium chiamata: POST /api/documents/{pk}/transcribe/
 * L'operazione è ASINCRONA - crea UN TASK PER OGNI PAGINA.
 */
class eScriptoriumTranscribeTranscriptionJob implements ShouldQueue
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
     * Esegue il job di trascrizione OCR.
     */
    public function handle(): void
    {
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);
        // STEP 1: Aggiorna lo status
        // ============================================================
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Transcribing->value]);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_TRANSCRIBE);

        // ============================================================
        // STEP 2: Recupera i parametri necessari
        // ============================================================
        $documentId = $this->dataManager->getDocumentId();
        if (! $documentId) {
            $this->handleError('Document ID not found');

            return;
        }

        $transcriptionId = $this->dataManager->getTranscriptionId();
        if (! $transcriptionId) {
            $this->handleError('eScriptorium Transcription ID not found');

            return;
        }

        $modelId = $this->dataManager->getRecognitionModelId();
        if (! $modelId) {
            $this->handleError('Recognition Model ID not found');

            return;
        }

        $partsPks = $this->dataManager->getPartsPks();
        if (! $partsPks) {
            $this->handleError('Parts Pks not found');

            return;
        }

        try {
            // ============================================================
            // STEP 3: Chiama l'API di trascrizione OCR
            // ============================================================
            $response = eScriptorium::transcribeTranscription($documentId, $partsPks, $transcriptionId, $modelId);

            // ============================================================
            // STEP 4: Salva la risposta e dispatcha il polling
            // ============================================================
            $this->dataManager->setStepResponse(eScriptoriumServiceDataManager::STEP_TRANSCRIBE, $response);
            dispatch(new eScriptoriumCheckTranscribeTranscriptionJob($this->transcription));
        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] OCR dispatch failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_TRANSCRIBE, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Gestisce un errore.
     */
    private function handleError(string $message): void
    {
        Log::error('❌ [eScriptorium] OCR error', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_TRANSCRIBE, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('❌ [eScriptorium] OCR permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_TRANSCRIBE, $exception?->getMessage() ?? 'Unknown error');
    }
}
