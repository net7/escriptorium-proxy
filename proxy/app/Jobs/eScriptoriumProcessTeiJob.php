<?php

namespace App\Jobs;

use App\Enums\eScriptoriumStatusEnum;
use App\Facades\eScriptorium;
use App\Models\Transcription;
use App\Services\eScriptoriumServiceDataManager;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
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
     * Istanza cached del disk storage.
     */
    private ?Filesystem $disk = null;

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

        $this->updateStatus(eScriptoriumStatusEnum::Processing);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_PROCESS);

        Log::info('🔄 [eScriptorium] Processing TEI export', $this->logContext());

        try {
            $extractPath = $this->extractZip();
            Log::info('📦 [eScriptorium] ZIP extracted', $this->logContext(['extract_path' => $extractPath]));

            $xmlContents = $this->readXmlFiles($extractPath);
            $this->ensureXmlFilesFound($xmlContents);
            Log::info('📄 [eScriptorium] XML files read', $this->logContext(['count' => count($xmlContents)]));

            $mergedTei = $this->mergeTeiContents($xmlContents);
            Log::info('� [eScriptorium] TEI contents merged', $this->logContext(['length' => Str::length($mergedTei)]));

            $this->transcription->update(['text' => $mergedTei]);

            $this->cleanup($extractPath);
            Log::info('🧹 [eScriptorium] Cleanup completed', $this->logContext());

            $this->markAsCompleted();
            Log::info('✅ [eScriptorium] Processing completed successfully', $this->logContext());

        } catch (\Throwable $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * Ritorna l'istanza del disk storage (lazy-loaded e cached).
     */
    private function disk(): Filesystem
    {
        return $this->disk ??= Storage::disk('local');
    }

    /**
     * Ritorna il percorso completo del file ZIP.
     */
    private function fullZipPath(): string
    {
        return $this->disk()->path($this->zipPath);
    }

    /**
     * Aggiorna lo status della transcription.
     */
    private function updateStatus(eScriptoriumStatusEnum $status): void
    {
        $this->transcription->update(['status' => $status->value]);
    }

    /**
     * Crea il contesto standard per i log.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(array $extra = []): array
    {
        return array_merge([
            'transcription_id' => $this->transcription->id,
            'zip_path' => $this->zipPath,
        ], $extra);
    }

    /**
     * Verifica che siano stati trovati file XML.
     *
     * @param  array<string>  $xmlContents
     *
     * @throws RuntimeException
     */
    private function ensureXmlFilesFound(array $xmlContents): void
    {
        if (empty($xmlContents)) {
            throw new RuntimeException('No XML files found in the ZIP archive');
        }
    }

    /**
     * Esegue il merge dei contenuti TEI.
     *
     * @param  array<string>  $xmlContents
     *
     * @throws RuntimeException
     */
    private function mergeTeiContents(array $xmlContents): string
    {
        $mergedTei = eScriptorium::mergeTeiContents($xmlContents);

        if (empty($mergedTei)) {
            throw new RuntimeException('Failed to merge TEI contents');
        }

        return $mergedTei;
    }

    /**
     * Marca il job come completato.
     */
    private function markAsCompleted(): void
    {
        $this->dataManager->completeStep(eScriptoriumServiceDataManager::STEP_PROCESS);
        $this->updateStatus(eScriptoriumStatusEnum::Completed);
    }

    /**
     * Gestisce il fallimento del job.
     *
     * @throws \Throwable
     */
    private function handleFailure(\Throwable $e): void
    {
        Log::error('❌ [eScriptorium] Processing failed', $this->logContext(['error' => $e->getMessage()]));

        $this->dataManager->failStep(eScriptoriumServiceDataManager::STEP_PROCESS, $e->getMessage());
        $this->updateStatus(eScriptoriumStatusEnum::Failed);

        throw $e;
    }

    /**
     * Estrae il file ZIP.
     *
     * @return string Il percorso della cartella estratta (relativo a storage/app/private)
     *
     * @throws RuntimeException
     */
    private function extractZip(): string
    {
        $fullZipPath = $this->fullZipPath();

        if (! File::exists($fullZipPath)) {
            throw new RuntimeException("ZIP file not found: {$this->zipPath}");
        }

        $extractDir = $this->buildExtractDirectory();
        $extractFullPath = $this->disk()->path($extractDir);

        File::ensureDirectoryExists($extractFullPath, 0755);

        $this->performZipExtraction($fullZipPath, $extractFullPath);

        return $extractDir;
    }

    /**
     * Costruisce il percorso della directory di estrazione.
     */
    private function buildExtractDirectory(): string
    {
        $dirname = pathinfo($this->zipPath, PATHINFO_DIRNAME);
        $filename = pathinfo($this->zipPath, PATHINFO_FILENAME);

        return "{$dirname}/{$filename}";
    }

    /**
     * Esegue l'estrazione del file ZIP.
     *
     * @throws RuntimeException
     */
    private function performZipExtraction(string $zipPath, string $extractPath): void
    {
        $zip = new ZipArchive;
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new RuntimeException("Failed to open ZIP file: error code {$result}");
        }

        try {
            $zip->extractTo($extractPath);
        } finally {
            $zip->close();
        }
    }

    /**
     * Legge tutti i file XML dalla cartella estratta.
     *
     * @param  string  $extractPath  Il percorso della cartella estratta
     * @return array<string> Array di contenuti XML
     */
    private function readXmlFiles(string $extractPath): array
    {
        $fullPath = $this->disk()->path($extractPath);
        $xmlFiles = $this->findXmlFiles($fullPath);

        // Ordina i file per numero di pagina
        usort($xmlFiles, fn ($a, $b) => $this->extractPageNumber(File::basename($a)) <=> $this->extractPageNumber(File::basename($b)));

        return collect($xmlFiles)
            ->map(fn ($xmlFile) => File::get($xmlFile))
            ->filter(fn ($content) => Str::length(trim($content)) > 0)
            ->values()
            ->all();
    }

    /**
     * Trova ricorsivamente tutti i file XML in una directory.
     *
     * @param  string  $directory  La directory da cercare
     * @return array<string> Array di percorsi assoluti ai file XML
     */
    private function findXmlFiles(string $directory): array
    {
        return collect(File::allFiles($directory))
            ->filter(fn ($file) => Str::lower($file->getExtension()) === 'xml')
            ->map(fn ($file) => $file->getPathname())
            ->all();
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
        // Elimina la cartella estratta
        if ($this->disk()->exists($extractPath)) {
            $this->disk()->deleteDirectory($extractPath);
        }

        // Elimina il file ZIP
        if ($this->disk()->exists($this->zipPath)) {
            $this->disk()->delete($this->zipPath);
        }
    }
}
