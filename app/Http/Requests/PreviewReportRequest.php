<?php

namespace App\Http\Requests;

use App\Reports\Contract\Column;
use App\Reports\Engine\PeriodResolver;
use App\Reports\Engine\ReportDefinitionValidator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape of a report definition (v1). What it refers to — datasets, columns,
 * formulas, grain — is checked by ReportDefinitionValidator against the catalog.
 * No list here has a maximum size.
 */
class PreviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'definition' => 'required|array',
            'definition.v' => 'required|integer|in:1',

            'definition.datasets' => 'required|array|min:1',
            'definition.datasets.*.alias' => 'required|string|max:16',
            'definition.datasets.*.key' => 'required|string|max:100',

            // Empty: one totals row (or one per breakdown).
            'definition.grain' => 'present|array',
            'definition.grain.*' => 'required|string|distinct|in:'.implode(',', ReportDefinitionValidator::GRAIN),

            'definition.columns' => 'required|array|min:1',
            'definition.columns.*.id' => 'required|string',
            'definition.columns.*.kind' => 'sometimes|string|in:dataset,formula',
            'definition.columns.*.alias' => 'required_unless:definition.columns.*.kind,formula|string|max:16',
            'definition.columns.*.column' => 'required_unless:definition.columns.*.kind,formula|string|max:64',
            'definition.columns.*.agg' => 'nullable|string|max:20',
            'definition.columns.*.expr' => 'required_if:definition.columns.*.kind,formula|string',
            'definition.columns.*.label' => 'nullable|string|max:100',
            'definition.columns.*.type' => 'nullable|string|in:'.implode(',', Column::TYPES),
            'definition.columns.*.format' => 'nullable|string|in:number,money,percent,date,text',

            'definition.filters' => 'sometimes|array',
            'definition.filters.*.alias' => 'required_without:definition.filters.*.id|string|max:16',
            'definition.filters.*.column' => 'required_with:definition.filters.*.alias|string|max:64',
            'definition.filters.*.id' => 'required_without:definition.filters.*.alias|string',
            'definition.filters.*.op' => 'required|string|in:'.implode(',', ReportDatasetQueryRequest::OPS),
            'definition.filters.*.value' => 'nullable',

            'definition.sort' => 'sometimes|array',
            'definition.sort.*.id' => 'required|string',
            'definition.sort.*.dir' => 'sometimes|string|in:asc,desc',

            'definition.period' => 'required|array',
            'definition.period.type' => 'required|string|in:relative,fixed',
            'definition.period.token' => 'required_if:definition.period.type,relative|string|in:'.implode(',', PeriodResolver::TOKENS),
            'definition.period.from' => 'required_if:definition.period.type,fixed|date_format:Y-m-d',
            'definition.period.to' => 'required_if:definition.period.type,fixed|date_format:Y-m-d',

            'definition.stores' => 'required|array',
            'definition.stores.mode' => 'required|string|in:all,mine,list',
            'definition.stores.codes' => 'required_if:definition.stores.mode,list|array',
            'definition.stores.codes.*' => ['required', 'string', 'max:20', 'distinct', 'regex:/^\d{5}-\d{5}$/'],

            // None: every row.
            'definition.limit' => 'nullable|integer|min:1',
        ];
    }
}
