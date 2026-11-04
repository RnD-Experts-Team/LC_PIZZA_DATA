<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\AggregationDataset;
use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\ItemSummaryColumns;

/** One row per store, business date, hour of the day and menu item (hourly_item_summary). */
class HourlyItemsDataset extends AggregationDataset
{
    public const KEY = 'data.hourly_items';

    public function label(): string
    {
        return 'Hourly item sales';
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::level('hour', 'Hour of the day (0-23)', 'hourly_item_summary.hour'),
            ...ItemSummaryColumns::dimensions(),
            ...ItemSummaryColumns::measures(),
        ];
    }

    protected function table(): string
    {
        return 'hourly_item_summary';
    }
}
