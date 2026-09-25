<?php

namespace App\Http\Requests\DoughSauce;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty' => 'required|numeric|gt:0',

            // The date the new quantity starts applying. The existing row is
            // closed the day before — the old figure stays true for the days it
            // was true, so a past week still computes the way it was planned.
            'effective_from' => 'sometimes|date_format:Y-m-d',
        ];
    }

    public function messages(): array
    {
        return [
            'qty.required'               => 'qty is required.',
            'qty.gt'                     => 'qty must be greater than zero.',
            'effective_from.date_format' => 'effective_from must be YYYY-MM-DD.',
        ];
    }
}
