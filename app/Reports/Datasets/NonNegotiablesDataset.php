<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Non-negotiable incidents logged against a store, e.g. downtime (non_negotiable_reports). Rows without a date or store are never returned. */
class NonNegotiablesDataset extends ReportDataset
{
    public const KEY = 'data.non_negotiables';

    public function label(): string
    {
        return 'Non-negotiable incidents';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('non_negotiable_reports.store_number'),
            Cols::date('non_negotiable_reports.date', 'Date'),
            Cols::text('time', 'Time', 'non_negotiable_reports.time'),
            Cols::day('second_date', 'Second date', 'non_negotiable_reports.date_two'),
            Cols::text('second_time', 'Second time', 'non_negotiable_reports.time_two'),
            Cols::text('action', 'Action', 'non_negotiable_reports.action'),
            Cols::text('notes', 'Notes', 'non_negotiable_reports.notes'),
            Cols::rows('Incidents', 'non_negotiable_reports.id'),
            Cols::whole('downtime_incidents', 'Downtime incidents', "CASE WHEN non_negotiable_reports.action = 'Downtime' THEN 1 ELSE 0 END"),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('non_negotiable_reports');
    }

    public function storeColumn(): string
    {
        return 'non_negotiable_reports.store_number';
    }

    public function dateColumn(): string
    {
        return 'non_negotiable_reports.date';
    }

    /** store_number already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
