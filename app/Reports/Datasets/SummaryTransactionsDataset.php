<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Payments by method per store and date (summary_transactions, hot and archive). */
class SummaryTransactionsDataset extends WarehouseDataset
{
    public const KEY = 'data.summary_transactions';

    public function label(): string
    {
        return 'Payments by method';
    }

    protected function table(): string
    {
        return 'summary_transactions';
    }

    public function sourceColumns(): array
    {
        return ['id', 'franchise_store', 'business_date', 'payment_method', 'sub_payment_method', 'total_amount', 'saf_qty', 'saf_total'];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('payment_method', 'Payment method'),
            Cols::text('sub_payment_method', 'Payment sub-method'),
            Cols::money('total_amount', 'Total amount'),
            Cols::whole('saf_qty', 'Store-and-forward (SAF) payments'),
            Cols::money('saf_total', 'Store-and-forward (SAF) amount'),
        ];
    }
}
