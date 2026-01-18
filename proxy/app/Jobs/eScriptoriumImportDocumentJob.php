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
use Illuminate\Support\Str;

/**
 * Job per importare un documento IIIF in eScriptorium.
 *
 * Questo job è il PRIMO della catena di elaborazione.
 * Chiama l'API di import di eScriptorium per caricare le immagini
 * dal manifest IIIF nel documento precedentemente creato.
 *
 * Flusso:
 * Controller → [QUESTO JOB] → CheckImportDocumentJob → SegmentDocumentJob → ...
 *
 * API eScriptorium chiamata: POST /api/documents/{pk}/import/
 * - mode: "iiif"
 * - iiif_uri: URL del manifest IIIF
 * - name: nome della trascrizione
 *
 * L'operazione è ASINCRONA su eScriptorium, quindi dopo la chiamata
 * viene dispatchato il job di polling per verificare il completamento.
 */
class eScriptoriumImportDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Numero massimo di tentativi in caso di errore.
     * Con backoff esponenziale: 30s, 60s, 120s
     */
    public int $tries = 3;

    /**
     * Secondi di attesa tra i retry in caso di fallimento.
     * Backoff esponenziale: 30 secondi, poi 60, poi 120.
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
     * @param  Transcription  $transcription  La trascrizione locale che traccia il processo
     */
    public function __construct(
        private Transcription $transcription,
    ) {}

    /**
     * Esegue il job di import.
     *
     * 1. Aggiorna lo status a "Importing"
     * 2. Chiama l'API di import di eScriptorium
     * 3. Verifica che la risposta sia ok
     * 4. Salva i dati della risposta in service_data
     * 5. Dispatcha il job di polling per verificare il completamento
     */
    public function handle(): void
    {
        // Inizializza il manager per i dati di servizio
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // ============================================================
        // STEP 1: Aggiorna lo status della trascrizione
        // ============================================================
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Importing->value]);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_IMPORT);

        try {
            // ============================================================
            // STEP 2: Chiama l'API di import di eScriptorium
            // ============================================================
            $documentId = $this->dataManager->getDocumentId();
            if (! $documentId) {
                throw new \Exception('Document ID not found in service data');
            }
            $manifestUrl = $this->dataManager->getManifestUrl();
            if (! $manifestUrl) {
                throw new \Exception('Manifest URL not found in service data');
            }

            $response = eScriptorium::importDocument(
                $documentId,
                'iiif',
                $manifestUrl,
                Str::random(16)
            );

            // ============================================================
            // STEP 3: Verifica la risposta dell'API
            // ============================================================
            if (! $response || empty($response) || $response['status'] !== 'ok') {
                throw new \Exception('Import failed: '.($response['error'] ?? 'Unknown error'));
            }

            // ============================================================
            // STEP 4: Salva i dati della risposta
            // ============================================================
            $this->dataManager->setStepResponse(eScriptoriumServiceDataManager::STEP_IMPORT, $response);

            // ============================================================
            // STEP 5: Dispatcha il job di polling
            // ============================================================
            dispatch(new eScriptoriumCheckImportDocumentJob($this->transcription));
        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Import dispatch failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_IMPORT, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('❌ [eScriptorium] Import permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_IMPORT, $exception?->getMessage() ?? 'Unknown error');
    }
}
