<?php

namespace App\Http\Requests\Goals;

use Illuminate\Foundation\Http\FormRequest;

class ListGoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'           => 'sometimes|date_format:Y-m-d',
            'to'             => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'goal_metric_id' => 'sometimes|integer|exists:goal_metrics,id',
            'per_page'       => 'sometimes|integer|min:1|max:200',
            'page'           => 'sometimes|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'from.date_format'      => 'from must be YYYY-MM-DD.',
            'to.date_format'        => 'to must be YYYY-MM-DD.',
            'to.after_or_equal'     => 'to must be after or equal to from.',
            'goal_metric_id.exists' => 'The selected metric does not exist.',
        ];
    }
}
