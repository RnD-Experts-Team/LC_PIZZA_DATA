<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Datasets\Support\Cols;
use App\Reports\Datasets\Support\WarehouseDataset;

/** The POS daily sales summary, one row per store and date (summary_sales, hot and archive). */
class SummarySalesDataset extends WarehouseDataset
{
    public const KEY = 'data.summary_sales';

    public function label(): string
    {
        return 'POS daily sales summary';
    }

    protected function table(): string
    {
        return 'summary_sales';
    }

    public function sourceColumns(): array
    {
        return [
            'id', 'franchise_store', 'business_date', 'royalty_obligation', 'customer_count', 'taxable_amount',
            'non_taxable_amount', 'tax_exempt_amount', 'non_royalty_amount', 'refund_amount', 'sales_tax', 'gross_sales',
            'occupational_tax', 'delivery_tip', 'delivery_fee', 'delivery_service_fee', 'delivery_small_order_fee',
            'modified_order_amount', 'store_tip_amount', 'prepaid_cash_orders', 'prepaid_non_cash_orders', 'prepaid_sales',
            'prepaid_delivery_tip', 'prepaid_in_store_tip_amount', 'over_short', 'previous_day_refunds', 'saf', 'manager_notes',
        ];
    }

    public function columns(): array
    {
        return [
            Cols::store(),
            Cols::date(),
            Cols::text('saf', 'Store-and-forward (SAF) note'),
            Cols::text('manager_notes', 'Manager notes'),
            Cols::money('royalty_obligation', 'Royalty obligation (sales royalty is paid on)'),
            Cols::whole('customer_count', 'Customers'),
            Cols::money('taxable_amount', 'Taxable amount'),
            Cols::money('non_taxable_amount', 'Non-taxable amount'),
            Cols::money('tax_exempt_amount', 'Tax-exempt amount'),
            Cols::money('non_royalty_amount', 'Non-royalty amount'),
            Cols::money('refund_amount', 'Refunds'),
            Cols::money('previous_day_refunds', 'Refunds of previous days'),
            Cols::money('sales_tax', 'Sales tax'),
            Cols::money('gross_sales', 'Gross sales'),
            Cols::money('occupational_tax', 'Occupational tax'),
            Cols::money('delivery_tip', 'Delivery tips'),
            Cols::money('delivery_fee', 'Delivery fees'),
            Cols::money('delivery_service_fee', 'Delivery service fees'),
            Cols::money('delivery_small_order_fee', 'Small-order delivery fees'),
            Cols::money('modified_order_amount', 'Modified order amount'),
            Cols::money('store_tip_amount', 'In-store tips'),
            Cols::money('prepaid_cash_orders', 'Prepaid cash orders'),
            Cols::money('prepaid_non_cash_orders', 'Prepaid non-cash orders'),
            Cols::money('prepaid_sales', 'Prepaid sales'),
            Cols::money('prepaid_delivery_tip', 'Prepaid delivery tips'),
            Cols::money('prepaid_in_store_tip_amount', 'Prepaid in-store tips'),
            Cols::money('over_short', 'Cash over/short'),
        ];
    }
}
