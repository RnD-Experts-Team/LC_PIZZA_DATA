<?php

declare(strict_types=1);

namespace App\Reports\Datasets\Support;

use App\Reports\Contract\Column;

/** The item columns of daily_item_summary and hourly_item_summary: same columns in both. */
final class ItemSummaryColumns
{
    /** @return list<Column> */
    public static function dimensions(): array
    {
        return [
            Cols::text('item_id', 'Item ID'),
            Cols::text('menu_item_name', 'Menu item'),
            Cols::text('menu_item_account', 'Menu category'),
        ];
    }

    /** @return list<Column> */
    public static function measures(): array
    {
        return [
            Cols::whole('quantity_sold', 'Quantity sold'),
            Cols::money('gross_sales', 'Gross sales (all lines)'),
            Cols::money('net_sales', 'Net sales (lines without a modification reason)'),
            Cols::whole('delivery_quantity', 'Delivery quantity'),
            Cols::whole('carryout_quantity', 'Carryout quantity'),
            Cols::whole('modified_quantity', 'Quantity of modified orders'),
            Cols::whole('refunded_quantity', 'Refunded quantity'),
            Cols::ratio('avg_item_price', 'Average item price', 'money', 'gross_sales / quantity_sold'),
        ];
    }
}
