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
use Illuminate\Support\Facades\Storage;

/**
 * Job per caricare immagini direttamente su eScriptorium.
 *
 * Questo job sostituisce l'ImportDocumentJob quando la sorgente sono immagini raw.
 * Itera sui file salvati temporaneamente e li carica uno ad uno tramite l'API "parts".
 */
class eScriptoriumUploadImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesEscriptoriumAuth;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public int $timeout = 180; // 3 minutes for slow uploads

    private eScriptoriumServiceDataManager $dataManager;

    public function __construct(private Transcription $transcription) {}

    public function handle(): void
    {
        $this->setupEscriptoriumAuth($this->transcription);
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // STEP 1: Update status to match Import flow
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Importing->value]);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_IMPORT);

        try {
            $documentId = $this->dataManager->getDocumentId();
            if (! $documentId) {
                throw new \Exception('Document ID not found in service data');
            }

            $imagePaths = $this->transcription->service_data['escriptorium']['image_paths'] ?? [];
            if (empty($imagePaths)) {
                throw new \Exception('No image paths found for upload');
            }

            Log::info('Starting upload of '.count($imagePaths)." images for transcription {$this->transcription->id}");

            $uploadedCount = 0;
            foreach ($imagePaths as $relativePath) {
                if (! Storage::exists($relativePath)) {
                    Log::warning("File not found for upload: $relativePath");

                    continue;
                }

                $content = Storage::get($relativePath);
                if (! $content) {
                    Log::warning("File content empty for upload: $relativePath");

                    continue;
                }

                // Upload part using content/stream
                eScriptorium::uploadPart($documentId, $content, basename($relativePath));
                $uploadedCount++;

                // Cleanup: Delete file after successful upload to free space
                Storage::delete($relativePath);
            }

            if ($uploadedCount === 0) {
                throw new \Exception('No images were uploaded successfully.');
            }

            Log::info("Successfully uploaded $uploadedCount images. Dispatching status check.");

            // STEP 2: Save upload count but DON'T complete the step yet
            // The CheckImportDocumentJob will complete the step when convert tasks finish
            $this->dataManager->setStepResponse(eScriptoriumServiceDataManager::STEP_IMPORT, [
                'uploaded_count' => $uploadedCount,
            ]);

            // STEP 3: Dispatch polling job
            // This job will wait for any generated tasks (like 'convert') to finish
            dispatch(new eScriptoriumCheckImportDocumentJob($this->transcription));

        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Image upload failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_IMPORT, $e->getMessage());
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->cleanupEscriptoriumAuth();

        Log::error('❌ [eScriptorium] Image upload permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_IMPORT, $exception?->getMessage() ?? 'Unknown error');

        // Attempt cleanup of remaining files
        $imagePaths = $this->transcription->service_data['escriptorium']['image_paths'] ?? [];
        foreach ($imagePaths as $relativePath) {
            if (Storage::exists($relativePath)) {
                Storage::delete($relativePath);
            }
        }
    }
}
