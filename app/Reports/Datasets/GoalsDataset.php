<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The weekly goal of each store for each goal metric (goals and goal_metrics). The date is the week's first day. */
class GoalsDataset extends ReportDataset
{
    public const KEY = 'data.goals';

    public function label(): string
    {
        return 'Weekly goals';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('goals.store_id'),
            Cols::date('goals.week_start_date', 'Week start date'),
            Cols::day('week_end_date', 'Week end date', 'goals.week_end_date'),
            Cols::text('metric', 'Goal metric', 'goal_metrics.name'),
            Cols::rows('Goals', 'goals.id'),
            Cols::amount('goal', 'Goal', 'goals.goal'),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('goals')->join('goal_metrics', 'goal_metrics.id', '=', 'goals.goal_metric_id');
    }

    public function storeColumn(): string
    {
        return 'goals.store_id';
    }

    public function dateColumn(): string
    {
        return 'goals.week_start_date';
    }

    /** store_id already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
