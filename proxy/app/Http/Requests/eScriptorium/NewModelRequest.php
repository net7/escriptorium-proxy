<?php

namespace App\Http\Requests\eScriptorium;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\File;

class NewModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string'],
            'file' => ['required', 'file', File::types(['application/octet-stream'])->min('1kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.types' => __('validation.escriptorium.file.types', ['values' => 'mlmodel']),
            'file.min' => __('validation.escriptorium.file.min', ['min' => '1kb']),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('validation.escriptorium.new_model.failed'),
            'errors' => $validator->errors(),
            'status' => 422,
        ], 422));
    }
}
