<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\AggregationDataset;
use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\StoreSummaryColumns;

/** One row per store per business date, from the aggregation warehouse (daily_store_summary). */
class DailySalesDataset extends AggregationDataset
{
    public const KEY = 'data.daily_sales';

    public function label(): string
    {
        return 'Daily sales';
    }

    public function columns(): array
    {
        return [Cols::store(), Cols::date(), ...StoreSummaryColumns::measures()];
    }

    protected function table(): string
    {
        return 'daily_store_summary';
    }
}
