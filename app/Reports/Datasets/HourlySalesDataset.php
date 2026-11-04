<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\AggregationDataset;
use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\StoreSummaryColumns;

/** One row per store per business date per hour of the day (hourly_store_summary). */
class HourlySalesDataset extends AggregationDataset
{
    public const KEY = 'data.hourly_sales';

    public function label(): string
    {
        return 'Hourly sales';
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::level('hour', 'Hour of the day (0-23)', 'hourly_store_summary.hour'),
            ...StoreSummaryColumns::measures(),
        ];
    }

    protected function table(): string
    {
        return 'hourly_store_summary';
    }
}
