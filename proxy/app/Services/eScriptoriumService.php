<?php

namespace App\Services;

use App\Contexts\ApiContext;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Get the current API token.
     *
     * Respects the ApiContext: uses direct token if in direct mode,
     * otherwise uses the service account token.
     */
    public function getCurrentToken(): string
    {
        return ApiContext::isDirectMode()
            ? ApiContext::getDirectToken()
            : $this->getToken();
    }

    /**
     * Get a Django session cookie for WebSocket authentication.
     *
     * In Direct Mode, creates a Django session directly in PostgreSQL for the user's API token.
     * In Service Mode, logs in with service account credentials.
     *
     * @return string The session ID
     *
     * @throws \RuntimeException If authentication fails
     */
    public function getSessionCookie(): string
    {
        // In Direct Mode, create session directly from the user's API token
        if (ApiContext::isDirectMode()) {
            $djangoSessionService = new DjangoSessionService;
            $sessionId = $djangoSessionService->createSessionForToken(ApiContext::getDirectToken());

            if (! $sessionId) {
                throw new \RuntimeException('Could not create Django session for WebSocket authentication in Direct Mode');
            }

            Log::debug('eScriptoriumService: Created Django session for Direct Mode WebSocket', [
                'session_id' => substr($sessionId, 0, 8).'...',
            ]);

            return $sessionId;
        }

        // Service Mode: login with service account credentials
        // Prima richiesta per ottenere CSRF token
        $loginPageResponse = Http::get("{$this->baseUrl}/login/");
        $cookies = $loginPageResponse->cookies();
        $csrfToken = $cookies->getCookieByName('csrftoken')?->getValue();

        if (! $csrfToken) {
            $csrfToken = $this->getCSRFTokenFromPage($loginPageResponse->body());
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

    private function getCSRFTokenFromPage(string $html): string
    {
        preg_match('/name=["\']csrfmiddlewaretoken["\'][^>]*value=["\']([^"\']+)/', $html, $matches);

        return $matches[1] ?? null;
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
        $token = ApiContext::isDirectMode()
            ? ApiContext::getDirectToken()
            : $this->getToken();

        return Http::baseUrl($this->baseUrl)
            ->withToken($token, config('escriptorium.api.headers.token_header'));
    }

    /**
     * Get the current authenticated user info from eScriptorium.
     *
     * @return array User info (pk, username, email, etc.)
     *
     * @throws \RuntimeException If the request fails
     */
    public function getCurrentUser(): array
    {
        $response = $this->client()->get('api/users/current/');

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium get current user request failed: '.$response->body());
        }

        return $response->json();
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
     * Recupera un documento da eScriptorium.
     *
     * @param  string  $documentId  ID del documento (pk)
     * @return array Dati del documento (pk, name, valid_block_types, etc.)
     *
     * @throws \RuntimeException Se la richiesta fallisce
     */
    public function getDocument(string $documentId): array
    {
        $endpoint = config('escriptorium.api.endpoints.documents').$documentId.'/';
        $response = $this->client()->get($endpoint);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium get document request failed: '.$response->body());
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
     * Upload an image as a new part of the document.
     *
     * @param  string  $documentId  The document pk
     * @param  string|resource  $fileContent  The file content or stream
     * @param  string  $filename  The filename
     * @return array The created part data
     *
     * @throws \RuntimeException If required parameters are empty or upload fails
     */
    public function uploadPart(string $documentId, $fileContent, string $filename): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium upload part request failed: Document ID is required');
        }

        if (empty($fileContent)) {
            throw new \RuntimeException('eScriptorium upload part request failed: File content is empty');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.parts'));

        $response = $this->client()
            ->asMultipart()
            ->attach(
                'image',
                $fileContent,
                $filename
            )
            ->post($endpoint);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium upload part request failed: '.$response->body());
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
    public function exportDocument(string $documentId, string $transcriptionId, array $partsPks = [], string $format = 'teixml', array $regionTypes = []): array
    {
        if (! $documentId || empty($documentId)) {
            throw new \RuntimeException('eScriptorium export document request failed: Document ID is required');
        }

        if (! $transcriptionId || empty($transcriptionId)) {
            throw new \RuntimeException('eScriptorium export document request failed: Transcription ID is required');
        }

        if (! $partsPks || empty($partsPks)) {
            throw new \RuntimeException('eScriptorium export document request failed: Parts PKS are required');
        }

        if (! $regionTypes || empty($regionTypes)) {
            throw new \RuntimeException('eScriptorium export document request failed: Region types are required');
        }

        $endpoint = str_replace('{document_id}', $documentId, config('escriptorium.api.endpoints.export'));

        $payload = [
            'file_format' => $format,
            'include_characters' => false,
            'include_images' => false,
            'transcription' => $transcriptionId,
            'parts' => $partsPks,
            'region_types' => [...$regionTypes, 'Undefined', 'Orphan'],
        ];

        if (app()->isLocal()) {
            Log::info('📤 [eScriptorium] Export document payload', $payload);
        }

        $response = $this->client()->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium export document request failed: '.$response->body());
        }

        return $response->json() ?? ['status' => 'ok'];
    }

    /**
     * Merge multiple TEI XML contents into a single valid TEI document.
     *
     * Features:
     * - Uses DOMDocument for robust XML parsing
     * - Creates proper <facsimile> section with all page images
     * - Adds merge metadata to header
     * - Validates output XML
     *
     * @param  array<string>  $contents  Array of TEI XML strings (already sorted by page number)
     * @return string Merged TEI XML document
     *
     * @throws \RuntimeException If XML parsing or validation fails
     */
    public function mergeTeiContents(array $contents): string
    {
        if (empty($contents)) {
            return '';
        }

        $pageCount = count($contents);
        $facsimileEntries = [];
        $bodyContents = [];
        $baseHeader = null;

        foreach ($contents as $pageNum => $content) {
            $pageId = 'page'.($pageNum + 1);

            // Parse XML with DOM
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;

            // Suppress warnings for malformed XML, handle gracefully
            if (! @$dom->loadXML($content)) {
                Log::warning('[TEI Merge] Failed to parse XML for page '.($pageNum + 1));

                continue;
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('tei', 'http://www.tei-c.org/ns/1.0');

            // Extract header from first file only (without xenoData)
            if ($baseHeader === null) {
                $headerNode = $xpath->query('//tei:teiHeader | //teiHeader')->item(0);
                if ($headerNode) {
                    // Clone and remove xenoData
                    $headerClone = $headerNode->cloneNode(true);
                    $xenoNodes = $headerClone->getElementsByTagName('xenoData');
                    while ($xenoNodes->length > 0) {
                        $xenoNodes->item(0)->parentNode->removeChild($xenoNodes->item(0));
                    }
                    $tempDom = new \DOMDocument('1.0', 'UTF-8');
                    $tempDom->appendChild($tempDom->importNode($headerClone, true));
                    $baseHeader = $tempDom->saveXML($tempDom->documentElement);
                }
            }

            // Extract source URL from xenoData
            $facsUrl = '';
            $xenoDataNodes = $xpath->query('//tei:xenoData | //xenoData');
            if ($xenoDataNodes->length > 0) {
                $xenoText = $xenoDataNodes->item(0)->textContent;
                if (preg_match('/SOURCE:\s*(\S+)/m', $xenoText, $match)) {
                    $facsUrl = trim($match[1]);
                }
            }

            // Build facsimile entry
            if ($facsUrl) {
                $facsimileEntries[] = sprintf(
                    '    <surface xml:id="%s"><graphic url="%s"/></surface>',
                    $pageId,
                    htmlspecialchars($facsUrl, ENT_XML1)
                );
            }

            // Extract body content
            $bodyNodes = $xpath->query('//tei:body | //body');
            if ($bodyNodes->length > 0) {
                $bodyNode = $bodyNodes->item(0);
                $bodyInnerHtml = '';
                foreach ($bodyNode->childNodes as $child) {
                    $bodyInnerHtml .= $dom->saveXML($child);
                }

                // Create page break with facsimile reference
                $pbTag = $facsUrl
                    ? sprintf('<pb n="%d" facs="#%s"/>', $pageNum + 1, $pageId)
                    : sprintf('<pb n="%d"/>', $pageNum + 1);

                $bodyContents[] = $pbTag."\n".trim($bodyInnerHtml);
            }
        }

        if (empty($bodyContents)) {
            throw new \RuntimeException('TEI merge failed: no body content extracted');
        }

        // Build default header if none found
        if (! $baseHeader) {
            $baseHeader = $this->buildDefaultTeiHeader();
        }

        // Inject merge metadata into header
        $baseHeader = $this->injectMergeMetadata($baseHeader, $pageCount);

        // Build facsimile section
        $facsimileSection = '';
        if (! empty($facsimileEntries)) {
            $facsimileSection = "  <facsimile>\n".implode("\n", $facsimileEntries)."\n  </facsimile>\n";
        }

        // Build final document
        $mergedBody = implode("\n        ", $bodyContents);

        $teiDocument = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<TEI xmlns="http://www.tei-c.org/ns/1.0">
  {$baseHeader}
{$facsimileSection}  <text>
    <body>
      <div>
        {$mergedBody}
      </div>
    </body>
  </text>
</TEI>
XML;

        // Validate output XML
        $this->validateXml($teiDocument);

        return $teiDocument;
    }

    /**
     * Build a default TEI header when none is available.
     */
    private function buildDefaultTeiHeader(): string
    {
        $date = now()->toIso8601String();

        return <<<XML
<teiHeader>
    <fileDesc>
      <titleStmt>
        <title>Merged TEI Document</title>
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

    /**
     * Inject merge metadata into the TEI header.
     */
    private function injectMergeMetadata(string $header, int $pageCount): string
    {
        $date = now()->toIso8601String();

        $revisionDesc = <<<XML

    <revisionDesc>
      <change when="{$date}">
        <p>Merged {$pageCount} pages from eScriptorium export</p>
      </change>
    </revisionDesc>
XML;

        // Insert before closing </teiHeader>
        return preg_replace('/<\/teiHeader>/', $revisionDesc."\n  </teiHeader>", $header);
    }

    /**
     * Validate that the XML document is well-formed.
     *
     * @throws \RuntimeException If XML is invalid
     */
    private function validateXml(string $xml): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');

        // Use internal errors to get detailed error info
        $previousErrorState = libxml_use_internal_errors(true);

        $result = $dom->loadXML($xml);

        if (! $result) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);

            $errorMessages = array_map(fn ($e) => trim($e->message), $errors);
            throw new \RuntimeException('TEI XML validation failed: '.implode('; ', $errorMessages));
        }

        libxml_use_internal_errors($previousErrorState);
    }

    /**
     * Extract plain text from TEI XML content.
     *
     * Removes all XML markup and returns only the text content,
     * useful for full-text search indexing.
     *
     * @param  string  $teiXml  TEI XML document
     * @return string Plain text content
     */
    public function extractPlainText(string $teiXml): string
    {
        if (empty($teiXml)) {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');

        if (! @$dom->loadXML($teiXml)) {
            // Fallback: strip tags if XML parsing fails
            return strip_tags($teiXml);
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('tei', 'http://www.tei-c.org/ns/1.0');

        // Get body text content
        $bodyNodes = $xpath->query('//tei:body | //body');

        if ($bodyNodes->length === 0) {
            return '';
        }

        $text = $bodyNodes->item(0)->textContent;

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Get a list of projects, optionally filtered by name.
     *
     * @param  string|null  $name  Project name to search for
     * @return array List of projects
     */
    public function getProjects(?string $name = null): array
    {
        $params = [];
        if ($name) {
            $params['name'] = $name;
        }

        $response = $this->client()->get(config('escriptorium.api.endpoints.projects'), $params);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium get projects request failed: '.$response->body());
        }

        $results = $response->json('results') ?? [];

        // Manual check if API does not support exact name filtering, ensuring we match exactly
        if ($name) {
            $results = array_values(array_filter($results, fn ($p) => $p['name'] === $name));
        }

        return $results;
    }

    /**
     * Get documents for a project, optionally filtered by name.
     *
     * @param  int|string  $projectId  Project ID
     * @param  string|null  $name  Document name to search for
     * @return array List of documents
     */
    public function getDocuments(int|string $projectId, ?string $name = null): array
    {
        $params = ['project' => $projectId];
        if ($name) {
            $params['name'] = $name;
        }

        $response = $this->client()->get(config('escriptorium.api.endpoints.documents'), $params);

        if (! $response->successful()) {
            throw new \RuntimeException('eScriptorium get documents request failed: '.$response->body());
        }

        $results = $response->json('results') ?? [];

        // Manual check for exact name match
        if ($name) {
            $results = array_values(array_filter($results, fn ($d) => $d['name'] === $name));
        }

        return $results;
    }
}
