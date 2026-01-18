<?php

namespace App\Http\Requests\eScriptorium;

use App\Facades\eScriptorium;
use App\Rules\PagesRange;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $scripts = eScriptorium::scripts();
        $scriptNames = implode(',', array_column($scripts ?? [], 'name'));

        $models = eScriptorium::models();
        $segmentationModels = array_filter($models ?? [], fn ($model) => $model['job'] === 'Segment');
        $recognitionModels = array_filter($models ?? [], fn ($model) => $model['job'] === 'Recognize');

        $segmentationModelIds = implode(',', array_column($segmentationModels, 'pk'));
        $recognitionModelIds = implode(',', array_column($recognitionModels, 'pk'));

        $rules = [
            'script_name' => ['required', 'string', "in:{$scriptNames}"],
            'manifest_url' => ['required', 'string', 'url'],
            'pages' => ['nullable', 'string', new PagesRange],
            'recognition_model_id' => ['required', 'integer', "in:{$recognitionModelIds}"],
            'segmentation_model_id' => ['nullable', 'integer', "in:{$segmentationModelIds}"],
            'text_direction' => ['required', 'string', 'in:horizontal-lr,horizontal-rl,vertical-lr,vertical-rl'],
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'script_name.required' => __('validation.escriptorium.process.script_name.required'),
            'script_name.string' => __('validation.escriptorium.process.script_name.string'),
            'script_name.in' => __('validation.escriptorium.process.script_name.in'),
            'manifest_url.required' => __('validation.escriptorium.process.manifest_url.required'),
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
     */
    protected function passedValidation(): void
    {
        $this->merge([
            'pages_array' => $this->filled('pages')
                ? $this->parsePagesRange($this->pages)
                : range(1, 10),
        ]);
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
     *
     * @param  array|int|string|null  $key
     * @param  mixed  $default
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if ($key === null && is_array($validated)) {
            $pagesArray = $this->getPagesArray();
            if ($pagesArray !== null) {
                $validated['pages_array'] = $pagesArray;
            }
        }

        if ($key === 'pages_array') {
            return $this->getPagesArray() ?? $default;
        }

        return $validated;
    }
}
