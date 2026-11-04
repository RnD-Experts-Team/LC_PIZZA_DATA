<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Menu items wasted in the store (waste, hot and archive). */
class WasteDataset extends WarehouseDataset
{
    public const KEY = 'data.waste';

    public function label(): string
    {
        return 'Waste';
    }

    protected function table(): string
    {
        return 'waste';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'cv_item_id', 'menu_item_name', 'expired', 'waste_date_time',
            'produce_date_time', 'waste_reason', 'cv_order_id', 'waste_type', 'item_cost', 'quantity',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('item_id', 'Item ID', 'cv_item_id'),
            Cols::text('menu_item_name', 'Menu item'),
            Cols::flag('expired', 'Expired'),
            Cols::text('waste_reason', 'Waste reason'),
            Cols::text('waste_type', 'Waste type'),
            Cols::text('order_id', 'Order number', 'cv_order_id'),
            Cols::stamp('wasted_at', 'Wasted at', 'waste_date_time'),
            Cols::stamp('produced_at', 'Produced at', 'produce_date_time'),
            Cols::rows('Waste entries', 'id'),
            Cols::whole('quantity', 'Quantity wasted'),
            Cols::money('item_cost', 'Cost per item', null, Cols::SPREAD),
            Cols::money('waste_cost', 'Cost of waste (cost per item times quantity)', 'item_cost * quantity'),
        ];
    }
}
