<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Phone calls received by a store (go_to_calls); the date is the day of the call. */
class GoToCallsDataset extends ReportDataset
{
    public const KEY = 'data.go_to_calls';

    private const STATUSES = [
        ['value' => 'is_store', 'label' => 'Answered by the store'],
        ['value' => 'is_store_manager', 'label' => 'Answered by the store manager'],
        ['value' => 'is_call_center', 'label' => 'Answered by the call center'],
        ['value' => 'is_missed', 'label' => 'Missed'],
    ];

    public function label(): string
    {
        return 'Phone calls';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('go_to_calls.store_number'),
            Cols::date('DATE(go_to_calls.datetime)', 'Call date'),
            Cols::stamp('called_at', 'Called at', 'go_to_calls.datetime'),
            new Column('status', 'Outcome', 'enum', 'go_to_calls.status', options: self::STATUSES, groupable: true),
            Cols::text('call_result', 'Call result', 'go_to_calls.call_result'),
            Cols::text('caller', 'Caller', '`go_to_calls`.`from`'),
            Cols::whole('calls', 'Calls', '1', ['sum']),
            Cols::whole('missed_calls', 'Missed calls', "CASE WHEN go_to_calls.status = 'is_missed' THEN 1 ELSE 0 END", ['sum']),
            Cols::whole('duration', 'Call duration (seconds)', 'go_to_calls.duration'),
            Cols::whole(
                'participants',
                'People on the call',
                '(SELECT COUNT(*) FROM go_to_call_participants WHERE go_to_call_participants.go_to_call_id = go_to_calls.id)',
            ),
            Cols::ratio('missed_call_rate', 'Missed calls (% of calls)', 'number', 'percent(missed_calls, calls)'),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('go_to_calls');
    }

    public function storeColumn(): string
    {
        return 'go_to_calls.store_number';
    }

    public function dateColumn(): string
    {
        return 'DATE(go_to_calls.datetime)';
    }

    /** store_number already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
