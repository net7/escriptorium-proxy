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
 * Job per recuperare i PK delle parti del documento da processare.
 *
 * Questo job viene eseguito dopo che l'import del documento è completato.
 * Recupera tutte le parti (pages) dal documento eScriptorium e filtra
 * quelle che corrispondono alle pagine richieste in pages_array.
 *
 * Flusso:
 * CheckImportDocumentJob → [QUESTO JOB] → SegmentDocumentJob → ...
 *
 * API eScriptorium chiamata: GET /api/documents/{pk}/parts/
 *
 * Nota: L'order delle parti è 0-based, mentre pages_array è 1-based.
 * Quindi pages_array = [1,2,3] corrisponde a order = [0,1,2]
 */
class eScriptoriumFetchPartsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Numero massimo di tentativi in caso di errore.
     */
    public int $tries;

    /**
     * Backoff esponenziale per i retry.
     *
     * @var array<int>
     */
    public array $backoff;

    /**
     * Manager per i dati di servizio.
     */
    private eScriptoriumServiceDataManager $dataManager;

    /**
     * Crea una nuova istanza del job.
     *
     * @param  Transcription  $transcription  La trascrizione da elaborare
     */
    public function __construct(private Transcription $transcription)
    {
        $this->tries = (int) config('escriptorium.polling.tries');
        $this->backoff = config('escriptorium.polling.backoff');
    }

    /**
     * Esegue il job di recupero parti.
     */
    public function handle(): void
    {
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // ============================================================
        // STEP 1: Avvia lo step
        // ============================================================
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_FETCH_PARTS);

        // ============================================================
        // STEP 2: Recupera document ID e pages_array
        // ============================================================
        $documentId = $this->dataManager->getDocumentId();
        if (! $documentId) {
            $this->handleError('Document ID not found');

            return;
        }

        $pagesArray = $this->dataManager->getPagesArray();
        if (empty($pagesArray)) {
            $this->handleError('Pages array is empty');

            return;
        }

        try {
            // ============================================================
            // STEP 3: Chiama l'API per recuperare le parti del documento
            // ============================================================
            $partsResponse = eScriptorium::getDocumentParts($documentId);

            if (! $partsResponse || ! isset($partsResponse['results'])) {
                throw new \Exception('Failed to retrieve document parts');
            }

            // ============================================================
            // STEP 4: Filtra le parti in base a pages_array
            // ============================================================
            // pages_array è 1-based, order è 0-based
            // Quindi page 1 corrisponde a order 0, page 2 a order 1, etc.
            $targetOrders = array_map(fn ($page) => $page - 1, $pagesArray);

            $partsPks = [];
            foreach ($partsResponse['results'] as $part) {
                if (in_array($part['order'], $targetOrders, true)) {
                    $partsPks[] = $part['pk'];
                }
            }

            if (empty($partsPks)) {
                throw new \Exception('No matching parts found for requested pages');
            }

            $this->dataManager->setPartsPks($partsPks);
            $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_FETCH_PARTS, [
                'parts_count' => count($partsPks),
                'parts_pks' => $partsPks,
            ]);

            // ============================================================
            // STEP 6: Dispatcha il job di segmentazione
            // ============================================================
            dispatch(new eScriptoriumSegmentDocumentJob($this->transcription));
        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Fetch parts failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
                'response' => $partsResponse,
                'target_orders' => $targetOrders,
                'pages_array' => $pagesArray,
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_FETCH_PARTS, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Gestisce un errore.
     */
    private function handleError(string $message): void
    {
        Log::error('❌ [eScriptorium] Fetch parts error', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_FETCH_PARTS, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('❌ [eScriptorium] Fetch parts permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_FETCH_PARTS, $exception?->getMessage() ?? 'Unknown error');
    }
}
