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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use WebSocket\Client;

/**
 * Job per scaricare l'export TEI XML da eScriptorium via WebSocket.
 *
 * Questo job:
 * 1. Apre una connessione WebSocket a eScriptorium
 * 2. Si unisce alla room del documento
 * 3. Lancia l'export TEI via API
 * 4. Attende il messaggio WebSocket con il link di download
 * 5. Scarica il file ZIP
 * 6. Salva il percorso del file per l'estrazione successiva
 *
 * Flusso:
 * CheckTranscribeTranscriptionJob → [QUESTO JOB] → ✅ COMPLETED
 *
 * API eScriptorium chiamata: POST /api/documents/{pk}/export/
 * WebSocket: ws://escriptorium/ws/notif/
 */
class eScriptoriumDownloadJob implements ShouldQueue
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
     * Timeout del job in secondi (10 minuti per export grandi).
     */
    public int $timeout = 600;

    /**
     * Manager per i dati di servizio.
     */
    private eScriptoriumServiceDataManager $dataManager;

    /**
     * Crea una nuova istanza del job.
     */
    public function __construct(private Transcription $transcription) {}

    /**
     * Esegue il job di download export.
     */
    public function handle(): void
    {
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // ============================================================
        // STEP 1: Aggiorna lo status
        // ============================================================
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Downloading->value]);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_DOWNLOAD);

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
            // STEP 3: Connetti al WebSocket
            // ============================================================
            $wsClient = eScriptorium::createWebSocketClient();

            Log::info('🔌 [eScriptorium] WebSocket connected', [
                'transcription_id' => $this->transcription->id,
            ]);

            // ============================================================
            // STEP 4: Join room del documento
            // ============================================================
            $wsClient->text(json_encode([
                'type' => 'join-room',
                'object_cls' => 'document',
                'object_pk' => $documentId,
            ]));

            Log::info('📡 [eScriptorium] Joined document room', [
                'transcription_id' => $this->transcription->id,
                'document_id' => $documentId,
            ]);

            // ============================================================
            // STEP 5: Lancia l'export TEI via API
            // ============================================================
            $this->triggerExport($documentId, $transcriptionId);

            Log::info('📤 [eScriptorium] Export triggered', [
                'transcription_id' => $this->transcription->id,
                'document_id' => $documentId,
            ]);

            // ============================================================
            // STEP 6: Attendi il messaggio con il link di download
            // ============================================================
            $downloadUrl = $this->waitForDownloadLink($wsClient);

            if (! $downloadUrl) {
                throw new \RuntimeException('Download link not received within timeout');
            }

            Log::info('📥 [eScriptorium] Download link received', [
                'transcription_id' => $this->transcription->id,
                'download_url' => $downloadUrl,
            ]);

            // ============================================================
            // STEP 7: Scarica il file
            // ============================================================
            $localPath = $this->downloadFile($downloadUrl);

            // ============================================================
            // STEP 8: Salva il percorso e completa lo step
            // ============================================================
            $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_DOWNLOAD, [
                'download_url' => $downloadUrl,
                'local_path' => $localPath,
            ]);

            // ============================================================
            // STEP 9: Elimina il progetto da eScriptorium
            // ============================================================
            $this->deleteEscriptoriumProject();

            // ============================================================
            // STEP 10: Marca la trascrizione come completata
            // ============================================================
            $this->transcription->update(['status' => eScriptoriumStatusEnum::Completed->value]);

            Log::info('✅ [eScriptorium] Download completed successfully', [
                'transcription_id' => $this->transcription->id,
                'local_path' => $localPath,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Download failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_DOWNLOAD, $e->getMessage());
            throw $e;
        } finally {
            // Chiudi WebSocket se aperto
            if (isset($wsClient)) {
                try {
                    $wsClient->close();
                } catch (\Exception $e) {
                    // Ignora errori di chiusura
                }
            }
        }
    }

    /**
     * Lancia l'export TEI via API REST.
     */
    private function triggerExport(int $documentId, int $transcriptionId): void
    {
        $partsPks = $this->dataManager->getPartsPks();

        eScriptorium::exportDocument(
            (string) $documentId,
            (string) $transcriptionId,
            $partsPks,
            'teixml'
        );
    }

    /**
     * Attende il messaggio WebSocket con il link di download.
     */
    private function waitForDownloadLink(Client $wsClient): ?string
    {
        $timeout = config('escriptorium.websocket.timeout', 600);
        $startTime = time();

        while ((time() - $startTime) < $timeout) {
            try {
                $message = $wsClient->receive();
                $data = json_decode($message->getContent(), true);

                if (! $data) {
                    continue;
                }

                // Cerca il messaggio di notifica con "Export done!"
                if (($data['type'] ?? '') === 'message' &&
                    str_contains($data['text'] ?? '', 'Export done')) {

                    $links = $data['links'] ?? [];
                    if (! empty($links) && isset($links[0]['src'])) {
                        return $links[0]['src'];
                    }
                }

                // Controlla anche per errori
                if (($data['type'] ?? '') === 'event' &&
                    ($data['name'] ?? '') === 'export:error') {
                    $reason = $data['data']['reason'] ?? 'Unknown export error';
                    throw new \RuntimeException("Export failed: {$reason}");
                }

            } catch (\Exception $e) {
                // Timeout or other WebSocket error, continue the loop
                if (str_contains($e->getMessage(), 'timeout') || str_contains(strtolower($e->getMessage()), 'timed out')) {
                    continue;
                }
                throw $e;
            }
        }

        return null;
    }

    /**
     * Scarica il file di export.
     */
    private function downloadFile(string $downloadUrl): string
    {
        $baseUrl = rtrim(config('escriptorium.api.base_url'), '/');
        $fullUrl = $baseUrl.$downloadUrl;

        $response = Http::withToken(
            eScriptorium::getTokenPublic(),
            config('escriptorium.api.headers.token_header')
        )->get($fullUrl);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download file: '.$response->status());
        }

        // Genera nome file locale
        $filename = basename($downloadUrl);
        $localPath = "escriptorium/exports/{$this->transcription->id}/{$filename}";

        // Salva il file
        Storage::disk('local')->put($localPath, $response->body());

        return $localPath;
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
        Log::error('❌ [eScriptorium] Download error', [
            'transcription_id' => $this->transcription->id,
            'error' => $message,
        ]);

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_DOWNLOAD, $message);
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        $this->fail($message);
    }

    /**
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('❌ [eScriptorium] Download permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_DOWNLOAD, $exception?->getMessage() ?? 'Unknown error');
    }
}
