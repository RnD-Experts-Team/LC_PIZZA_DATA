<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Foundation\Http\FormRequest;

class UpsertValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key_id' => 'required|integer|exists:entered_keys,id',

            'value_text' => 'nullable|string',
            'value_number' => 'nullable|numeric',
            'value_boolean' => 'nullable|boolean',
            'value_json' => 'nullable|array',

            'note' => 'nullable|string|max:2000',

            'attachments' => 'sometimes|array',
            'attachments.*' => 'sometimes|file|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'key_id.required' => 'key_id is required.',
            'key_id.exists' => 'key_id does not exist.',

            'value_number.numeric' => 'value_number must be numeric.',
            'value_boolean.boolean' => 'value_boolean must be true/false.',
            'value_json.array' => 'value_json must be an array/object.',
            'attachments.array' => 'attachments must be an array of files.',
            'attachments.*.file' => 'Each attachment must be a valid file.',
            'attachments.*.max' => 'Each attachment must not exceed 10MB.',
        ];
    }
}