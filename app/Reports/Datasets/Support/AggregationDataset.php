<?php

declare(strict_types=1);

namespace App\Reports\Datasets\Support;

use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A table of the aggregation database: franchise_store holds the store code
 * and business_date the business date.
 */
abstract class AggregationDataset extends ReportDataset
{
    /** The aggregation table. */
    abstract protected function table(): string;

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function baseQuery(): Builder
    {
        return DB::connection('aggregation')->table($this->table());
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
