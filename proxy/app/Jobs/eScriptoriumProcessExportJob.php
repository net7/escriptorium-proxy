<?php

namespace App\Jobs;

use App\Enums\eScriptoriumStatusEnum;
use App\Enums\ExportFormatEnum;
use App\Facades\eScriptorium;
use App\Jobs\Concerns\UsesEscriptoriumAuth;
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
 * Job per processare l'export scaricato da eScriptorium.
 *
 * Gestisce tutti i formati di export:
 * - teixml: estrae ZIP, merge XMLs, salva TEI in text, mantiene ZIP
 * - text: legge il file .txt, salva contenuto in text, mantiene file
 * - pagexml/alto: mantiene ZIP, text resta null
 *
 * Flusso:
 * DownloadJob → [QUESTO JOB] → COMPLETED
 */
class eScriptoriumProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesEscriptoriumAuth;

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
     * @param  string  $filePath  Il percorso del file scaricato (relativo a storage/app/private)
     * @param  ExportFormatEnum  $exportFormat  Il formato di export richiesto
     */
    public function __construct(
        private Transcription $transcription,
        private string $filePath,
        private ExportFormatEnum $exportFormat
    ) {}

    /**
     * Esegue il job di processing export.
     */
    public function handle(): void
    {
        $this->setupEscriptoriumAuth($this->transcription);

        $this->dataManager = eScriptoriumServiceDataManager::for($this->transcription);

        $this->updateStatus(eScriptoriumStatusEnum::Processing);
        $this->dataManager->startStep(eScriptoriumServiceDataManager::STEP_PROCESS);

        Log::info('[eScriptorium] Processing export', $this->logContext(['format' => $this->exportFormat->value]));

        try {
            match ($this->exportFormat) {
                ExportFormatEnum::TeiXml => $this->processTeiXml(),
                ExportFormatEnum::Text => $this->processText(),
                ExportFormatEnum::PageXml, ExportFormatEnum::Alto, ExportFormatEnum::OpenItiMarkdown => $this->processZipOnly(),
            };

            $this->transcription->update(['export_file_path' => $this->filePath]);

            $this->markAsCompleted();
            Log::info('[eScriptorium] Processing completed successfully', $this->logContext());

        } catch (\Throwable $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * TEI XML: estrae ZIP, merge XMLs, salva TEI merged in text, mantiene ZIP.
     */
    private function processTeiXml(): void
    {
        $extractPath = $this->extractZip();
        Log::info('[eScriptorium] ZIP extracted', $this->logContext(['extract_path' => $extractPath]));

        $xmlContents = $this->readXmlFiles($extractPath);
        $this->ensureXmlFilesFound($xmlContents);
        Log::info('[eScriptorium] XML files read', $this->logContext(['count' => count($xmlContents)]));

        $mergedTei = $this->mergeTeiContents($xmlContents);
        Log::info('[eScriptorium] TEI contents merged', $this->logContext(['length' => Str::length($mergedTei)]));

        $this->transcription->update(['text' => $mergedTei]);

        // Elimina solo la directory estratta, mantiene il ZIP per il download
        if ($this->disk()->exists($extractPath)) {
            $this->disk()->deleteDirectory($extractPath);
        }
        Log::info('[eScriptorium] Extracted directory cleaned up (ZIP preserved)', $this->logContext());
    }

    /**
     * Text: legge il file .txt, salva contenuto in text.
     */
    private function processText(): void
    {
        $fullPath = $this->disk()->path($this->filePath);

        if (! File::exists($fullPath)) {
            throw new RuntimeException("Text file not found: {$this->filePath}");
        }

        $content = File::get($fullPath);
        $this->transcription->update(['text' => $content]);

        Log::info('[eScriptorium] Text file read', $this->logContext(['length' => Str::length($content)]));
    }

    /**
     * PageXml/Alto: mantiene il ZIP, text resta null.
     */
    private function processZipOnly(): void
    {
        $fullPath = $this->disk()->path($this->filePath);

        if (! File::exists($fullPath)) {
            throw new RuntimeException("ZIP file not found: {$this->filePath}");
        }

        Log::info('[eScriptorium] ZIP file preserved for download', $this->logContext());
    }

    /**
     * Ritorna l'istanza del disk storage (lazy-loaded e cached).
     */
    private function disk(): Filesystem
    {
        return $this->disk ??= Storage::disk('local');
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
            'file_path' => $this->filePath,
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
        Log::error('[eScriptorium] Processing failed', $this->logContext(['error' => $e->getMessage()]));

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
        $fullZipPath = $this->disk()->path($this->filePath);

        if (! File::exists($fullZipPath)) {
            throw new RuntimeException("ZIP file not found: {$this->filePath}");
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
        $dirname = pathinfo($this->filePath, PATHINFO_DIRNAME);
        $filename = pathinfo($this->filePath, PATHINFO_FILENAME);

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
     * Gestisce il fallimento permanente del job.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->cleanupEscriptoriumAuth();

        Log::error('[eScriptorium] Processing permanently failed', [
            'transcription_id' => $this->transcription->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->transcription->update(['status' => eScriptoriumStatusEnum::Failed->value]);
        eScriptoriumServiceDataManager::for($this->transcription)
            ->failStep(eScriptoriumServiceDataManager::STEP_PROCESS, $exception?->getMessage() ?? 'Unknown error');
    }
}
