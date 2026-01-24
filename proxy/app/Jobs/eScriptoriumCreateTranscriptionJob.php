<?php

namespace App\Jobs;

use App\Enums\eScriptoriumStatusEnum;
use App\Facades\eScriptorium;
use App\Jobs\Concerns\UsesEscriptoriumAuth;
use App\Models\Transcription;
use App\Services\eScriptoriumServiceDataManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job per creare una trascrizione su eScriptorium.
 *
 * NOTA: La "Transcription" su eScriptorium è un "layer" di testo
 * associato a un documento - diversa dalla Transcription locale.
 *
 * Flusso:
 * CheckSegmentDocumentJob → [QUESTO JOB] → TranscribeTranscriptionJob → ...
 *
 * API eScriptorium chiamata: POST /api/documents/{pk}/transcriptions/
 * L'operazione è SINCRONA su eScriptorium.
 */
class eScriptoriumCreateTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesEscriptoriumAuth;

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
     * Esegue il job di creazione trascrizione.
     */
    public function handle(): void
    {
        $this->setupEscriptoriumAuth($this->transcription);

        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // ============================================================
        // STEP 1: Avvia lo step
        // ============================================================
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_CREATE_TRANSCRIPTION);

        // ============================================================
        // STEP 2: Recupera l'ID del documento
        // ============================================================
        $documentId = $this->dataManager->getDocumentId();
        if (! $documentId) {
            $this->handleError('Document ID not found');

            return;
        }

        try {
            // ============================================================
            // STEP 3: Crea la trascrizione su eScriptorium
            // ============================================================
            $response = eScriptorium::createTranscription($documentId, Str::random(16));

            // ============================================================
            // STEP 4: Salva i dati della trascrizione eScriptorium
            // ============================================================
            $this->dataManager->setTranscription($response);
            $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_CREATE_TRANSCRIPTION, $response);

            Log::info('📝 [eScriptorium] Transcription layer created', ['transcription_id' => $this->transcription->id]);

            // ============================================================
            // STEP 5: Dispatcha il job di trascrizione OCR
            // ============================================================
            dispatch(new eScriptoriumTranscribeTranscriptionJob($this->transcription));
        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Transcription layer creation failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_CREATE_TRANSCRIPTION, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Gestisce un errore.
     */
    private function handleError(string $message): void
    {
        Log::error('❌ [eScriptorium] Transcription layer error', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_CREATE_TRANSCRIPTION, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->cleanupEscriptoriumAuth();

        Log::error('❌ [eScriptorium] Transcription layer permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_CREATE_TRANSCRIPTION, $exception?->getMessage() ?? 'Unknown error');
    }
}
