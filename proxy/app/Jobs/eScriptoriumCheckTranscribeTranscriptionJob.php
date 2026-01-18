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
 * Job per verificare lo stato della trascrizione OCR su eScriptorium.
 *
 * Questo è l'ULTIMO job della catena. Quando tutti i task OCR sono completati,
 * la trascrizione locale viene marcata come COMPLETED.
 *
 * Flusso:
 * TranscribeTranscriptionJob → [QUESTO JOB] → ✅ COMPLETED
 *
 * API eScriptorium chiamata: GET /api/tasks/?document={pk}
 * Method per OCR: "core.tasks.transcribe"
 */
class eScriptoriumCheckTranscribeTranscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Numero massimo di tentativi in caso di errore API.
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
     */
    public function __construct(
        private Transcription $transcription,
        private int $pollingAttempt = 1
    ) {
        $this->tries = (int) config('escriptorium.polling.tries');
        $this->backoff = config('escriptorium.polling.backoff');
    }

    /**
     * Esegue il job di verifica stato trascrizione OCR.
     */
    public function handle(): void
    {
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // Skip obsolete jobs silently
        if (! $this->dataManager->isPollingAttemptValid(eScriptoriumServiceDataManager::STEP_TRANSCRIBE, $this->pollingAttempt)) {
            return;
        }

        Log::info("⏳ [eScriptorium] OCR polling ({$this->pollingAttempt}/".(int) config('escriptorium.polling.max_attempts.transcribe').')', [
            'transcription_id' => $this->transcription->id,
        ]);

        // ============================================================
        // STEP 1: Verifica limite di polling
        // ============================================================
        if ($this->pollingAttempt > (int) config('escriptorium.polling.max_attempts.transcribe')) {
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
            $this->scheduleNextCheck();

            return;
        }

        // ============================================================
        // STEP 3: Aggiorna dati di polling
        // ============================================================
        $this->dataManager->updatePolling(
            eScriptoriumServiceDataManager::STEP_TRANSCRIBE,
            $this->pollingAttempt,
            $tasks
        );

        // ============================================================
        // STEP 4: Filtra e analizza i task OCR
        // ============================================================
        $transcribeTasks = array_values(array_filter($tasks['results'], function ($result) use ($documentId) {
            return $result['document'] === (int) $documentId
                && $result['method'] === 'core.tasks.transcribe';
        }));

        if (empty($transcribeTasks)) {
            $this->handleError('Transcribe tasks not found');

            return;
        }

        $stats = $this->analyzeTaskStates($transcribeTasks);

        // ============================================================
        // STEP 5: Decide il prossimo step
        // ============================================================
        if ($stats['failed'] > 0) {
            $this->handleTasksFailed($stats['failed']);

            return;
        }

        if ($stats['pending'] > 0) {
            $this->scheduleNextCheck();

            return;
        }

        if ($stats['completed'] === $stats['total']) {
            $this->handleAllTasksCompleted();

            return;
        }
    }

    /**
     * Analizza gli stati dei task.
     *
     * @return array{total: int, completed: int, failed: int, pending: int}
     */
    private function analyzeTaskStates(array $tasks): array
    {
        $states = array_map(fn ($t) => $t['workflow_state'], $tasks);

        return [
            'total' => count($states),
            'completed' => count(array_filter($states, fn ($s) => $s === 3)),
            'failed' => count(array_filter($states, fn ($s) => $s === 2 || $s === 4)),
            'pending' => count(array_filter($states, fn ($s) => $s === 0 || $s === 1)),
        ];
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
     * Gestisce il completamento di tutti i task OCR.
     * Dispatcha il job per importare il TEI XML e completare il workflow.
     */
    private function handleAllTasksCompleted(): void
    {
        Log::info('✨ [eScriptorium] OCR completed', ['transcription_id' => $this->transcription->id]);

        $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_TRANSCRIBE);
        dispatch(new eScriptoriumDownloadJob($this->transcription));
    }

    /**
     * Gestisce il fallimento di alcuni task.
     */
    private function handleTasksFailed(int $failedCount): void
    {
        $message = "{$failedCount} transcribe task(s) failed";

        Log::error('❌ [eScriptorium] OCR failed', [
            'transcription_id' => $this->transcription->id,
            'failed_tasks' => $failedCount,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_TRANSCRIBE, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il superamento del limite di polling.
     */
    private function handleMaxAttemptsExceeded(): void
    {
        $maxAttempts = (int) config('escriptorium.polling.max_attempts.transcribe');
        $message = "Max polling attempts exceeded ({$this->pollingAttempt}/{$maxAttempts})";

        Log::error('❌ [eScriptorium] OCR timeout', [
            'transcription_id' => $this->transcription->id,
            'attempts' => "{$this->pollingAttempt}/{$maxAttempts}",
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_TRANSCRIBE, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce un errore generico.
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
