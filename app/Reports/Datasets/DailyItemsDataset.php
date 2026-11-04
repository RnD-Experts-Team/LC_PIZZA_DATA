<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\AggregationDataset;
use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\ItemSummaryColumns;

/** One row per store, business date and menu item (daily_item_summary). */
class DailyItemsDataset extends AggregationDataset
{
    public const KEY = 'data.daily_items';

    public function label(): string
    {
        return 'Daily item sales';
    }

    public function columns(): array
    {
        return [Cols::store(), Cols::date(), ...ItemSummaryColumns::dimensions(), ...ItemSummaryColumns::measures()];
    }

    protected function table(): string
    {
        return 'daily_item_summary';
    }
}
