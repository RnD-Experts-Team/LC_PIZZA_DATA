<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ingredient transfers between stores (transfer_in_outs). Each transfer shows
 * up once for every store it involves: as "In" for the receiving store and as
 * "Out" for the sending store, so a store filter finds both its transfers in
 * and out. Without a store filter, group or filter by direction to count a
 * transfer once.
 */
class TransfersDataset extends ReportDataset
{
    public const KEY = 'data.transfers';

    private const FIELDS = ['id', 'date', 'major_category', 'base_unit_per_report_unit', 'ing_id', 'ing_des', 'quantity', 'unit', 'total_cost', 'is_posted'];

    public function label(): string
    {
        return 'Ingredient transfers between stores';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('store_number'),
            Cols::date('date', 'Transfer date'),
            Cols::pick('direction', 'Direction (In = received, Out = sent)', ['In', 'Out']),
            Cols::text('other_store', 'Other store', 'other_store_number'),
            Cols::text('major_category', 'Category'),
            Cols::text('ingredient_id', 'Ingredient ID', 'ing_id'),
            Cols::text('ingredient', 'Ingredient', 'ing_des'),
            Cols::text('unit', 'Unit'),
            Cols::flag('is_posted', 'Posted'),
            Cols::rows('Transfers', 'id'),
            Cols::amount('quantity', 'Quantity'),
            Cols::money('total_cost', 'Total cost'),
            Cols::amount('base_unit_per_report_unit', 'Base units per reporting unit', null, Cols::SPREAD),
        ];
    }

    public function baseQuery(): Builder
    {
        $in = DB::table('transfer_in_outs')->select(self::FIELDS)
            ->selectRaw("'In' as direction, to_store_number as store_number, from_store_number as other_store_number");
        $out = DB::table('transfer_in_outs')->select(self::FIELDS)
            ->selectRaw("'Out' as direction, from_store_number as store_number, to_store_number as other_store_number");

        return DB::query()->fromSub($in->unionAll($out), 'transfers');
    }

    public function storeColumn(): string
    {
        return 'store_number';
    }

    public function dateColumn(): string
    {
        return 'date';
    }

    /** The store columns already hold the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
