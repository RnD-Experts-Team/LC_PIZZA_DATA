<?php

namespace App\Http\Requests\DoughSauce;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Many stores x many dates — the batch form of DailyPlanRequest.
 *
 * Both lists are comma-separated because the caller is a browser building a GET:
 * ?store_ids=03795-00044,03795-00042&dates=2026-10-13,2026-10-14
 */
class DailyPlansRequest extends FormRequest
{
    /** A month of weekdays across the report's four weeks, with room to spare. */
    public const MAX_DATES = 62;

    /** Every store in the business today is 44. */
    public const MAX_STORES = 60;

    public function authorize(): bool
    {
        // pizzasys authorizes every store in `store_ids` through
        // AuthTokenStoreScopeMiddleware (store_scope_mode=scoped, policy=all):
        // one store the caller may not see and the whole request is a 403.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['dates', 'store_ids'] as $list) {
            if (is_string($this->input($list))) {
                $merge[$list] = array_values(array_unique(array_filter(
                    array_map('trim', explode(',', $this->input($list)))
                )));
            }
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
            // The franchise_store code exactly as daily_item_summary stores it.
            'store_ids'        => 'required|array|min:1|max:' . self::MAX_STORES,
            'store_ids.*'      => 'string|max:20',
            'dates'            => 'required|array|min:1|max:' . self::MAX_DATES,
            'dates.*'          => 'date_format:Y-m-d',
            'lookback'         => 'sometimes|integer|min:1|max:12',
            'include_refunded' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'store_ids.required' => 'store_ids is required (comma-separated store codes).',
            'store_ids.max'      => 'store_ids cannot list more than ' . self::MAX_STORES . ' stores.',
            'dates.required'     => 'dates is required (comma-separated YYYY-MM-DD).',
            'dates.max'          => 'dates cannot list more than ' . self::MAX_DATES . ' days.',
            'dates.*.date_format' => 'every date must be YYYY-MM-DD.',
            'lookback.max'       => 'lookback cannot exceed 12.',
            'include_refunded.boolean' => 'include_refunded must be true or false.',
        ];
    }

    /** @return array<int, string> */
    public function stores(): array
    {
        $stores = $this->validated()['store_ids'];
        sort($stores);

        return $stores;
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
