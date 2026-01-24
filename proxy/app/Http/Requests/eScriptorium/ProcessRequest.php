<?php

namespace App\Http\Requests\eScriptorium;

use App\Enums\ProcessSourceEnum;
use App\Facades\eScriptorium;
use App\Rules\PagesRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * @property int $script_id ID del sistema di scrittura (es. 1). Ottenibile da /v1/scripts.
 * @property string $source_type Tipo di sorgente: 'manifest' o 'images'.
 * @property string|null $manifest_url URL del manifest IIIF (obbligatorio se source_type='manifest').
 * @property array|null $images Elenco immagini da caricare (obbligatorio se source_type='images').
 * @property string|null $pages Range di pagine (es. "1-5, 8"). Default: prime 10 pagine. Solo per manifest.
 * @property int $recognition_model_id ID modello OCR (es. 12).
 * @property int|null $segmentation_model_id ID modello segmentazione (es. 5).
 * @property string $text_direction Direzione testo (es. "horizontal-lr").
 * @property string|null $project_name Nome del progetto su eScriptorium (opzionale).
 * @property string|null $document_name Nome del documento su eScriptorium (opzionale).
 * @property string|null $transcription_name Nome del layer di trascrizione (opzionale).
 */
class ProcessRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function bodyParameters(): array
    {
        return [
            'script_id' => [
                'description' => 'ID univoco del sistema di scrittura (Script). Ottenibile dall\'endpoint `/v1/scripts`. Fondamentale per la corretta inizializzazione del documento su eScriptorium.',
                'example' => 1,
            ],
            'source_type' => [
                'description' => 'Modalità di acquisizione delle immagini:
                - `manifest`: Scarica le immagini da un URL manifesto IIIF pubblico.
                - `images`: Carica direttamente i file immagine raw dal client.',
                'example' => 'manifest',
            ],
            'manifest_url' => [
                'description' => 'URL pubblico del manifesto IIIF. Richiesto obbligatoriamente se `source_type` è `manifest`.',
                'example' => 'https://digi.vatlib.it/iiif/MSS_Vat.lat.3225/manifest.json',
            ],
            'images' => [
                'description' => 'Array di file immagine da caricare. Richiesto obbligatoriamente se `source_type` è `images`.
                - **Formato**: Multipart/form-data (`images[]`)
                - **Limiti**: Max 20MB per singolo file
                - **Tipi**: JPEG, PNG, TIFF',
            ],
            'pages' => [
                'description' => 'Selettore delle pagine da elaborare.
                - **Manifest**: Stringa tipo "1-5, 8, 10-12". Se omesso, vengono elaborate le prime 10 pagine.
                - **Images**: Ignorato. Vengono elaborate tutte le immagini caricate (es. se carichi 20 file, il range sarà 1-20).',
                'example' => '1-5, 10',
            ],
            'recognition_model_id' => [
                'description' => 'ID del modello HTR (Handwritten Text Recognition) da utilizzare per la trascrizione. Ottenibile da `/v1/models` (filtrare per job=Recognize).',
                'example' => 12,
            ],
            'segmentation_model_id' => [
                'description' => 'ID del modello di segmentazione (Layout Analysis). Ottenibile da `/v1/models` (filtrare per job=Segment). Se omesso, verrà usato il modello di default se configurato.',
                'example' => 5,
            ],
            'text_direction' => [
                'description' => 'Direzione principale del testo nel documento. Valori ammessi:
                - `horizontal-lr`: Orizzontale, da sinistra a destra (es. Latino, Italiano)
                - `horizontal-rl`: Orizzontale, da destra a sinistra (es. Arabo, Ebraico)
                - `vertical-lr`: Verticale, da sinistra a destra
                - `vertical-rl`: Verticale, da destra a sinistra (es. Cinese tradizionale)
                - `ttb`: Top to Bottom',
                'example' => 'horizontal-lr',
            ],
            'project_name' => [
                'description' => 'Nome del progetto contenitore su eScriptorium. Utilizzato per raggruppare i documenti.
                Se lasciato vuoto, verrà generato un nome casuale univoco.',
                'example' => 'Progetto Manoscritti Vaticani',
            ],
            'document_name' => [
                'description' => 'Nome del documento creato su eScriptorium.
                Se lasciato vuoto, verrà generato un nome casuale univoco.',
                'example' => 'Vat. Lat. 3225',
            ],
            'transcription_name' => [
                'description' => 'Etichetta per il layer di trascrizione generato (utile per versioning).
                Se lasciato vuoto, verrà generato un nome casuale.',
                'example' => 'Trascrizione Automatica v1.0',
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     * Convert empty strings to null for nullable fields.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'segmentation_model_id' => $this->segmentation_model_id === '' ? null : $this->segmentation_model_id,
            'pages' => $this->pages === '' ? null : $this->pages,
            'project_name' => $this->project_name === '' ? null : $this->project_name,
            'document_name' => $this->document_name === '' ? null : $this->document_name,
            'transcription_name' => $this->transcription_name === '' ? null : $this->transcription_name,
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'script_id' => ['required', 'integer'],
            'source_type' => ['required', 'string', Rule::enum(ProcessSourceEnum::class)],
            'manifest_url' => ['required_if:source_type,manifest', 'nullable', 'string', 'url'],
            'images' => ['required_if:source_type,images', 'nullable', 'array', 'min:1'],
            'images.*' => ['image', 'max:20480'],
            'pages' => ['nullable', 'string', new PagesRange],
            'recognition_model_id' => ['required', 'integer'],
            'segmentation_model_id' => ['nullable', 'integer'],
            'text_direction' => ['required', 'string', 'in:horizontal-lr,horizontal-rl,vertical-lr,vertical-rl'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'document_name' => ['nullable', 'string', 'max:255'],
            'transcription_name' => ['nullable', 'string', 'max:255'],
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'script_id.required' => __('validation.escriptorium.process.script_id.required'),
            'script_id.integer' => __('validation.escriptorium.process.script_id.integer'),
            'script_id.in' => __('validation.escriptorium.process.script_id.in'),
            'source_type.required' => __('validation.escriptorium.process.source_type.required'),
            'source_type.enum' => __('validation.escriptorium.process.source_type.enum'),
            'images.required_if' => __('validation.escriptorium.process.images.required_if'),
            'images.array' => __('validation.escriptorium.process.images.array'),
            'images.min' => __('validation.escriptorium.process.images.min'),
            'manifest_url.required_if' => __('validation.escriptorium.process.manifest_url.required_if'),
            'manifest_url.string' => __('validation.escriptorium.process.manifest_url.string'),
            'manifest_url.url' => __('validation.escriptorium.process.manifest_url.url'),
            'pages.nullable' => __('validation.escriptorium.process.pages.nullable'),
            'pages.string' => __('validation.escriptorium.process.pages.string'),
            'recognition_model_id.required' => __('validation.escriptorium.process.recognition_model_id.required'),
            'recognition_model_id.integer' => __('validation.escriptorium.process.recognition_model_id.integer'),
            'recognition_model_id.in' => __('validation.escriptorium.process.recognition_model_id.in'),
            'segmentation_model_id.nullable' => __('validation.escriptorium.process.segmentation_model_id.nullable'),
            'segmentation_model_id.integer' => __('validation.escriptorium.process.segmentation_model_id.integer'),
            'segmentation_model_id.in' => __('validation.escriptorium.process.segmentation_model_id.in'),
            'text_direction.required' => __('validation.escriptorium.process.text_direction.required'),
            'text_direction.string' => __('validation.escriptorium.process.text_direction.string'),
            'text_direction.in' => __('validation.escriptorium.process.text_direction.in'),
            'project_name.string' => __('validation.escriptorium.process.project_name.string'),
            'project_name.max' => __('validation.escriptorium.process.project_name.max'),
            'document_name.string' => __('validation.escriptorium.process.document_name.string'),
            'document_name.max' => __('validation.escriptorium.process.document_name.max'),
            'transcription_name.string' => __('validation.escriptorium.process.transcription_name.string'),
            'transcription_name.max' => __('validation.escriptorium.process.transcription_name.max'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('validation.escriptorium.process.validation_failed'),
            'errors' => $validator->errors(),
            'status' => 422,
        ], 422));
    }

    /**
     * Handle a passed validation attempt.
     * Parse the pages string into an array of page numbers.
     * Resolve script_id to script_name.
     */
    protected function passedValidation(): void
    {
        $dataToMerge = [
            'pages_array' => $this->filled('pages')
                ? $this->parsePagesRange($this->pages)
                : range(1, 10),
        ];

        // Resolve script_name from script_id
        if ($this->filled('script_id')) {
            $scripts = eScriptorium::scripts();
            $scriptId = (int) $this->script_id;
            foreach ($scripts as $script) {
                if ($script['pk'] === $scriptId) {
                    $dataToMerge['script_name'] = $script['name'];
                    break;
                }
            }
        }

        $this->merge($dataToMerge);
    }

    /**
     * Parse a pages range string into an array of page numbers.
     *
     * @param  string  $pagesString  e.g. "3-6,8,12"
     * @return array<int> e.g. [3, 4, 5, 6, 8, 12]
     */
    protected function parsePagesRange(string $pagesString): array
    {
        $pages = [];
        $segments = explode(',', $pagesString);

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if (str_contains($segment, '-')) {
                [$start, $end] = explode('-', $segment);
                for ($i = (int) $start; $i <= (int) $end; $i++) {
                    $pages[] = $i;
                }
            } else {
                $pages[] = (int) $segment;
            }
        }

        return $pages;
    }

    /**
     * Get the parsed pages array.
     *
     * @return array<int>|null
     */
    public function getPagesArray(): ?array
    {
        return $this->input('pages_array');
    }

    /**
     * Get the validated data from the request.
     * Includes the parsed pages_array if pages was provided.
     * Includes script_name resolved from script_id.
     *
     * @param  array|int|string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key === null && is_array($validated)) {
            // Add pages_array
            $pagesArray = $this->getPagesArray();
            if ($pagesArray !== null) {
                $validated['pages_array'] = $pagesArray;
            }

            // Add script_name
            if ($this->has('script_name')) {
                $validated['script_name'] = $this->input('script_name');
            }
        }

        if ($key === 'pages_array') {
            return $this->getPagesArray() ?? $default;
        }

        if ($key === 'script_name') {
            return $this->input('script_name') ?? $default;
        }

        return $validated;
    }
}
