<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Inventory waste counted in the inventory system (alta_inventory_waste, hot and archive). */
class AltaWasteDataset extends WarehouseDataset
{
    public const KEY = 'data.alta_waste';

    public function label(): string
    {
        return 'Inventory waste';
    }

    protected function table(): string
    {
        return 'alta_inventory_waste';
    }

    public function sourceColumns(): array
    {
        return ['id', 'franchise_store', 'business_date', 'item_id', 'item_description', 'waste_reason', 'unit_food_cost', 'qty'];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('item_id', 'Item ID'),
            Cols::text('item_description', 'Item'),
            Cols::text('waste_reason', 'Waste reason'),
            Cols::rows('Waste entries', 'id'),
            Cols::money('unit_food_cost', 'Food cost per unit', null, Cols::SPREAD),
            Cols::amount('qty', 'Quantity wasted'),
            Cols::money('waste_cost', 'Cost of waste (cost per unit times quantity)', 'unit_food_cost * qty'),
        ];
    }
}
