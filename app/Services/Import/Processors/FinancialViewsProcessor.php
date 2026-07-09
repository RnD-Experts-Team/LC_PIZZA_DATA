<?php

namespace App\Services\Import\Processors;

class FinancialViewsProcessor extends BaseTableProcessor
{
    protected function getTableName(): string
    {
        return 'financial_views';
    }

    protected function getUniqueKeys(): array
    {
        return ['franchise_store', 'business_date', 'sub_account', 'area'];
    }

    protected function getFillableColumns(): array
    {
        return [
            'franchise_store',
            'business_date',
            'area',
            'sub_account',
            'amount',
        ];
    }

    protected function getColumnMapping(): array
    {
        return array_merge(parent::getColumnMapping(), [
            'area' => 'area',
            'subaccount' => 'sub_account',
            'amount' => 'amount',
        ]);
    }

    protected function transformData(array $row): array
    {
        $row = parent::transformData($row);

        if (array_key_exists('amount', $row) && $row['amount'] !== null) {
            $row['amount'] = $this->toNumeric(str_replace(',', '', (string) $row['amount']));
        }

        return $row;
    }
}
