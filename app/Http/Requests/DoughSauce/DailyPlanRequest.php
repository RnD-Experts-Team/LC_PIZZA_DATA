<?php

namespace App\Http\Requests\DoughSauce;

use Illuminate\Foundation\Http\FormRequest;

class DailyPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route-level authorization is handled by the auth server through
        // AuthTokenStoreScopeMiddleware, which also receives `store` in the
        // query string via store_context and decides whether this caller may
        // read that store.
        return true;
    }

    /**
     * A query string cannot carry a JSON boolean.
     *
     * `?include_refunded=false` arrives as the string "false", which Laravel's
     * `boolean` rule rejects — it accepts true/false/1/0/"1"/"0" but not the
     * spelled-out words. Since the caller is a browser and `=false` is the only
     * natural way to write it there, the request normalises rather than making
     * every client send `=0`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('include_refunded')) {
            $this->merge([
                'include_refunded' => filter_var(
                    $this->input('include_refunded'),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            // `store` is not here: it is the {store_id} path segment, which is
            // what pizzasys authorizes against.
            'date' => 'required|date_format:Y-m-d',

            // 4 is the workbook's window. Capped at 12 because the averaging
            // window is a business rule, not a dial, and a caller asking for a
            // year of Fridays is asking the wrong question.
            'lookback' => 'sometimes|integer|min:1|max:12',

            // false subtracts refunds. A refunded order is not recurring demand,
            // and the plan forecasts demand — but the caller decides, because it
            // is a parameter here and not a rebuild.
            'include_refunded' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'date.required'    => 'date is required.',
            'date.date_format' => 'date must be YYYY-MM-DD.',
            'lookback.integer' => 'lookback must be a number.',
            'lookback.min'     => 'lookback must be at least 1.',
            'lookback.max'     => 'lookback cannot exceed 12.',
            'include_refunded.boolean' => 'include_refunded must be true or false.',
        ];
    }

    public function lookback(): int
    {
        return (int) $this->input('lookback', 4);
    }

    public function includeRefunded(): bool
    {
        return filter_var($this->input('include_refunded', false), FILTER_VALIDATE_BOOLEAN);
    }
}
