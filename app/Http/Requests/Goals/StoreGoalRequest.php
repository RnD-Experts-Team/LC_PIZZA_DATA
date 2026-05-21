<?php

namespace App\Http\Requests\Goals;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'goal_metric_id'  => 'required|integer|exists:goal_metrics,id',
            'week_start_date' => 'required|date_format:Y-m-d',
            'week_end_date'   => 'required|date_format:Y-m-d|after_or_equal:week_start_date',
            'goal'            => 'required|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'goal_metric_id.required'  => 'goal_metric_id is required.',
            'goal_metric_id.exists'    => 'The selected metric does not exist.',
            'week_start_date.required' => 'week_start_date is required.',
            'week_start_date.date_format' => 'week_start_date must be YYYY-MM-DD.',
            'week_end_date.required'   => 'week_end_date is required.',
            'week_end_date.date_format' => 'week_end_date must be YYYY-MM-DD.',
            'week_end_date.after_or_equal' => 'week_end_date must be after or equal to week_start_date.',
            'goal.required'            => 'goal is required.',
            'goal.numeric'             => 'goal must be a number.',
            'goal.min'                 => 'goal must be at least 0.',
        ];
    }
}
