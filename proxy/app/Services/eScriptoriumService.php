<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class eScriptoriumService
{
    private readonly string $baseUrl;

    private readonly string $username;

    private readonly string $password;

    private readonly string $cachePrefix;

    private readonly int $cacheTtl;

    private const MODEL_TYPE_PREFIX_STRING = '"model_type": "';

    private const MODEL_TYPE_POSTFIX_STRING = '"';

    private const MODEL_TYPES = [
        'segment' => [
            'string' => self::MODEL_TYPE_PREFIX_STRING.'segmentation'.self::MODEL_TYPE_POSTFIX_STRING,
            'name' => 'segment',
        ],
        'recognize' => [
            'string' => self::MODEL_TYPE_PREFIX_STRING.'recognition'.self::MODEL_TYPE_POSTFIX_STRING,
            'name' => 'recognize',
        ],
    ];

    public function __construct()
    {
        $this->baseUrl = rtrim(config('escriptorium.api.base_url'), '/');
        $this->username = config('escriptorium.api.auth.username');
        $this->password = config('escriptorium.api.auth.password');
        $this->cachePrefix = config('escriptorium.cache.prefix');
        $this->cacheTtl = config('escriptorium.cache.ttl');
    }

    /**
     * Check if eScriptorium service is available.
     *
     * @return bool True if the service is up and responding
     */
    public function isUp(): bool
    {
        $response = $this->client()->get($this->baseUrl);

        return $response->successful();
    }

    private function getToken(): string
    {
        return Cache::remember("{$this->cachePrefix}token", now()->addHours($this->cacheTtl), function () {
            $response = Http::post("{$this->baseUrl}/".config('escriptorium.api.endpoints.token_auth'), [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('eScriptorium authentication failed: '.$response->body());
            }

            if (! $response->json('token') || empty($response->json('token'))) {
                throw new \RuntimeException('eScriptorium authentication failed: No token found in response');
            }

            return $response->json('token');
        });
    }

    /**
     * Get the API token (public accessor for use in jobs).
     */
    public function getTokenPublic(): string
    {
        return $this->getToken();
    }

    /**
     * Get a Django session cookie for WebSocket authentication.
     *
     * @return string The session ID
     *
     * @throws \RuntimeException If authentication fails
     */
    public function getSessionCookie(): string
    {
        // Prima richiesta per ottenere CSRF token
        $loginPageResponse = Http::get("{$this->baseUrl}/login/");
        $cookies = $loginPageResponse->cookies();
        $csrfToken = $cookies->getCookieByName('csrftoken')?->getValue();

        if (! $csrfToken) {
            // Prova a estrarre dall'HTML
            preg_match('/name=["\']csrfmiddlewaretoken["\'][^>]*value=["\']([^"\']+)["\']/', $loginPageResponse->body(), $matches);
            $csrfToken = $matches[1] ?? null;
        }

        if (! $csrfToken) {
            throw new \RuntimeException('Could not obtain CSRF token for WebSocket authentication');
        }

        // Converti i cookie in formato semplice chiave => valore
        $cookieArray = [];
        foreach ($cookies->toArray() as $cookie) {
            $cookieArray[$cookie['Name']] = $cookie['Value'];
        }

        // Login per ottenere session cookie
        $response = Http::withCookies($cookieArray, parse_url($this->baseUrl, PHP_URL_HOST))
            ->asForm()
            ->post("{$this->baseUrl}/login/", [
                'username' => $this->username,
                'password' => $this->password,
                'csrfmiddlewaretoken' => $csrfToken,
            ]);

        $sessionCookies = $response->cookies();
        $sessionId = $sessionCookies->getCookieByName('sessionid')?->getValue();

        if (! $sessionId) {
            throw new \RuntimeException('Could not obtain session cookie for WebSocket authentication');
        }

        return $sessionId;
    }

    /**
     * Create a WebSocket client configured for eScriptorium.
     *
     * @param  int|null  $timeout  Connection timeout in seconds (default from config)
     * @return \WebSocket\Client Configured WebSocket client
     *
     * @throws \RuntimeException If connection fails
     */
    public function createWebSocketClient(?int $timeout = null): \WebSocket\Client
    {
        $wsUrl = $this->getWebSocketUrl();
        $sessionId = $this->getSessionCookie();
        $origin = rtrim($this->baseUrl, '/');
        $timeoutSec = $timeout ?? config('escriptorium.websocket.timeout', 600);

        $client = new \WebSocket\Client($wsUrl);

        // Add standard middlewares
        $client->addMiddleware(new \WebSocket\Middleware\CloseHandler);
        $client->addMiddleware(new \WebSocket\Middleware\PingResponder);

        // Set handshake headers
        $client->addHeader('Cookie', 'sessionid='.$sessionId);
        $client->addHeader('Origin', $origin);

        // Set timeout
        $client->setTimeout($timeoutSec);

        return $client;
    }

    /**
     * Get the WebSocket URL for debugging.
     */
    public function getWebSocketUrl(): string
    {
        // Use dedicated WebSocket URL if configured, otherwise derive from API base URL
        $wsBaseUrl = config('escriptorium.websocket.base_url');

        if ($wsBaseUrl) {
            $baseUrl = rtrim($wsBaseUrl, '/');
            // If already ws:// or wss://, use as-is
            if (str_starts_with($baseUrl, 'ws://') || str_starts_with($baseUrl, 'wss://')) {
                $wsUrl = $baseUrl;
            } else {
                $wsUrl = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $baseUrl);
            }
        } else {
            $baseUrl = rtrim($this->baseUrl, '/');
            $wsUrl = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $baseUrl);
        }

        return $wsUrl.'/'.ltrim(config('escriptorium.websocket.endpoint'), '/');
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->getToken(), config('escriptorium.api.headers.token_header'));
    }

    /**
     * Get all available OCR models from eScriptorium.
     *
     * @return array List of available models
     *
     * @throws \RuntimeException If the request fails
     */
    public function models(): array
    {
        $response = $this->client()->get(config('escriptorium.api.endpoints.models'));

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium models request failed');
        }

        $results = $response->json('results') ?? [];

        return $results;
    }

    /**
     * Get all available scripts from eScriptorium.
     *
     * @return array List of available scripts (e.g., Latin, Arabic)
     *
     * @throws \RuntimeException If the request fails
     */
    public function scripts(): array
    {
        $response = $this->client()->get(config('escriptorium.api.endpoints.scripts'));

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium scripts request failed');
        }

        $results = $response->json('results') ?? [];

        return $results;
    }

    /**
     * Upload a new OCR model to eScriptorium via API.
     *
     * @param  string|null  $name  The model name (uses filename if null)
     * @param  UploadedFile  $file  The model file (.mlmodel)
     * @return array The created model data
     *
     * @throws \RuntimeException If the file is invalid or upload fails
     */
    public function newModel(?string $name, UploadedFile $file): array
    {
        if (! $file || ! $file->isValid()) {
            throw new \RuntimeException('eScriptorium new model request failed: Invalid file');
        }

        try {
            $modelType = $this->getModelType($file);
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage());
        }

        $filename = $file->getClientOriginalName();

        $response = $this->client()
            ->asMultipart()
            ->attach(
                name: 'file',
                contents: $file->getContent(),
                filename: $filename
            )
            ->post(config('escriptorium.api.endpoints.models'), [
                'name' => $name ?? $filename,
                'job' => Str::ucfirst($modelType),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium new model request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Upload a new OCR model to eScriptorium simulating browser behavior.
     *
     * This method is used to force accuracy parsing which is not available via API.
     *
     * @param  string|null  $name  The model name (uses filename if null)
     * @param  UploadedFile  $file  The model file (.mlmodel)
     * @return bool True if upload was successful
     *
     * @throws \RuntimeException If the file is invalid, login fails, or upload fails
     */
    public function newModelViaBrowser(?string $name, UploadedFile $file): bool|\RuntimeException
    {
        if (! $file || ! $file->isValid()) {
            throw new \RuntimeException('eScriptorium new model request failed: Invalid file');
        }

        $jar = new CookieJar;

        $browser = Http::withOptions([
            'cookies' => $jar,
            'allow_redirects' => true,
        ])->baseUrl($this->baseUrl);

        $loginPage = $browser->get("{$this->baseUrl}/login/");
        $csrfToken = $this->extractCsrfToken($loginPage->body());

        $loginResponse = $browser->asForm()->post("{$this->baseUrl}/login/", [
            'username' => $this->username,
            'password' => $this->password,
            'csrfmiddlewaretoken' => $csrfToken,
        ]);

        if (! $loginResponse->successful()) {
            throw new \RuntimeException('eScriptorium browser login failed: '.$loginResponse->status());
        }

        $uploadPage = $browser->get("{$this->baseUrl}/models/new/");
        $uploadCsrf = $this->extractCsrfToken($uploadPage->body());

        $originalName = $file->getClientOriginalName();

        $baseName = $name
            ? (Str::endsWith($name, '.mlmodel') ? Str::beforeLast($name, '.mlmodel') : $name)
            : pathinfo($originalName, PATHINFO_FILENAME);

        $filename = "{$baseName}.mlmodel";

        $guzzleClient = new Client([
            'cookies' => $jar,
            'allow_redirects' => true,
            'base_uri' => $this->baseUrl,
        ]);

        $response = $guzzleClient->post("{$this->baseUrl}/models/new/", [
            'multipart' => [
                [
                    'name' => 'name',
                    'contents' => $baseName,
                ],
                [
                    'name' => 'csrfmiddlewaretoken',
                    'contents' => $uploadCsrf,
                ],
                [
                    'name' => 'file',
                    'contents' => fopen($file->getPathname(), 'r'),
                    'filename' => $filename,
                ],
            ],
            'headers' => [
                'Referer' => "{$this->baseUrl}/models/new/",
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('eScriptorium browser upload failed: '.$statusCode);
        }

        return true;
    }

    /**
     * Helper to extract the CSRF token from the HTML input hidden
     */
    private function extractCsrfToken(string $html): string
    {
        if (preg_match('/name=["\']csrfmiddlewaretoken["\'][^>]*value=["\']([^"\']+)["\']/', $html, $matches)) {
            return $matches[1];
        }
        if (preg_match('/value=["\']([^"\']+)["\'][^>]*name=["\']csrfmiddlewaretoken["\']/', $html, $matches)) {
            return $matches[1];
        }

        throw new \RuntimeException('eScriptorium new model request failed: CSRF token not found in HTML response');
    }

    /**
     * Get the model type from the file
     */
    private function getModelType(UploadedFile $file): string
    {
        try {
            $hexFileContent = Str::of(bin2hex($file->getContent()))->trim();

            foreach (self::MODEL_TYPES as $modelType) {
                if ($hexFileContent->contains(bin2hex($modelType['string']))) {
                    return $modelType['name'];
                }
            }

            throw new \RuntimeException('eScriptorium new model request failed: Could not determine model type');
        } catch (\Exception $e) {
            throw new \RuntimeException('eScriptorium new model request failed: '.$e->getMessage());
        }
    }

    /**
     * Create a new project in eScriptorium.
     *
     * @param  string  $name  The project name
     * @return array The created project data (pk, slug, name, etc.)
     *
     * @throws \RuntimeException If name is empty or creation fails
     */
    public function createProject(string $name): array
    {
        if (! $name || empty($name)) {
            throw new \RuntimeException('eScriptorium create project request failed: Name is required');
        }

        $response = $this->client()->post(config('escriptorium.api.endpoints.projects'), [
            'name' => $name,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium create project request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create a new document in eScriptorium within a project.
     *
     * @param  string  $name  The document name
     * @param  string  $projectSlug  The project slug (not the pk)
     * @param  string  $mainScriptName  The main script name (e.g., "Latin", "Arabic")
     * @return array The created document data (pk, name, project, etc.)
     *
     * @throws \RuntimeException If required parameters are empty or creation fails
     */
    public function createDocument(string $name, string $projectSlug, string $mainScriptName): array
    {
        if (! $name || empty($name)) {
            throw new \RuntimeException('eScriptorium create document request failed: Name is required');
        }

        if (! $projectSlug || empty($projectSlug)) {
            throw new \RuntimeException('eScriptorium create document request failed: Project slug is required');
        }

        if (! $mainScriptName || empty($mainScriptName)) {
            throw new \RuntimeException('eScriptorium create document request failed: Main script name is required');
        }

        $response = $this->client()->post(config('escriptorium.api.endpoints.documents'), [
            'name' => $name,
            'project' => $projectSlug,
            'main_script' => $mainScriptName,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium create document request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Import images into a document from a IIIF manifest.
     *
     * This is an ASYNCHRONOUS operation. Use tasks() to poll for completion.
     *
     * @param  string  $documentId  The document pk
     * @param  string  $mode  Import mode (e.g., "iiif", "pdf", "mets", "xml")
     * @param  string  $iiifUri  The IIIF manifest URI
     * @param  string  $name  The transcription name
     * @return array Response with status
     *
     * @throws \RuntimeException If required parameters are empty or import fails
     */
    public function importDocument(string $documentId, string $mode, string $iiifUri, string $name): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium import document request failed: Document ID is required');
        }

        if (! $iiifUri || empty($iiifUri)) {
            throw new \RuntimeException('eScriptorium import document request failed: IIIF URI is required');
        }

        if (! $name || empty($name)) {
            throw new \RuntimeException('eScriptorium import document request failed: Name is required');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.import'));

        $response = $this->client()->post($endpoint, [
            'mode' => $mode ?? 'iiif',
            'iiif_uri' => $iiifUri,
            'name' => $name,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium import document request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get tasks for a document to check async operation status.
     *
     * Use this to poll for completion of import, segment, transcribe operations.
     * Task workflow_state: 0=Queued, 1=Running, 2=Crashed, 3=Finished, 4=Canceled.
     *
     * @param  string  $documentId  The document pk
     * @return array Paginated list of tasks with their status
     *
     * @throws \RuntimeException If document ID is empty or request fails
     */
    public function tasks(string $documentId): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium tasks request failed: Document ID is required');
        }

        $response = $this->client()->get(config('escriptorium.api.endpoints.tasks'), [
            'document' => $documentId,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium check import document status request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get parts for a document.
     *
     * @param  string  $documentId  The document pk
     * @return array Paginated list of parts
     *
     * @throws \RuntimeException If document ID is empty or request fails
     */
    public function getDocumentParts(string $documentId): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium get document parts request failed: Document ID is required');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.parts'));

        $response = $this->client()->get($endpoint, [
            'ordering' => 'order',
            'paginate_by' => 5000,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium get document parts request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Start segmentation on a document.
     *
     * This is an ASYNCHRONOUS operation. Use tasks() to poll for completion.
     *
     * @param  string  $documentId  The document pk
     * @param  int|null  $modelId  The segmentation model pk (optional, uses default if null)
     * @param  array|null  $parts  The parts to segment (optional)
     * @param  string  $steps  Steps to perform: "both", "lines", "masks", "regions"
     * @param  bool  $override  Whether to override existing segmentation
     * @param  string  $textDirection  Text direction: "horizontal-lr", "horizontal-rl", "vertical-lr", "vertical-rl"
     * @return array Response with status
     *
     * @throws \RuntimeException If document ID is empty or segmentation fails
     */
    public function segmentDocument(string $documentId, ?array $parts, ?int $modelId = null, string $steps = 'both', bool $override = true, string $textDirection = 'horizontal-lr'): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium segment document request failed: Document ID is required');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.segment'));

        $payload = [
            'steps' => $steps,
            'override' => $override,
            'text_direction' => $textDirection,
        ];

        if ($modelId) {
            $payload['model'] = $modelId;
        }

        if ($parts && ! empty($parts) && \is_array($parts)) {
            $payload['parts'] = $parts;
        }

        $response = $this->client()->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium segment document request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create a new transcription layer for a document.
     *
     * This is a SYNCHRONOUS operation. The transcription pk is immediately available.
     * If a transcription with the same name exists, returns the existing one.
     *
     * @param  string  $documentId  The document pk
     * @param  string  $name  The transcription name
     * @return array The created/existing transcription data (pk, name, etc.)
     *
     * @throws \RuntimeException If required parameters are empty or creation fails
     */
    public function createTranscription(string $documentId, string $name): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium create transcription request failed: Document ID is required');
        }

        if (! $name || empty($name)) {
            throw new \RuntimeException('eScriptorium create transcription request failed: Name is required');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.transcriptions'));

        $response = $this->client()->post($endpoint, [
            'name' => $name,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium create transcription request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Start OCR transcription on a document.
     *
     * This is an ASYNCHRONOUS operation. Use tasks() to poll for completion.
     *
     * @param  string  $documentId  The document pk
     * @param  array|null  $parts  The parts to transcribe (optional)
     * @param  string  $transcriptionId  The transcription pk (destination for OCR output)
     * @param  string  $modelId  The recognition model pk (must be type "recognize")
     * @return array Response with status
     *
     * @throws \RuntimeException If required parameters are empty or transcription fails
     */
    public function transcribeTranscription(string $documentId, ?array $parts, string $transcriptionId, string $modelId): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium transcribe transcription request failed: Document ID is required');
        }

        if (! $transcriptionId || empty($transcriptionId)) {
            throw new \RuntimeException('eScriptorium transcribe transcription request failed: Transcription ID is required');
        }

        if (! $modelId || empty($modelId)) {
            throw new \RuntimeException('eScriptorium transcribe transcription request failed: Model ID is required');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.transcribe'));

        $payload = [
            'model' => $modelId,
            'transcription' => $transcriptionId,
        ];

        if ($parts && ! empty($parts) && \is_array($parts)) {
            $payload['parts'] = $parts;
        }

        $response = $this->client()->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium transcribe transcription request failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Delete a project from eScriptorium.
     *
     * @param  int|string  $projectId  The project ID
     * @return bool True if deletion was successful
     *
     * @throws \RuntimeException If deletion fails
     */
    public function deleteProject(int|string $projectId): bool
    {
        if (! $projectId) {
            throw new \RuntimeException('eScriptorium delete project request failed: Project ID is required');
        }

        $endpoint = config('escriptorium.api.endpoints.projects').$projectId.'/';

        $response = $this->client()->delete($endpoint);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium delete project request failed: '.$response->body());
        }

        return true;
    }

    /**
     * Trigger document export on eScriptorium.
     *
     * This is an ASYNCHRONOUS operation. The export completion is notified via WebSocket.
     *
     * @param  string  $documentId  The document pk
     * @param  string  $transcriptionId  The transcription pk
     * @param  array  $partsPks  The parts to export
     * @param  string  $format  Export format (e.g., "teixml", "alto", "pagexml", "text")
     * @return array Response with status
     *
     * @throws \RuntimeException If export request fails
     */
    public function exportDocument(string $documentId, string $transcriptionId, array $partsPks = [], string $format = 'teixml'): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium export document request failed: Document ID is required');
        }

        if (! $transcriptionId || empty($transcriptionId)) {
            throw new \RuntimeException('eScriptorium export document request failed: Transcription ID is required');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.export'));

        $response = $this->client()->post($endpoint, [
            'file_format' => $format,
            'include_characters' => false,
            'include_images' => false,
            'transcription' => $transcriptionId,
            'parts' => $partsPks,
            'region_types' => ['2'],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium export document request failed: '.$response->body());
        }

        return $response->json() ?? ['status' => 'ok'];
    }

    /**
     * Export transcription as TEI XML from eScriptorium.
     *
     * Uses the custom synchronous endpoint to get TEI XML content directly.
     *
     * @param  string  $documentId  The document pk
     * @param  string  $transcriptionId  The eScriptorium transcription pk
     * @return string The TEI XML content
     *
     * @throws \RuntimeException If export fails
     */
    public function exportTeiXml(string $documentId, string $transcriptionId): string
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium TEI export failed: Document ID is required');
        }

        if (! $transcriptionId || empty($transcriptionId)) {
            throw new \RuntimeException('eScriptorium TEI export failed: Transcription ID is required');
        }

        $endpoint = str_replace(
            ['{document_id}', '{transcription_id}'],
            [$documentId, $transcriptionId],
            config('escriptorium.api.endpoints.tei_export')
        );

        $response = $this->client()->get($endpoint);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium TEI export failed: '.$response->body());
        }

        $teiXml = $response->json('tei_xml');

        if (! $teiXml || empty($teiXml)) {
            throw new \RuntimeException('eScriptorium TEI export failed: No TEI XML content in response');
        }

        // Handle multi-file response (when document has multiple pages)
        // The response can be either a string or {'files': [...]} array
        if (is_array($teiXml)) {
            if (isset($teiXml['files']) && is_array($teiXml['files'])) {
                // Combine all file contents into one TEI document
                $contents = [];
                $errors = [];
                $filesCount = count($teiXml['files']);

                foreach ($teiXml['files'] as $file) {
                    if (isset($file['content']) && ! empty($file['content'])) {
                        $contents[] = $file['content'];
                    }
                    if (isset($file['error'])) {
                        $errors[] = ($file['filename'] ?? 'unknown').': '.$file['error'];
                    }
                }

                // Merge the TEI contents properly with page breaks
                $teiXml = $this->mergeTeiContents($contents);

                // If no content but there are errors, include them in exception
                if (empty($teiXml) && ! empty($errors)) {
                    throw new \RuntimeException('eScriptorium TEI export failed: '.implode('; ', $errors));
                }
            } else {
                // Unknown array format, convert to string
                $teiXml = json_encode($teiXml);
            }
        }

        // Handle string response with multiple concatenated TEI documents
        // Split by XML declaration and merge if multiple documents found
        if (is_string($teiXml) && substr_count($teiXml, '<?xml') > 1) {
            // Split by XML declaration (keeping the declaration with each part)
            $parts = preg_split('/(?=<\?xml)/', $teiXml, -1, PREG_SPLIT_NO_EMPTY);

            if (count($parts) > 1) {
                $teiXml = $this->mergeTeiContents($parts);
            }
        }

        if (empty($teiXml)) {
            throw new \RuntimeException('eScriptorium TEI export failed: No TEI XML content after processing');
        }

        return $teiXml;
    }

    /**
     * Merge multiple TEI XML contents into a single valid TEI document.
     *
     * Extracts body content from each TEI file, combines them with <pb/> page breaks,
     * and creates a single TEI document using the header from the first file.
     *
     * @param  array<string>  $contents  Array of TEI XML strings
     * @return string Merged TEI XML document
     */
    private function mergeTeiContents(array $contents): string
    {
        if (empty($contents)) {
            return '';
        }

        // If single file, return as is
        if (count($contents) === 1) {
            return $contents[0];
        }

        $firstHeader = null;
        $pageContents = [];

        $pageNum = 1;
        foreach ($contents as $content) {
            // Extract header from first file only
            if ($firstHeader === null) {
                if (preg_match('/<teiHeader[^>]*>.*?<\/teiHeader>/s', $content, $headerMatch)) {
                    $firstHeader = $headerMatch[0];
                }
            }

            // Extract body inner content (everything inside <body>...</body>)
            if (preg_match('/<body[^>]*>(.*?)<\/body>/s', $content, $bodyMatch)) {
                $bodyInner = trim($bodyMatch[1]);
                // Add page break marker with page number
                $pageContents[] = "<pb n=\"{$pageNum}\"/>\n{$bodyInner}";
                $pageNum++;
            }
        }
        unset($pageNum);

        if (empty($pageContents)) {
            // Fallback to simple join if extraction fails
            return implode("\n\n", $contents);
        }

        // Use default header if none found
        if (! $firstHeader) {
            $date = now()->toIso8601String();
            $firstHeader = <<<XML
<teiHeader>
    <fileDesc>
      <titleStmt>
        <title>TEI Document</title>
      </titleStmt>
      <publicationStmt>
        <p>Imported from eScriptorium</p>
      </publicationStmt>
      <sourceDesc>
        <p>Created: {$date}</p>
      </sourceDesc>
    </fileDesc>
  </teiHeader>
XML;
        }

        // Build merged TEI document
        $mergedBody = implode("\n        ", $pageContents);

        return <<<XML
<?xml version='1.0' encoding='UTF-8'?>
<TEI xmlns="http://www.tei-c.org/ns/1.0">
  {$firstHeader}
  <text>
    <body>
      <div>
        {$mergedBody}
      </div>
    </body>
  </text>
</TEI>
XML;
    }
}
