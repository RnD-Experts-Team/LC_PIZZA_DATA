<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/**
 * Order headers from the POS (detail_orders, hot and archive): one row per
 * order and transaction type. The employee columns hold the POS name, not a
 * Hiring employee id, so the dataset has no employee grain.
 */
class OrdersDataset extends WarehouseDataset
{
    public const KEY = 'data.orders';

    public function label(): string
    {
        return 'Orders';
    }

    public function heavy(): bool
    {
        return true;
    }

    protected function table(): string
    {
        return 'detail_orders';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'order_id', 'date_time_placed', 'date_time_fulfilled', 'promise_date',
            'time_loaded_into_portal', 'transaction_type', 'order_placed_method', 'order_fulfilled_method', 'employee',
            'override_approval_employee', 'user_id', 'payment_methods', 'refunded', 'modification_reason',
            'tax_exemption_entity_name', 'hnrOrder', 'broken_promise', 'portal_eligible', 'portal_used',
            'put_into_portal_before_promise_time', 'portal_compartments_used', 'marketplace_order_number',
            'royalty_obligation', 'gross_sales', 'taxable_amount', 'non_taxable_amount', 'tax_exempt_amount',
            'non_royalty_amount', 'sales_tax', 'occupational_tax', 'quantity', 'customer_count', 'delivery_tip',
            'delivery_tip_tax', 'delivery_fee', 'delivery_fee_tax', 'delivery_service_fee', 'delivery_service_fee_tax',
            'delivery_small_order_fee', 'delivery_small_order_fee_tax', 'store_tip_amount', 'modified_order_amount',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('order_id', 'Order number'),
            Cols::text('transaction_type', 'Transaction type'),
            Cols::text('order_placed_method', 'Channel (how the order was placed)'),
            Cols::text('order_fulfilled_method', 'Service type (how it was fulfilled)'),
            Cols::text('pos_employee', 'Employee (POS name)', 'employee'),
            Cols::text('approver', 'Override approved by (POS name)', 'override_approval_employee'),
            Cols::text('pos_user_id', 'POS user ID', 'user_id'),
            Cols::text('payment_methods', 'Payment methods'),
            Cols::yesNo('refunded', 'Refunded'),
            Cols::text('modification_reason', 'Modification reason'),
            Cols::text('tax_exemption_entity', 'Tax exemption entity', 'tax_exemption_entity_name'),
            Cols::yesNo('hnr_order', 'Hot-N-Ready order', 'hnrOrder'),
            Cols::yesNo('broken_promise', 'Promise time broken'),
            Cols::yesNo('portal_eligible', 'Eligible for the portal'),
            Cols::yesNo('portal_used', 'Put into the portal'),
            Cols::yesNo('portal_on_time', 'Put into the portal before the promise time', 'put_into_portal_before_promise_time'),
            Cols::text('portal_compartments_used', 'Portal compartments used'),
            Cols::text('marketplace_order_number', 'Marketplace order number'),
            Cols::stamp('placed_at', 'Placed at', 'date_time_placed'),
            Cols::stamp('fulfilled_at', 'Fulfilled at', 'date_time_fulfilled'),
            Cols::stamp('promise_at', 'Promised for', 'promise_date'),
            Cols::stamp('loaded_into_portal_at', 'Loaded into the portal at', 'time_loaded_into_portal'),
            Cols::distinct('orders', 'Orders', 'order_id'),
            Cols::rows('Order records', 'id'),
            Cols::money('royalty_obligation', 'Royalty obligation (sales royalty is paid on)'),
            Cols::money('gross_sales', 'Gross sales'),
            Cols::money('net_sales', 'Net sales (taxable plus non-taxable)', 'COALESCE(taxable_amount, 0) + COALESCE(non_taxable_amount, 0)'),
            Cols::money('taxable_amount', 'Taxable amount'),
            Cols::money('non_taxable_amount', 'Non-taxable amount'),
            Cols::money('tax_exempt_amount', 'Tax-exempt amount'),
            Cols::money('non_royalty_amount', 'Non-royalty amount'),
            Cols::money('sales_tax', 'Sales tax'),
            Cols::money('occupational_tax', 'Occupational tax'),
            Cols::whole('quantity', 'Items'),
            Cols::whole('customer_count', 'Customers'),
            Cols::money('delivery_tip', 'Delivery tip'),
            Cols::money('delivery_tip_tax', 'Delivery tip tax'),
            Cols::money('delivery_fee', 'Delivery fee'),
            Cols::money('delivery_fee_tax', 'Delivery fee tax'),
            Cols::money('delivery_service_fee', 'Delivery service fee'),
            Cols::money('delivery_service_fee_tax', 'Delivery service fee tax'),
            Cols::money('delivery_small_order_fee', 'Small-order delivery fee'),
            Cols::money('delivery_small_order_fee_tax', 'Small-order delivery fee tax'),
            Cols::money('store_tip_amount', 'In-store tip'),
            Cols::money('total_tips', 'Total tips (delivery plus in-store)', 'COALESCE(delivery_tip, 0) + COALESCE(store_tip_amount, 0)'),
            Cols::money('modified_order_amount', 'Modified order amount'),
            Cols::ratio('avg_order_value', 'Average order value', 'money', 'royalty_obligation / orders'),
        ];
    }
}
