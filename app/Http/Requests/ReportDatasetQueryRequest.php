<?php
// Copied from LC_PIZZA_DATA/app/Http/Requests/ReportDatasetQueryRequest.php — contract v1. Edit there, copy everywhere.

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only. Which columns, ops and functions a dataset accepts is checked
 * by DatasetQueryCompiler, which knows the dataset.
 */
class ReportDatasetQueryRequest extends FormRequest
{
    public const OPS = ['eq', 'neq', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'between', 'contains', 'is_null', 'not_null'];

    public const FNS = ['count', 'count_distinct', 'sum', 'avg', 'min', 'max'];

    private const KEY = 'regex:/^[a-z][a-z0-9_]{0,63}$/';

    /** An aggregate alias; the engine's own `_d_` aliases may start with an underscore. */
    private const ALIAS = 'regex:/^_?[a-z][a-z0-9_]{0,66}$/';

    private const STORE_CODE = 'regex:/^\d{5}-\d{5}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Store codes (03795-00044). Empty = no store filter, which pizzasys only allows the head.
            'stores' => 'present|array',
            'stores.*' => ['required', 'string', 'max:20', 'distinct', self::STORE_CODE],
            // Required by dated datasets (the compiler checks); snapshot datasets ignore it.
            'date_from' => 'nullable|required_with:date_to|date_format:Y-m-d',
            'date_to' => 'nullable|required_with:date_from|date_format:Y-m-d|after_or_equal:date_from',

            'columns' => 'sometimes|array',
            'columns.*' => ['required', 'string', self::KEY, 'distinct'],
            'group_by' => 'sometimes|array',
            'group_by.*' => ['required', 'string', self::KEY, 'distinct'],

            'filters' => 'sometimes|array',
            'filters.*.column' => ['required', 'string', self::KEY],
            'filters.*.op' => 'required|string|in:'.implode(',', self::OPS),
            'filters.*.value' => 'nullable',

            'aggregates' => 'sometimes|array',
            'aggregates.*.column' => ['required', 'string', self::KEY],
            'aggregates.*.fn' => 'required|string|in:'.implode(',', self::FNS),
            'aggregates.*.alias' => ['nullable', 'string', self::ALIAS],

            'sort' => 'sometimes|array',
            'sort.*.column' => ['required', 'string', self::KEY],
            'sort.*.dir' => 'sometimes|string|in:asc,desc',

            'cursor' => 'nullable|string|max:4000',
            // A page above 5000 is cut to 5000 by the compiler.
            'limit' => 'nullable|integer|min:1',
            // Every row at once as NDJSON, with no cursor and no limit.
            'stream' => 'sometimes|boolean',
        ];
    }
}
