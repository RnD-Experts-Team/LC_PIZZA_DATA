<?php

namespace App\Http\Requests\DoughSauce;

use App\Models\Aggregation\DsIngredient;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id'           => 'required|string|max:20',
            'menu_item_name'    => 'required|string|max:255',
            'menu_item_account' => 'required|string|max:60',

            'lines'                  => 'required|array|min:1',
            'lines.*.ingredient_key' => 'required|string|max:40',
            'lines.*.qty'            => 'required|numeric|gt:0',

            'effective_from' => 'sometimes|date_format:Y-m-d',
        ];
    }

    public function messages(): array
    {
        return [
            'item_id.required'        => 'item_id is required (the LC menu item code).',
            'lines.required'          => 'lines is required — a recipe with no ingredients is not a recipe.',
            'lines.*.qty.gt'          => 'qty must be greater than zero.',
            'effective_from.date_format' => 'effective_from must be YYYY-MM-DD.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $lines = (array) $this->input('lines', []);
            $keys  = array_filter(array_column($lines, 'ingredient_key'));

            if ($keys !== array_unique($keys)) {
                $validator->errors()->add('lines', 'Each ingredient may appear only once in a recipe.');
            }

            if (! $keys) {
                return;
            }

            $known   = DsIngredient::query()->whereIn('key', $keys)->pluck('key')->all();
            $unknown = array_diff($keys, $known);

            if ($unknown) {
                $validator->errors()->add(
                    'lines',
                    'Unknown ingredient key(s): ' . implode(', ', $unknown) . '.'
                );
            }
        });
    }
}
