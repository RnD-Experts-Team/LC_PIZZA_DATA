<?php

namespace App\Http\Requests\DoughSauce;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Many dates for one store — the batch form of DailyPlanRequest.
 *
 * `dates` is a comma-separated list because the caller is a browser building a
 * GET: ?dates=2026-10-13,2026-10-14 is what it can write without a body.
 */
class DailyPlansRequest extends FormRequest
{
    /** A month of weekdays across the report's four weeks, with room to spare. */
    public const MAX_DATES = 62;

    public function authorize(): bool
    {
        // Same as DailyPlanRequest: pizzasys authorizes the {store_id} path
        // segment through AuthTokenStoreScopeMiddleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (is_string($this->input('dates'))) {
            $merge['dates'] = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $this->input('dates')))
            )));
        }

        if ($this->has('include_refunded')) {
            $merge['include_refunded'] = filter_var(
                $this->input('include_refunded'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'dates'            => 'required|array|min:1|max:' . self::MAX_DATES,
            'dates.*'          => 'date_format:Y-m-d',
            'lookback'         => 'sometimes|integer|min:1|max:12',
            'include_refunded' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'dates.required'     => 'dates is required (comma-separated YYYY-MM-DD).',
            'dates.max'          => 'dates cannot list more than ' . self::MAX_DATES . ' days.',
            'dates.*.date_format' => 'every date must be YYYY-MM-DD.',
            'lookback.max'       => 'lookback cannot exceed 12.',
            'include_refunded.boolean' => 'include_refunded must be true or false.',
        ];
    }

    /** @return array<int, string> */
    public function dates(): array
    {
        $dates = $this->validated()['dates'];
        sort($dates);

        return $dates;
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
