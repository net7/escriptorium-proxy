<?php

namespace App\Services;

use App\Facades\eScriptorium;
use App\Models\Transcription;

/**
 * Manager per la gestione strutturata dei dati di servizio eScriptorium.
 *
 * Questa classe gestisce la struttura dei dati salvati in service_data
 * della Transcription, garantendo un formato consistente e leggibile.
 *
 * Struttura service_data:
 * ```
 * [
 *     'escriptorium' => [
 *         'request' => [...],           // Request originale dal controller
 *         'project' => [...],           // Dati progetto eScriptorium (pk, slug, etc.)
 *         'document' => [...],          // Dati documento eScriptorium (pk, name, etc.)
 *         'transcription' => [...],     // Dati trascrizione eScriptorium (pk, name, etc.)
 *
 *         'steps' => [
 *             'import' => [
 *                 'status' => 'pending|in_progress|completed|failed',
 *                 'started_at' => '2024-01-15T10:30:00Z',
 *                 'completed_at' => '2024-01-15T10:35:00Z',
 *                 'response' => [...],      // Response API iniziale
 *                 'error' => null,          // Messaggio errore se fallito
 *                 'polling' => [
 *                     'attempts' => 5,
 *                     'last_check_at' => '...',
 *                     'tasks' => [...],     // Ultimo stato dei task
 *                 ],
 *             ],
 *             'segment' => [...],           // Stessa struttura
 *             'create_transcription' => [...],
 *             'transcribe' => [...],
 *         ],
 *     ],
 * ]
 * ```
 */
class eScriptoriumServiceDataManager
{
    /**
     * Nomi degli step del processo.
     */
    public const STEP_IMPORT = 'import';

    public const STEP_SEGMENT = 'segment';

    public const STEP_CREATE_TRANSCRIPTION = 'create_transcription';

    public const STEP_TRANSCRIBE = 'transcribe';

    public const STEP_IMPORT_TEI = 'import_tei';

    public const STEP_FETCH_PARTS = 'fetch_parts';

    public const STEP_DOWNLOAD = 'download';

    /**
     * Stati possibili per ogni step.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Crea una nuova istanza del manager.
     *
     * @param  Transcription  $transcription  La trascrizione da gestire
     */
    public function __construct(private Transcription $transcription) {}

    /**
     * Crea un manager per una trascrizione.
     *
     * @param  Transcription  $transcription  La trascrizione
     * @return static Nuova istanza del manager
     */
    public static function for(Transcription $transcription): static
    {
        return new static($transcription);
    }

    /**
     * Recupera tutti i dati di eScriptorium.
     *
     * @return array Dati di eScriptorium
     */
    public function getData(): array
    {
        return $this->transcription->service_data['escriptorium'] ?? [];
    }

    /**
     * Recupera i dati di uno step specifico.
     *
     * @param  string  $step  Nome dello step (es. 'import', 'segment')
     * @return array Dati dello step
     */
    public function getStep(string $step): array
    {
        return $this->getData()['steps'][$step] ?? [];
    }

    /**
     * Verifica se uno step è completato.
     *
     * @param  string  $step  Nome dello step
     * @return bool True se completato
     */
    public function isStepCompleted(string $step): bool
    {
        return ($this->getStep($step)['status'] ?? null) === self::STATUS_COMPLETED;
    }

    /**
     * Verifica se uno step è fallito.
     *
     * @param  string  $step  Nome dello step
     * @return bool True se fallito
     */
    public function isStepFailed(string $step): bool
    {
        return ($this->getStep($step)['status'] ?? null) === self::STATUS_FAILED;
    }

    /**
     * Recupera l'ID del documento eScriptorium.
     *
     * @return int|null ID del documento
     */
    public function getDocumentId(): ?int
    {
        return $this->getData()['document']['pk'] ?? null;
    }

    /**
     * Recupera gli ID dei tipi di regione validi per il documento.
     *
     * @return array ID dei tipi di regione validi (come stringhe)
     */
    public function getDocumentValidRegionTypesIds(): array
    {
        $validBlockTypes = $this->getData()['document']['valid_block_types'] ?? [];
        $pks = array_column($validBlockTypes, 'pk');

        return array_map('strval', $pks);
    }

    /**
     * Aggiorna i dati del documento recuperandoli da eScriptorium.
     *
     * Utile per ottenere i valid_block_types aggiornati dopo segmentazione/transcription.
     *
     * @return array Dati aggiornati del documento
     */
    public function refreshDocument(): array
    {
        $document = eScriptorium::getDocument((string) $this->getDocumentId());
        $this->update(['document' => $document]);

        return $document;
    }

    /**
     * Recupera l'ID del progetto eScriptorium.
     *
     * @return int|null ID del progetto
     */
    public function getProjectId(): ?int
    {
        $project = $this->getData()['project'] ?? [];

        // eScriptorium API returns 'id' for projects, 'pk' for documents
        return $project['pk'] ?? $project['id'] ?? null;
    }

    /**
     * Recupera l'URL del manifest.
     *
     * @return string|null URL del manifest
     */
    public function getManifestUrl(): ?string
    {
        return $this->getData()['request']['manifest_url'] ?? null;
    }

    /**
     * Recupera l'ID della trascrizione eScriptorium.
     *
     * @return int|null ID della trascrizione
     */
    public function getTranscriptionId(): ?int
    {
        return $this->getData()['transcription']['pk'] ?? null;
    }

    /**
     * Recupera l'ID del modello di riconoscimento dalla request.
     *
     * @return int|null ID del modello
     */
    public function getRecognitionModelId(): ?int
    {
        return $this->getData()['request']['recognition_model_id'] ?? null;
    }

    /**
     * Recupera l'ID del modello di segmentazione dalla request.
     *
     * @return int|null ID del modello
     */
    public function getSegmentationModelId(): ?int
    {
        return $this->getData()['request']['segmentation_model_id'] ?? null;
    }

    /**
     * Recupera la direzione del testo dalla request.
     *
     * @return string Direzione del testo (default: horizontal-lr)
     */
    public function getTextDirection(): string
    {
        return $this->getData()['request']['text_direction'] ?? 'horizontal-lr';
    }

    /**
     * Recupera l'array delle pagine dalla request.
     *
     * @return array Array delle pagine (1-based)
     */
    public function getPagesArray(): array
    {
        return $this->getData()['request']['pages_array'] ?? [];
    }

    /**
     * Salva i PK delle parti da processare.
     *
     * @param  array  $partsPks  Array dei PK delle parti
     */
    public function setPartsPks(array $partsPks): void
    {
        $this->update(['parts_pks' => $partsPks]);
    }

    /**
     * Recupera i PK delle parti da processare.
     *
     * @return array Array dei PK delle parti
     */
    public function getPartsPks(): array
    {
        return $this->getData()['parts_pks'] ?? [];
    }

    /**
     * Avvia uno step (imposta status in_progress e started_at).
     *
     * @param  string  $step  Nome dello step
     */
    public function startStep(string $step): void
    {
        $this->updateStep($step, [
            'status' => self::STATUS_IN_PROGRESS,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Completa uno step con successo.
     *
     * @param  string  $step  Nome dello step
     * @param  array|null  $response  Response dell'API (opzionale)
     */
    public function completeStep(string $step, ?array $response = null): void
    {
        $data = [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now()->toIso8601String(),
        ];

        if ($response !== null) {
            $data['response'] = $response;
        }

        $this->updateStep($step, $data);
    }

    /**
     * Segna uno step come fallito.
     *
     * @param  string  $step  Nome dello step
     * @param  string  $error  Messaggio di errore
     */
    public function failStep(string $step, string $error): void
    {
        $this->updateStep($step, [
            'status' => self::STATUS_FAILED,
            'completed_at' => now()->toIso8601String(),
            'error' => $error,
        ]);
    }

    /**
     * Recupera l'ultimo polling attempt registrato per uno step.
     *
     * @param  string  $step  Nome dello step
     * @return int L'ultimo polling attempt registrato (0 se non esiste)
     */
    public function getCurrentPollingAttempt(string $step): int
    {
        return $this->getStep($step)['polling']['attempts'] ?? 0;
    }

    /**
     * Verifica se il polling attempt è valido (non obsoleto).
     *
     * Un job è obsoleto se:
     * - Lo step è già completato
     * - Lo step è già fallito
     * - Il pollingAttempt del job è minore o uguale all'ultimo registrato
     *
     * @param  string  $step  Nome dello step
     * @param  int  $pollingAttempt  Il polling attempt del job corrente
     * @return bool True se il job è valido e può proseguire
     */
    public function isPollingAttemptValid(string $step, int $pollingAttempt): bool
    {
        // Se lo step è già completato o fallito, il job è obsoleto
        if ($this->isStepCompleted($step) || $this->isStepFailed($step)) {
            return false;
        }

        // Se il polling attempt è minore o uguale all'ultimo registrato, è obsoleto
        $currentAttempt = $this->getCurrentPollingAttempt($step);
        if ($currentAttempt > 0 && $pollingAttempt <= $currentAttempt) {
            return false;
        }

        return true;
    }

    /**
     * Salva la response iniziale di uno step (quando viene avviata l'operazione).
     *
     * @param  string  $step  Nome dello step
     * @param  array  $response  Response dell'API
     */
    public function setStepResponse(string $step, array $response): void
    {
        $this->updateStep($step, [
            'response' => $response,
        ]);
    }

    /**
     * Aggiorna i dati di polling per uno step.
     *
     * @param  string  $step  Nome dello step
     * @param  int  $attempt  Numero del tentativo corrente
     * @param  array|null  $tasks  Task recuperati (opzionale)
     */
    public function updatePolling(string $step, int $attempt, ?array $tasks = null): void
    {
        $pollingData = [
            'attempts' => $attempt,
            'last_check_at' => now()->toIso8601String(),
        ];

        if ($tasks !== null) {
            $pollingData['tasks'] = $tasks;
        }

        $this->updateStep($step, [
            'polling' => $pollingData,
        ]);
    }

    /**
     * Salva i dati della trascrizione eScriptorium.
     *
     * @param  array  $transcription  Dati della trascrizione
     */
    public function setTranscription(array $transcription): void
    {
        $this->update(['transcription' => $transcription]);
    }

    /**
     * Aggiorna i dati di uno step specifico.
     *
     * @param  string  $step  Nome dello step
     * @param  array  $data  Dati da aggiungere/aggiornare
     */
    private function updateStep(string $step, array $data): void
    {
        $currentData = $this->getData();
        $currentSteps = $currentData['steps'] ?? [];
        $currentStepData = $currentSteps[$step] ?? [];

        // Merge dei dati mantenendo quelli esistenti
        $currentSteps[$step] = array_merge($currentStepData, $data);

        $this->update(['steps' => $currentSteps]);
    }

    /**
     * Aggiorna i dati di eScriptorium nella trascrizione.
     *
     * @param  array  $data  Dati da aggiungere/aggiornare
     */
    private function update(array $data): void
    {
        $currentServiceData = $this->transcription->service_data ?? [];
        $currentEscriptoriumData = $currentServiceData['escriptorium'] ?? [];

        $this->transcription->update([
            'service_data' => [
                ...$currentServiceData,
                'escriptorium' => [
                    ...$currentEscriptoriumData,
                    ...$data,
                ],
            ],
        ]);

        // Refresh per avere i dati aggiornati
        $this->transcription->refresh();
    }
}
