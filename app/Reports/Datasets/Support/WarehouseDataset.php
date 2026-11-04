<?php

declare(strict_types=1);

namespace App\Reports\Datasets\Support;

use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A raw POS table, hot and archive in one query. The operational database
 * keeps the last 90 days in `<table>_hot` and the archive database everything
 * older in `<table>_archive`; both sit on the same MySQL server, so the base
 * query is a UNION ALL of the two fully qualified tables, run on the
 * operational connection, and the report's period and store filters apply on
 * top of it (MySQL pushes them into both halves). DatabaseRouter has no
 * union of its own: it hands back one builder per connection.
 */
abstract class WarehouseDataset extends ReportDataset
{
    /** The table without the _hot / _archive suffix, e.g. detail_orders. */
    abstract protected function table(): string;

    /**
     * The physical columns both halves select, in this order. Only these are
     * visible to the columns' SQL, so a column left out here (a card number,
     * say) cannot be reached by any request.
     *
     * @return list<string>
     */
    abstract public function sourceColumns(): array;

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function baseQuery(): Builder
    {
        $connection = DB::connection('operational');
        $fields = $this->sourceColumns();

        $hot = $connection->table(config('database.connections.operational.database').'.'.$this->table().'_hot')->select($fields);
        $archive = $connection->table(config('database.connections.archive.database').'.'.$this->table().'_archive')->select($fields);

        return $connection->query()->fromSub($hot->unionAll($archive), 'warehouse');
    }

    public function storeColumn(): string
    {
        return 'franchise_store';
    }

    public function dateColumn(): string
    {
        return 'business_date';
    }

    /** franchise_store already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
