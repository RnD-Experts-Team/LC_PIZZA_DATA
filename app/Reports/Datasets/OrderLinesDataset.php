<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** Every item on every order (order_line, hot and archive). Employee columns hold the POS name. */
class OrderLinesDataset extends WarehouseDataset
{
    public const KEY = 'data.order_lines';

    public function label(): string
    {
        return 'Order lines';
    }

    public function heavy(): bool
    {
        return true;
    }

    protected function table(): string
    {
        return 'order_line';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'order_id', 'item_id', 'date_time_placed', 'date_time_fulfilled',
            'menu_item_name', 'menu_item_account', 'bundle_name', 'royalty_item', 'taxable_item', 'employee',
            'override_approval_employee', 'order_placed_method', 'order_fulfilled_method', 'modification_reason',
            'payment_methods', 'refunded', 'is_pizza', 'is_bread', 'is_wings', 'is_beverages', 'is_crazy_puffs',
            'is_caesar_dip', 'net_amount', 'quantity', 'tax_included_amount', 'modified_order_amount',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('order_id', 'Order number'),
            Cols::text('item_id', 'Item ID'),
            Cols::text('menu_item_name', 'Menu item'),
            Cols::text('menu_item_account', 'Menu category'),
            Cols::text('bundle_name', 'Bundle'),
            Cols::text('royalty_item', 'Royalty item'),
            Cols::text('taxable_item', 'Taxable item'),
            Cols::flag('is_pizza', 'Pizza or Hot-N-Ready item'),
            Cols::flag('is_bread', 'Bread item'),
            Cols::flag('is_wings', 'Wings item'),
            Cols::flag('is_beverages', 'Beverage item'),
            Cols::flag('is_crazy_puffs', 'Crazy Puffs item'),
            Cols::flag('is_caesar_dip', 'Caesar dip item'),
            Cols::text('order_placed_method', 'Channel (how the order was placed)'),
            Cols::text('order_fulfilled_method', 'Service type (how it was fulfilled)'),
            Cols::text('pos_employee', 'Employee (POS name)', 'employee'),
            Cols::text('approver', 'Override approved by (POS name)', 'override_approval_employee'),
            Cols::text('payment_methods', 'Payment methods'),
            Cols::yesNo('refunded', 'Refunded'),
            Cols::text('modification_reason', 'Modification reason'),
            Cols::stamp('placed_at', 'Placed at', 'date_time_placed'),
            Cols::stamp('fulfilled_at', 'Fulfilled at', 'date_time_fulfilled'),
            Cols::distinct('orders', 'Orders', 'order_id'),
            Cols::rows('Order lines', 'id'),
            Cols::whole('quantity', 'Quantity'),
            Cols::money('net_amount', 'Net amount'),
            Cols::money('tax_included_amount', 'Amount including tax'),
            Cols::money('modified_order_amount', 'Modified order amount'),
            Cols::ratio('avg_item_price', 'Average item price', 'money', 'net_amount / quantity'),
        ];
    }
}
