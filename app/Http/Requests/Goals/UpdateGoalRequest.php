<?php

namespace App\Http\Requests\Goals;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'goal' => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'goal.required' => 'goal is required.',
            'goal.numeric'  => 'goal must be a number.',
            'goal.min'      => 'goal must be at least 0.',
        ];
    }
}
