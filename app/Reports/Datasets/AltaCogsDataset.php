<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Cost of goods per inventory category and count period (alta_inventory_cogs, hot and archive). */
class AltaCogsDataset extends WarehouseDataset
{
    public const KEY = 'data.alta_cogs';

    public function label(): string
    {
        return 'Inventory cost of goods';
    }

    protected function table(): string
    {
        return 'alta_inventory_cogs';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'count_period', 'inventory_category', 'starting_value', 'received_value',
            'net_transfer_value', 'ending_value', 'used_value', 'theoretical_usage_value', 'variance_value',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('count_period', 'Count period'),
            Cols::text('inventory_category', 'Inventory category'),
            Cols::money('starting_value', 'Starting inventory value'),
            Cols::money('received_value', 'Received value'),
            Cols::money('net_transfer_value', 'Net transfers value'),
            Cols::money('ending_value', 'Ending inventory value'),
            Cols::money('used_value', 'Used value'),
            Cols::money('theoretical_usage_value', 'Theoretical usage value'),
            Cols::money('variance_value', 'Variance value (used minus theoretical)'),
        ];
    }
}
