<?php

namespace App\Http\Requests\eScriptorium;

use App\Facades\eScriptorium;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Avvia una trascrizione OCR da upload diretto di immagini.
 *
 * Questo endpoint permette di caricare file immagine (JPEG, PNG, TIFF)
 * direttamente dal client per la trascrizione automatica.
 */
class ImagesProcessRequest extends FormRequest
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
            'images' => [
                'description' => __('api.body_parameters.images'),
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
                'example' => 'Documento 001',
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
            'source_type' => 'images',
            'segmentation_model_id' => $this->segmentation_model_id === '' ? null : $this->segmentation_model_id,
            'project_name' => $this->project_name === '' ? null : $this->project_name,
            'document_name' => $this->document_name === '' ? null : $this->document_name,
            'transcription_name' => $this->transcription_name === '' ? null : $this->transcription_name,
        ]);
    }

    public function rules(): array
    {
        return [
            'script_id' => ['required', 'integer'],
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:20480'],
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
            'images.required' => __('api.validation.images.required'),
            'images.array' => __('api.validation.images.array'),
            'images.min' => __('api.validation.images.min'),
            'images.*.image' => __('api.validation.images.image'),
            'images.*.max' => __('api.validation.images.max'),
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
        $dataToMerge = [];

        if ($this->filled('script_id')) {
            $scripts = eScriptorium::scripts();
            $scriptId = (int) $this->script_id;
            foreach ($scripts as $script) {
                if ($script['id'] === $scriptId) {
                    $dataToMerge['script_name'] = $script['name'];
                    break;
                }
            }
        }

        $this->merge($dataToMerge);
    }

    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key === null && is_array($validated)) {
            $validated['source_type'] = 'images';

            if ($this->has('script_name')) {
                $validated['script_name'] = $this->input('script_name');
            }
        }

        return $validated;
    }
}
