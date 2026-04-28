<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeDebriefRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = (string) $this->route('store_id');

        return [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'employee_id' => [
                'sometimes',
                'integer',
                Rule::exists('employees', 'id')->where(fn($query) => $query->where('store_id', $storeId)),
            ],

            'paginated' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'from.required' => 'from is required.',
            'from.date_format' => 'from must be YYYY-MM-DD.',

            'to.required' => 'to is required.',
            'to.date_format' => 'to must be YYYY-MM-DD.',
            'to.after_or_equal' => 'to must be after or equal to from.',

            'employee_id.integer' => 'employee_id must be a number.',
            'employee_id.exists' => 'employee_id is invalid for this store.',

            'paginated.boolean' => 'paginated must be true or false.',

            'page.integer' => 'page must be a number.',
            'page.min' => 'page must be at least 1.',

            'per_page.integer' => 'per_page must be a number.',
            'per_page.min' => 'per_page must be at least 1.',
            'per_page.max' => 'per_page cannot exceed 200.',
        ];
    }
}