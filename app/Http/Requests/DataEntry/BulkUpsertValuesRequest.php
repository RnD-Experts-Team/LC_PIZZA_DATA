<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpsertValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.key_id' => 'required|integer|exists:entered_keys,id',

            'items.*.value_text' => 'nullable|string',
            'items.*.value_number' => 'nullable|numeric',
            'items.*.value_boolean' => 'nullable|boolean',
            'items.*.value_json' => 'nullable|array',

            'items.*.note' => 'nullable|string|max:2000',

            'items.*.attachments' => 'sometimes|array',
            'items.*.attachments.*' => 'sometimes|file|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'items is required.',
            'items.array' => 'items must be an array.',
            'items.min' => 'items must contain at least 1 row.',
            'items.*.key_id.required' => 'Each item must include key_id.',
            'items.*.key_id.exists' => 'One or more key_id values do not exist.',
            'items.*.attachments.array' => 'attachments must be an array of files for each item.',
            'items.*.attachments.*.file' => 'Each attachment in an item must be a valid file.',
            'items.*.attachments.*.max' => 'Each attachment in an item must not exceed 10MB.',
        ];
    }
}
