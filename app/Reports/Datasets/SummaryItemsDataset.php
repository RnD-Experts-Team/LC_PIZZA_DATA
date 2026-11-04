<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** The POS item summary report, one row per store, date and menu item (summary_items, hot and archive). */
class SummaryItemsDataset extends WarehouseDataset
{
    public const KEY = 'data.summary_items';

    public function label(): string
    {
        return 'POS item summary';
    }

    protected function table(): string
    {
        return 'summary_items';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'menu_item_name', 'menu_item_account', 'item_id', 'item_quantity',
            'royalty_obligation', 'taxable_amount', 'non_taxable_amount', 'tax_exempt_amount', 'non_royalty_amount',
            'tax_included_amount',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('item_id', 'Item ID'),
            Cols::text('menu_item_name', 'Menu item'),
            Cols::text('menu_item_account', 'Menu category'),
            Cols::whole('item_quantity', 'Quantity sold'),
            Cols::money('royalty_obligation', 'Royalty obligation (sales royalty is paid on)'),
            Cols::money('taxable_amount', 'Taxable amount'),
            Cols::money('non_taxable_amount', 'Non-taxable amount'),
            Cols::money('tax_exempt_amount', 'Tax-exempt amount'),
            Cols::money('non_royalty_amount', 'Non-royalty amount'),
            Cols::money('tax_included_amount', 'Amount including tax'),
            Cols::ratio('avg_item_price', 'Average item price', 'money', 'royalty_obligation / item_quantity'),
        ];
    }
}
