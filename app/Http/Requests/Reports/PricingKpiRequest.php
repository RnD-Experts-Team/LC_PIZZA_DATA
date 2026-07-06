<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class PricingKpiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'stores' => 'required|string',
            'item_ids' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.required' => 'start_date is required.',
            'start_date.date_format' => 'start_date must be YYYY-MM-DD.',

            'end_date.required' => 'end_date is required.',
            'end_date.date_format' => 'end_date must be YYYY-MM-DD.',
            'end_date.after_or_equal' => 'end_date must be after or equal to start_date.',

            'stores.required' => 'stores is required.',
            'stores.string' => 'stores must be a comma separated list of store numbers.',

            'item_ids.required' => 'item_ids is required.',
            'item_ids.string' => 'item_ids must be a comma separated list of item ids.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {

            $start = $this->input('start_date');
            $end = $this->input('end_date');

            if ($start && $end && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
                $dayCount = (int) \Carbon\Carbon::parse($start)->diffInDays(\Carbon\Carbon::parse($end)) + 1;
                if ($dayCount > 366) {
                    $validator->errors()->add('date_range', 'Date range must not exceed 366 days.');
                }
            }

            $stores = $this->storesArray();
            if (empty($stores)) {
                $validator->errors()->add('stores', 'stores must contain at least one store number.');
            } elseif (count($stores) > 100) {
                $validator->errors()->add('stores', 'Cannot request more than 100 stores.');
            }

            $itemIds = $this->itemIdsArray();
            if (empty($itemIds)) {
                $validator->errors()->add('item_ids', 'item_ids must contain at least one item id.');
            } elseif (count($itemIds) > 200) {
                $validator->errors()->add('item_ids', 'Cannot request more than 200 item ids.');
            }
        });
    }

    public function storesArray(): array
    {
        return $this->explodeList($this->input('stores'));
    }

    public function itemIdsArray(): array
    {
        return $this->explodeList($this->input('item_ids'));
    }

    private function explodeList(?string $value): array
    {
        if (!$value) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            fn($v) => $v !== ''
        )));
    }
}
