<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Cash checks and till counts (cash_management, hot and archive). */
class CashDataset extends WarehouseDataset
{
    public const KEY = 'data.cash';

    public function label(): string
    {
        return 'Cash management';
    }

    protected function table(): string
    {
        return 'cash_management';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'create_datetime', 'verified_datetime', 'till', 'check_type',
            'system_totals', 'verified', 'variance', 'created_by', 'verified_by',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('till', 'Till'),
            Cols::text('check_type', 'Check type'),
            Cols::text('created_by', 'Counted by (POS name)'),
            Cols::text('verified_by', 'Verified by (POS name)'),
            Cols::stamp('created_at', 'Counted at', 'create_datetime'),
            Cols::stamp('verified_at', 'Verified at', 'verified_datetime'),
            Cols::rows('Cash checks', 'id'),
            Cols::money('system_totals', 'Amount the system expects'),
            Cols::money('verified', 'Amount counted'),
            Cols::money('variance', 'Variance (counted minus expected)'),
        ];
    }
}
