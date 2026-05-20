<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $key = $this->route('key');

        return [
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('entered_keys', 'label')->ignore($key?->id ?? $key),
            ],
            'data_type' => 'required|in:text,number,decimal,boolean,json',
            'is_active' => 'sometimes|boolean',

            'store_rules' => 'required|array|min:1',
            'store_rules.*.store_id' => 'required|string|max:255',

            'store_rules.*.fill_mode' => 'sometimes|in:store_once,role_each',
            'store_rules.*.role_names' => 'nullable|array',
            'store_rules.*.role_names.*' => 'string|max:255',

            'store_rules.*.frequency_type' => 'required|in:daily,weekly,monthly,yearly',
            'store_rules.*.interval' => 'required|integer|min:1',

            'store_rules.*.week_days' => 'nullable|array',
            'store_rules.*.week_days.*' => 'integer|min:1|max:7',

            'store_rules.*.month_day' => 'nullable|integer|min:1|max:31',
            'store_rules.*.week_of_month' => 'nullable|integer|in:1,2,3,4,-1',
            'store_rules.*.week_day' => 'nullable|integer|min:1|max:7',
            'store_rules.*.year_month' => 'nullable|integer|min:1|max:12',

            'store_rules.*.starts_at' => 'required|date_format:Y-m-d',
            'store_rules.*.ends_at' => 'nullable|date_format:Y-m-d',

            'store_rules.*.time' => 'nullable|date_format:H:i',

            'tags' => 'nullable|array',
            'tags.*' => 'exists:tags,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rules = $this->input('store_rules', []);

            foreach ($rules as $index => $rule) {
                $fillMode = $rule['fill_mode'] ?? 'store_once';
                $roleNames = $rule['role_names'] ?? [];

                if ($fillMode === 'role_each' && empty($roleNames)) {
                    $validator->errors()->add(
                        "store_rules.$index.role_names",
                        'role_names is required when fill_mode is role_each.'
                    );
                }

                if ($fillMode === 'store_once' && !empty($roleNames)) {
                    $validator->errors()->add(
                        "store_rules.$index.role_names",
                        'role_names must be empty when fill_mode is store_once.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'label.required' => 'Label is required.',
            'label.unique' => 'This label already exists.',
            'store_rules.required' => 'At least one store rule is required.',
            'store_rules.*.fill_mode.in' => 'fill_mode must be store_once or role_each.',
            'store_rules.*.role_names.array' => 'role_names must be an array.',
            'store_rules.*.role_names.*.string' => 'Each role name must be a string.',
            'store_rules.*.time.date_format' => 'time must be HH:mm format.',
        ];
    }
}