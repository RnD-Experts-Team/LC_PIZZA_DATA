<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Ingredients ordered from suppliers, one row per invoice line (alta_inventory_ingredient_orders, hot and archive). */
class AltaIngredientOrdersDataset extends WarehouseDataset
{
    public const KEY = 'data.alta_ingredient_orders';

    public function label(): string
    {
        return 'Ingredient orders';
    }

    protected function table(): string
    {
        return 'alta_inventory_ingredient_orders';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'supplier', 'invoice_number', 'purchase_order_number', 'ingredient_id',
            'ingredient_description', 'ingredient_category', 'ingredient_unit', 'unit_price', 'order_qty', 'sent_qty',
            'received_qty', 'total_cost',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('supplier', 'Supplier'),
            Cols::text('invoice_number', 'Invoice number'),
            Cols::text('purchase_order_number', 'Purchase order number'),
            Cols::text('ingredient_id', 'Ingredient ID'),
            Cols::text('ingredient_description', 'Ingredient'),
            Cols::text('ingredient_category', 'Ingredient category'),
            Cols::text('ingredient_unit', 'Unit'),
            Cols::rows('Order lines', 'id'),
            Cols::money('unit_price', 'Unit price', null, Cols::SPREAD),
            Cols::amount('order_qty', 'Quantity ordered'),
            Cols::amount('sent_qty', 'Quantity sent'),
            Cols::amount('received_qty', 'Quantity received'),
            Cols::money('total_cost', 'Total cost'),
        ];
    }
}
