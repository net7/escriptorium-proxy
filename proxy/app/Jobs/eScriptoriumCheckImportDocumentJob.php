<?php

namespace App\Jobs;

use App\Enums\eScriptoriumStatusEnum;
use App\Enums\ProcessSourceEnum;
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

/**
 * Job per verificare lo stato dell'import documento su eScriptorium.
 *
 * Questo job fa POLLING sull'API dei task di eScriptorium per verificare
 * quando l'operazione di import è completata.
 *
 * Flusso:
 * ImportDocumentJob → [QUESTO JOB] → SegmentDocumentJob → ...
 *
 * API eScriptorium chiamata: GET /api/tasks/?document={pk}
 *
 * Workflow States di eScriptorium:
 * - 0: Queued (in coda)
 * - 1: Running (in esecuzione)
 * - 2: Crashed (fallito)
 * - 3: Finished (completato con successo)
 * - 4: Canceled (annullato)
 *
 * Method per import: "imports.tasks.document_import"
 */
class eScriptoriumCheckImportDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesEscriptoriumAuth;

    /**
     * Numero massimo di tentativi in caso di errore API.
     */
    public int $tries;

    /**
     * Backoff esponenziale per i retry in caso di errore.
     *
     * @var array<int>
     */
    public array $backoff;

    /**
     * Inizializza le proprietà di configurazione del job.
     */
    public function __construct(
        private Transcription $transcription,
        private int $pollingAttempt = 1
    ) {
        $this->tries = (int) config('escriptorium.polling.tries');
        $this->backoff = config('escriptorium.polling.backoff');
    }

    /**
     * Manager per i dati di servizio.
     */
    private eScriptoriumServiceDataManager $dataManager;

    /**
     * Esegue il job di verifica stato import.
     */
    public function handle(): void
    {
        $this->setupEscriptoriumAuth($this->transcription);

        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // Skip obsolete jobs silently
        if (! $this->dataManager->isPollingAttemptValid(eScriptoriumServiceDataManager::STEP_IMPORT, $this->pollingAttempt)) {
            return;
        }

        Log::info("⏳ [eScriptorium] Import polling ({$this->pollingAttempt}/".(int) config('escriptorium.polling.max_attempts.import').')', [
            'transcription_id' => $this->transcription->id,
        ]);

        // ============================================================
        // STEP 1: Verifica limite di polling
        // ============================================================
        if ($this->pollingAttempt > (int) config('escriptorium.polling.max_attempts.import')) {
            $this->handleMaxAttemptsExceeded();

            return;
        }

        // ============================================================
        // STEP 2: Recupera i task dal documento
        // ============================================================
        $documentId = $this->dataManager->getDocumentId();
        if (! $documentId) {
            $this->handleError('Document ID not found');

            return;
        }

        try {
            $tasks = eScriptorium::tasks($documentId);
        } catch (\Exception $e) {
            // API error - schedule next poll silently
            $this->scheduleNextCheck();

            return;
        }

        // ============================================================
        // STEP 3: Aggiorna dati di polling
        // ============================================================
        $this->dataManager->updatePolling(
            eScriptoriumServiceDataManager::STEP_IMPORT,
            $this->pollingAttempt,
            $tasks
        );

        // ============================================================
        // STEP 4: Determina quali task monitorare in base alla sorgente
        // ============================================================
        $sourceType = $this->dataManager->getData()['request']['source_type'] ?? ProcessSourceEnum::Manifest->value;

        if ($sourceType === ProcessSourceEnum::Images->value) {
            // Per le immagini caricate singolarmente, monitoriamo i task di conversione "convert"
            // Cerchiamo task il cui metodo finisce con "convert" (es. core.tasks.convert)
            $relevantTasks = array_values(array_filter($tasks['results'], function ($result) use ($documentId) {
                return $result['document'] === (int) $documentId
                    && str_ends_with($result['method'], 'convert');
            }));

            if (empty($relevantTasks)) {
                // Se non ci sono task di conversione, potrebbe essere che:
                // 1. Sono stati cancellati (improbabile così presto)
                // 2. Non sono ancora partiti (possibile se c'è lag)
                // 3. L'upload è stato così veloce che non li vediamo? No, il task report dovrebbe restare.

                // Verifica se abbiamo parti. Se abbiamo parti e nessun task running, siamo ok.
                // Per sicurezza, se siamo ai primi tentativi, aspettiamo ancora.
                if ($this->pollingAttempt < 3) {
                    $this->scheduleNextCheck();

                    return;
                }

                // Se dopo 3 tentativi non vediamo task convert, assumiamo siano finiti o non necessari
                // (es. immagini già supportate nativamente?)
                Log::warning('⚠️ [eScriptorium] No convert tasks found for images. Assuming completion.');
                $this->handleTaskCompleted();

                return;
            }

            // Controlliamo lo stato cumulativo
            $hasRunning = false;
            $hasFailed = false;
            $failureMessages = [];

            foreach ($relevantTasks as $task) {
                $state = $task['workflow_state'];
                if (in_array($state, [0, 1])) { // Queued, Running
                    $hasRunning = true;
                } elseif (in_array($state, [2, 4])) { // Crashed, Canceled
                    $hasFailed = true;
                    $failureMessages[] = $task['messages'] ?? 'Unknown error';
                }
            }

            if ($hasFailed) {
                // Se anche uno solo fallisce, consideriamo l'import fallito?
                // O continuiamo con quelli buoni? Per ora fail fast.
                $this->handleTaskFailed('Image conversion failed: '.implode('; ', array_unique($failureMessages)));

                return;
            }

            if ($hasRunning) {
                $this->scheduleNextCheck();

                return;
            }

            // Se nessuno corre e nessuno è fallito, sono tutti finiti (3)
            $this->handleTaskCompleted();

        } else {
            // Logica esistente per MANIFEST
            $importTasks = array_values(array_filter($tasks['results'], function ($result) use ($documentId) {
                return $result['document'] === (int) $documentId
                    && $result['method'] === 'imports.tasks.document_import';
            }));

            if (empty($importTasks)) {
                $this->handleError('Import tasks not found');

                return;
            }

            $workflowState = $importTasks[0]['workflow_state'] ?? null;

            switch ($workflowState) {
                case 0: // Queued
                case 1: // Running
                    $this->scheduleNextCheck();
                    break;

                case 2: // Crashed
                case 4: // Canceled
                    $this->handleTaskFailed($importTasks[0]['messages'] ?? 'Task failed or canceled');
                    break;

                case 3: // Finished
                    $this->handleTaskCompleted();
                    break;

                default:
                    $this->handleError("Unknown workflow state: {$workflowState}");
            }
        }
    }

    /**
     * Schedula il prossimo check di polling.
     */
    private function scheduleNextCheck(): void
    {
        self::dispatch($this->transcription, $this->pollingAttempt + 1)
            ->delay(now()->addSeconds((int) config('escriptorium.polling.interval')));
    }

    /**
     * Gestisce il completamento del task.
     */
    private function handleTaskCompleted(): void
    {
        Log::info('📥 [eScriptorium] Import completed', ['transcription_id' => $this->transcription->id]);

        $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_IMPORT);
        dispatch(new eScriptoriumFetchPartsJob($this->transcription));
    }

    /**
     * Gestisce il fallimento del task eScriptorium.
     */
    private function handleTaskFailed(string $message): void
    {
        Log::error('❌ [eScriptorium] Import failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_IMPORT, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
    }

    /**
     * Gestisce il superamento del limite di polling.
     */
    private function handleMaxAttemptsExceeded(): void
    {
        $maxAttempts = (int) config('escriptorium.polling.max_attempts.import');

        Log::error('❌ [eScriptorium] Import timeout - max polling attempts exceeded', [
            'transcription_id' => $this->transcription->id,
            'attempts' => "{$this->pollingAttempt}/{$maxAttempts}",
        ]);

        $this->dataManager->failStep(
            eScriptoriumServiceDataManager::STEP_IMPORT,
            "Max polling attempts exceeded ({$this->pollingAttempt}/{$maxAttempts})"
        );
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail('Max polling attempts exceeded');
    }

    /**
     * Gestisce un errore generico.
     */
    private function handleError(string $message): void
    {
        Log::error('❌ [eScriptorium] Import error', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_IMPORT, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->cleanupEscriptoriumAuth();

        Log::error('❌ [eScriptorium] Import permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_IMPORT, $exception?->getMessage() ?? 'Unknown error');
    }
}
