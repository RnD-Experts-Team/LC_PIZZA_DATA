<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Ingredient stock movement per count period (alta_inventory_ingredient_usage, hot and archive). */
class AltaIngredientUsageDataset extends WarehouseDataset
{
    public const KEY = 'data.alta_ingredient_usage';

    public function label(): string
    {
        return 'Ingredient usage';
    }

    protected function table(): string
    {
        return 'alta_inventory_ingredient_usage';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'count_period', 'ingredient_id', 'ingredient_description',
            'ingredient_category', 'ingredient_unit', 'ingredient_unit_cost', 'starting_inventory_qty', 'received_qty',
            'net_transferred_qty', 'ending_inventory_qty', 'actual_usage', 'theoretical_usage', 'variance_qty', 'waste_qty',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('count_period', 'Count period'),
            Cols::text('ingredient_id', 'Ingredient ID'),
            Cols::text('ingredient_description', 'Ingredient'),
            Cols::text('ingredient_category', 'Ingredient category'),
            Cols::text('ingredient_unit', 'Unit'),
            Cols::money('ingredient_unit_cost', 'Cost per unit', null, Cols::SPREAD),
            Cols::amount('starting_inventory_qty', 'Starting inventory'),
            Cols::amount('received_qty', 'Received'),
            Cols::amount('net_transferred_qty', 'Net transfers'),
            Cols::amount('ending_inventory_qty', 'Ending inventory'),
            Cols::amount('actual_usage', 'Actual usage'),
            Cols::amount('theoretical_usage', 'Theoretical usage'),
            Cols::amount('variance_qty', 'Usage variance (actual minus theoretical)'),
            Cols::amount('waste_qty', 'Waste'),
            Cols::money('actual_usage_cost', 'Cost of actual usage', 'actual_usage * ingredient_unit_cost'),
            Cols::money('variance_cost', 'Cost of usage variance', 'variance_qty * ingredient_unit_cost'),
        ];
    }
}
