<?php

namespace App\Http\Requests\eScriptorium;

use App\Facades\eScriptorium;
use App\Rules\PagesRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Avvia una trascrizione OCR da un Manifest IIIF.
 *
 * Questo endpoint scarica le immagini da un server IIIF e avvia
 * il processo di trascrizione automatica.
 */
class ManifestProcessRequest extends FormRequest
{
    /**
     * Documentazione dei parametri per Scramble/OpenAPI.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'script_id' => [
                'description' => __('api.body_parameters.script_id'),
                'example' => 1,
            ],
            'manifest_url' => [
                'description' => __('api.body_parameters.manifest_url'),
                'example' => 'https://digi.vatlib.it/iiif/MSS_Vat.lat.3225/manifest.json',
            ],
            'pages' => [
                'description' => __('api.body_parameters.pages'),
                'example' => '1-5',
            ],
            'recognition_model_id' => [
                'description' => __('api.body_parameters.recognition_model_id'),
                'example' => 142,
            ],
            'segmentation_model_id' => [
                'description' => __('api.body_parameters.segmentation_model_id'),
                'example' => 45,
            ],
            'text_direction' => [
                'description' => __('api.body_parameters.text_direction'),
                'example' => 'horizontal-lr',
            ],
            'project_name' => [
                'description' => __('api.body_parameters.project_name'),
                'example' => 'Manoscritti Vaticani',
            ],
            'document_name' => [
                'description' => __('api.body_parameters.document_name'),
                'example' => 'Vat.lat.3225',
            ],
            'transcription_name' => [
                'description' => __('api.body_parameters.transcription_name'),
                'example' => 'Trascrizione v1.0',
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source_type' => 'manifest',
            'segmentation_model_id' => $this->segmentation_model_id === '' ? null : $this->segmentation_model_id,
            'pages' => $this->pages === '' ? null : $this->pages,
            'project_name' => $this->project_name === '' ? null : $this->project_name,
            'document_name' => $this->document_name === '' ? null : $this->document_name,
            'transcription_name' => $this->transcription_name === '' ? null : $this->transcription_name,
        ]);
    }

    public function rules(): array
    {
        return [
            'script_id' => ['required', 'integer'],
            'manifest_url' => ['required', 'string', 'url'],
            'pages' => ['nullable', 'string', new PagesRange],
            'recognition_model_id' => ['required', 'integer'],
            'segmentation_model_id' => ['nullable', 'integer'],
            'text_direction' => ['required', 'string', 'in:horizontal-lr,horizontal-rl,vertical-lr,vertical-rl'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'document_name' => ['nullable', 'string', 'max:255'],
            'transcription_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'script_id.required' => __('api.validation.script_id.required'),
            'script_id.integer' => __('api.validation.script_id.integer'),
            'manifest_url.required' => __('api.validation.manifest_url.required'),
            'manifest_url.url' => __('api.validation.manifest_url.url'),
            'recognition_model_id.required' => __('api.validation.recognition_model_id.required'),
            'recognition_model_id.integer' => __('api.validation.recognition_model_id.integer'),
            'text_direction.required' => __('api.validation.text_direction.required'),
            'text_direction.in' => __('api.validation.text_direction.in'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('api.responses.validation_failed'),
            'errors' => $validator->errors(),
            'status' => 422,
        ], 422));
    }

    protected function passedValidation(): void
    {
        $dataToMerge = [
            'pages_array' => $this->filled('pages')
                ? $this->parsePagesRange($this->pages)
                : range(1, 10),
        ];

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

    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key === null && is_array($validated)) {
            $validated['source_type'] = 'manifest';

            if ($this->has('pages_array')) {
                $validated['pages_array'] = $this->input('pages_array');
            }

            if ($this->has('script_name')) {
                $validated['script_name'] = $this->input('script_name');
            }
        }

        return $validated;
    }
}
