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
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Job per processare l'export TEI XML scaricato da eScriptorium.
 *
 * Questo job:
 * 1. Estrae il file ZIP scaricato
 * 2. Legge tutti i file XML nella cartella estratta
 * 3. Merge i contenuti TEI con mergeTeiContents()
 * 4. Aggiorna la transcription con il testo TEI merged
 * 5. Pulisce i file temporanei (ZIP e cartella estratta)
 *
 * Flusso:
 * DownloadJob → [QUESTO JOB] → ✅ COMPLETED
 */
class eScriptoriumProcessTeiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Numero massimo di tentativi.
     */
    public int $tries = 3;

    /**
     * Backoff esponenziale.
     *
     * @var array<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * Timeout del job (5 minuti).
     */
    public int $timeout = 300;

    /**
     * Manager per i dati di servizio.
     */
    private eScriptoriumServiceDataManager $dataManager;

    /**
     * Crea una nuova istanza del job.
     *
     * @param  Transcription  $transcription  La trascrizione da aggiornare
     * @param  string  $zipPath  Il percorso del file ZIP (relativo a storage/app/private)
     */
    public function __construct(
        private Transcription $transcription,
        private string $zipPath
    ) {}

    /**
     * Esegue il job di processing TEI.
     */
    public function handle(): void
    {
        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        // ============================================================
        // STEP 1: Aggiorna lo status
        // ============================================================
        $this->transcription->update(['status' => eScriptoriumStatusEnum::Processing->value]);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_PROCESS);

        Log::info('🔄 [eScriptorium] Processing TEI export', [
            'transcription_id' => $this->transcription->id,
            'zip_path' => $this->zipPath,
        ]);

        try {
            // ============================================================
            // STEP 2: Estrai lo ZIP
            // ============================================================
            $extractPath = $this->extractZip();

            Log::info('📦 [eScriptorium] ZIP extracted', [
                'transcription_id' => $this->transcription->id,
                'extract_path' => $extractPath,
            ]);

            // ============================================================
            // STEP 3: Trova e leggi i file XML
            // ============================================================
            $xmlContents = $this->readXmlFiles($extractPath);

            if (empty($xmlContents)) {
                throw new \RuntimeException('No XML files found in the ZIP archive');
            }

            Log::info('📄 [eScriptorium] XML files read', [
                'transcription_id' => $this->transcription->id,
                'count' => count($xmlContents),
            ]);

            // ============================================================
            // STEP 4: Merge dei contenuti TEI
            // ============================================================
            $mergedTei = eScriptorium::mergeTeiContents($xmlContents);

            if (empty($mergedTei)) {
                throw new \RuntimeException('Failed to merge TEI contents');
            }

            Log::info('🔗 [eScriptorium] TEI contents merged', [
                'transcription_id' => $this->transcription->id,
                'length' => strlen($mergedTei),
            ]);

            // ============================================================
            // STEP 5: Aggiorna la transcription
            // ============================================================
            $this->transcription->update(['text' => $mergedTei]);

            // ============================================================
            // STEP 6: Cleanup - elimina ZIP e cartella estratta
            // ============================================================
            $this->cleanup($extractPath);

            Log::info('🧹 [eScriptorium] Cleanup completed', [
                'transcription_id' => $this->transcription->id,
            ]);

            // ============================================================
            // STEP 7: Marca come completato
            // ============================================================
            $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_PROCESS);
            $this->transcription->update(['status' => eScriptoriumStatusEnum::Completed->value]);

            Log::info('✅ [eScriptorium] Processing completed successfully', [
                'transcription_id' => $this->transcription->id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ [eScriptorium] Processing failed', [
                'transcription_id' => $this->transcription->id,
                'error' => $e->getMessage(),
            ]);

            $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_PROCESS, $e->getMessage());
            $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);

            throw $e;
        }
    }

    /**
     * Estrae il file ZIP.
     *
     * @return string Il percorso della cartella estratta (relativo a storage/app/private)
     */
    private function extractZip(): string
    {
        $disk = Storage::disk('local');
        $fullZipPath = $disk->path($this->zipPath);

        if (! file_exists($fullZipPath)) {
            throw new \RuntimeException("ZIP file not found: {$this->zipPath}");
        }

        // Estrai nella stessa directory del ZIP
        $extractDir = pathinfo($this->zipPath, PATHINFO_DIRNAME).'/'.pathinfo($this->zipPath, PATHINFO_FILENAME);

        $zip = new ZipArchive;
        $result = $zip->open($fullZipPath);

        if ($result !== true) {
            throw new \RuntimeException("Failed to open ZIP file: error code {$result}");
        }

        $extractFullPath = $disk->path($extractDir);

        // Crea la directory se non esiste
        if (! is_dir($extractFullPath)) {
            mkdir($extractFullPath, 0755, true);
        }

        $zip->extractTo($extractFullPath);
        $zip->close();

        return $extractDir;
    }

    /**
     * Legge tutti i file XML dalla cartella estratta.
     *
     * @param  string  $extractPath  Il percorso della cartella estratta
     * @return array<string> Array di contenuti XML
     */
    private function readXmlFiles(string $extractPath): array
    {
        $disk = Storage::disk('local');
        $fullPath = $disk->path($extractPath);

        // Cerca i file XML (potrebbero essere nella root o in una sottocartella)
        $xmlFiles = $this->findXmlFiles($fullPath);

        // Ordina i file per nome (assumendo formato 0_xxx.xml, 1_xxx.xml, etc.)
        usort($xmlFiles, function ($a, $b) {
            $numA = $this->extractPageNumber(basename($a));
            $numB = $this->extractPageNumber(basename($b));

            return $numA <=> $numB;
        });

        $contents = [];
        foreach ($xmlFiles as $xmlFile) {
            $content = file_get_contents($xmlFile);
            if ($content !== false && ! empty(trim($content))) {
                $contents[] = $content;
            }
        }

        return $contents;
    }

    /**
     * Trova ricorsivamente tutti i file XML in una directory.
     *
     * @param  string  $directory  La directory da cercare
     * @return array<string> Array di percorsi assoluti ai file XML
     */
    private function findXmlFiles(string $directory): array
    {
        $xmlFiles = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
                $xmlFiles[] = $file->getPathname();
            }
        }

        return $xmlFiles;
    }

    /**
     * Estrae il numero di pagina dal nome del file.
     *
     * @param  string  $filename  Il nome del file (es. "0_abc123_default.xml")
     * @return int Il numero di pagina
     */
    private function extractPageNumber(string $filename): int
    {
        // Il formato è "{page_number}_{hash}_{transcription_name}.xml"
        if (preg_match('/^(\d+)_/', $filename, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Pulisce i file temporanei.
     *
     * @param  string  $extractPath  Il percorso della cartella estratta
     */
    private function cleanup(string $extractPath): void
    {
        $disk = Storage::disk('local');

        // Elimina la cartella estratta
        if ($disk->exists($extractPath)) {
            $disk->deleteDirectory($extractPath);
        }

        // Elimina il file ZIP
        if ($disk->exists($this->zipPath)) {
            $disk->delete($this->zipPath);
        }
    }
}
