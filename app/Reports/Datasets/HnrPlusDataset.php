<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Hot-N-Ready Plus items made, sold, voided and wasted per store and week (hnr_plus_items). The date is the week's first day. */
class HnrPlusDataset extends ReportDataset
{
    public const KEY = 'data.hnr_plus';

    public function label(): string
    {
        return 'Hot-N-Ready Plus items';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('hnr_plus_items.store_number'),
            Cols::date('hnr_plus_items.week_start', 'Week start date'),
            Cols::day('week_end', 'Week end date', 'hnr_plus_items.week_end'),
            Cols::text('item_id', 'Item ID', 'hnr_plus_items.item_id'),
            Cols::text('item_name', 'Item', 'hnr_plus_items.item_name'),
            Cols::rows('Item rows', 'hnr_plus_items.id'),
            Cols::whole('made', 'Made', 'hnr_plus_items.made'),
            Cols::whole('sold', 'Sold', 'hnr_plus_items.sold'),
            Cols::whole('voided', 'Voided', 'hnr_plus_items.voided'),
            Cols::whole('wasted', 'Wasted', 'hnr_plus_items.wasted'),
            Cols::whole('variance', 'Variance', 'hnr_plus_items.variance'),
            Cols::whole('no_inventory_available', 'Times no inventory was available', 'hnr_plus_items.no_inventory_available'),
            Cols::ratio('sell_through', 'Sold (% of made)', 'number', 'percent(sold, made)'),
            Cols::ratio('waste_rate', 'Wasted (% of made)', 'number', 'percent(wasted, made)'),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('hnr_plus_items');
    }

    public function storeColumn(): string
    {
        return 'hnr_plus_items.store_number';
    }

    public function dateColumn(): string
    {
        return 'hnr_plus_items.week_start';
    }

    /** store_number already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
