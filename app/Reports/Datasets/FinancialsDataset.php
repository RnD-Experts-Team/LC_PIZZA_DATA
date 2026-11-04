<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** The POS financial views: an amount per area and sub-account per store and date (financial_views, hot and archive). */
class FinancialsDataset extends WarehouseDataset
{
    public const KEY = 'data.financials';

    public function label(): string
    {
        return 'Financial views';
    }

    protected function table(): string
    {
        return 'financial_views';
    }

    public function sourceColumns(): array
    {
        return ['id', 'franchise_store', 'business_date', 'area', 'sub_account', 'amount'];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('area', 'Area'),
            Cols::text('sub_account', 'Account'),
            Cols::rows('Entries', 'id'),
            Cols::money('amount', 'Amount'),
        ];
    }
}
