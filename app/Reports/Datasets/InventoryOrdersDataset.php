<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\Cols;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Ingredient deliveries from the supply vendor, one row per invoice (inventory_orders). The date is the delivery date. */
class InventoryOrdersDataset extends ReportDataset
{
    public const KEY = 'data.inventory_orders';

    public function label(): string
    {
        return 'Inventory orders';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            Cols::store('inventory_orders.store_number'),
            Cols::date('inventory_orders.delivery_date', 'Delivery date'),
            Cols::text('invoice_no', 'Invoice number', 'inventory_orders.invoice_no'),
            Cols::text('vendor_number', 'Vendor number', 'inventory_orders.vendor_number'),
            Cols::text('vendor_name', 'Vendor', 'inventory_orders.vendor_name'),
            Cols::text('order_status', 'Order status', 'inventory_orders.order_status'),
            Cols::flag('is_manual_asn', 'Manual advance ship notice', 'inventory_orders.is_manual_asn'),
            Cols::flag('is_asn_received', 'Advance ship notice received', 'inventory_orders.is_asn_received'),
            Cols::flag('is_ordered', 'Ordered', 'inventory_orders.is_ordered'),
            Cols::flag('is_posted', 'Posted', 'inventory_orders.is_posted'),
            Cols::rows('Invoices', 'inventory_orders.id'),
            Cols::money('asn_total', 'Advance ship notice total', 'inventory_orders.asn_total'),
            Cols::money('invoice_total', 'Invoice total', 'inventory_orders.invoice_total'),
            Cols::ratio('invoice_difference', 'Invoice total minus advance ship notice total', 'money', 'invoice_total - asn_total'),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('inventory_orders');
    }

    public function storeColumn(): string
    {
        return 'inventory_orders.store_number';
    }

    public function dateColumn(): string
    {
        return 'inventory_orders.delivery_date';
    }

    /** store_number already holds the code. */
    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}
