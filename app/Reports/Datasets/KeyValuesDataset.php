<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Values entered against data-entry keys. Only current values: a row marked
 * mistaken has been replaced by its correction. `value` is the number, or 1/0
 * for a yes/no key; text and JSON keys have no value here.
 */
class KeyValuesDataset extends ReportDataset
{
    public const KEY = 'data.key_values';

    public function label(): string
    {
        return 'Entered key values';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'entered_key_values.store_id', groupable: true),
            new Column('date', 'Entry date', 'date', 'entered_key_values.entry_date', groupable: true),
            new Column('key', 'Key', 'string', 'entered_keys.label', groupable: true),
            new Column(
                'value',
                'Value',
                'number',
                'COALESCE(entered_key_values.value_number, entered_key_values.value_boolean)',
                measure: true,
                aggregations: ['sum', 'avg', 'min', 'max', 'count'],
            ),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('entered_key_values')
            ->join('entered_keys', 'entered_keys.id', '=', 'entered_key_values.key_id')
            ->where('entered_key_values.is_mistaken', false);
    }

    public function storeColumn(): string
    {
        return 'entered_key_values.store_id';
    }

    public function dateColumn(): string
    {
        return 'entered_key_values.entry_date';
    }

    /** store_id already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
