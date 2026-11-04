<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Models\EmployeeDebriefType;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Debriefs written about employees; `count` counts them. */
class EmployeeDebriefsDataset extends ReportDataset
{
    public const KEY = 'data.employee_debriefs';

    public function label(): string
    {
        return 'Employee debriefs';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        $types = EmployeeDebriefType::query()->orderBy('label')->get(['slug', 'label'])
            ->map(fn (EmployeeDebriefType $type) => ['value' => $type->slug, 'label' => $type->label])
            ->all();

        return [
            new Column('store', 'Store', 'string', 'employee_debriefs.store_id', groupable: true),
            new Column('date', 'Date', 'date', 'employee_debriefs.date', groupable: true),
            new Column('employee', 'Employee', 'integer', 'employee_debriefs.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('type', 'Type', 'enum', 'employee_debrief_types.slug', options: $types, groupable: true),
            new Column('count', 'Debriefs', 'integer', 'employee_debriefs.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('employee_debriefs')
            ->leftJoin('employee_debrief_types', 'employee_debrief_types.id', '=', 'employee_debriefs.type_id');
    }

    public function storeColumn(): string
    {
        return 'employee_debriefs.store_id';
    }

    public function dateColumn(): string
    {
        return 'employee_debriefs.date';
    }

    /** store_id already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
