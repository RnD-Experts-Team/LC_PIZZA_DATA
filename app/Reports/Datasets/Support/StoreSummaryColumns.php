<?php

declare(strict_types=1);

namespace App\Reports\Datasets\Support;

use App\Reports\Contract\Column;

/**
 * The measures of daily_store_summary and hourly_store_summary: the two tables
 * have the same columns. The stored ratio columns (average order value, portal
 * and digital rates) are not offered as such, because a stored percentage
 * cannot be added up across days or stores; they come back as derived columns
 * recomputed from the summed counts.
 */
final class StoreSummaryColumns
{
    private const CATEGORIES = [
        'pizza' => 'Pizza',
        'hnr' => 'Hot-N-Ready',
        'bread' => 'Bread',
        'wings' => 'Wings',
        'beverages' => 'Beverages',
        'other_foods' => 'Other foods',
        'side_items' => 'Side items',
    ];

    /** @return list<Column> */
    public static function measures(): array
    {
        $columns = [
            Cols::money('royalty_obligation', 'Royalty obligation (sales royalty is paid on)'),
            Cols::money('gross_sales', 'Gross sales'),
            Cols::money('net_sales', 'Net sales'),
            Cols::money('refund_amount', 'Refunded sales'),

            Cols::whole('total_orders', 'Orders'),
            Cols::whole('completed_orders', 'Completed orders'),
            Cols::whole('cancelled_orders', 'Cancelled orders'),
            Cols::whole('modified_orders', 'Orders modified with a manager override'),
            Cols::whole('refunded_orders', 'Refunded orders'),
            Cols::whole('customer_count', 'Customers'),

            Cols::whole('phone_orders', 'Phone orders'),
            Cols::whole('website_orders', 'Website orders'),
            Cols::whole('mobile_orders', 'Mobile app orders'),
            Cols::whole('call_center_orders', 'Call center orders'),
            Cols::whole('drive_thru_orders', 'Drive-thru orders'),
            Cols::money('phone_sales', 'Phone sales'),
            Cols::money('website_sales', 'Website sales'),
            Cols::money('mobile_sales', 'Mobile app sales'),
            Cols::money('call_center_sales', 'Call center sales'),
            Cols::money('drive_thru_sales', 'Drive-thru sales'),

            Cols::whole('doordash_orders', 'DoorDash orders'),
            Cols::money('doordash_sales', 'DoorDash sales'),
            Cols::whole('ubereats_orders', 'Uber Eats orders'),
            Cols::money('ubereats_sales', 'Uber Eats sales'),
            Cols::whole('grubhub_orders', 'Grubhub orders'),
            Cols::money('grubhub_sales', 'Grubhub sales'),

            // The totals below add up the category splits, so they count items, not orders.
            Cols::whole('delivery_orders', 'Delivery items sold'),
            Cols::money('delivery_sales', 'Delivery sales'),
            Cols::whole('carryout_orders', 'Carryout items sold'),
            Cols::money('carryout_sales', 'Carryout sales'),
        ];

        foreach (self::CATEGORIES as $key => $label) {
            $columns[] = Cols::whole("{$key}_delivery_quantity", "{$label} delivery quantity");
            $columns[] = Cols::money("{$key}_delivery_sales", "{$label} delivery sales");
            $columns[] = Cols::whole("{$key}_carryout_quantity", "{$label} carryout quantity");
            $columns[] = Cols::money("{$key}_carryout_sales", "{$label} carryout sales");
        }

        return [
            ...$columns,
            Cols::money('sales_tax', 'Sales tax'),
            Cols::money('delivery_fees', 'Delivery fees'),
            Cols::money('delivery_tips', 'Delivery tips'),
            Cols::money('store_tips', 'In-store tips'),
            Cols::money('total_tips', 'Total tips'),

            Cols::money('cash_sales', 'Cash sales'),
            Cols::money('over_short', 'Cash over/short'),

            Cols::whole('portal_eligible_orders', 'Orders eligible for the portal'),
            Cols::whole('portal_used_orders', 'Orders put into the portal'),
            Cols::whole('portal_on_time_orders', 'Orders in the portal before the promise time'),

            Cols::whole('digital_orders', 'Digital orders (website and mobile app)'),
            Cols::money('digital_sales', 'Digital sales (website and mobile app)'),

            Cols::whole('hnr_transactions', 'Hot-N-Ready orders'),
            Cols::whole('hnr_broken_promises', 'Hot-N-Ready broken promises'),

            Cols::ratio('avg_order_value', 'Average order value', 'money', 'net_sales / total_orders'),
            Cols::ratio('cancelled_order_rate', 'Cancelled orders (% of orders)', 'number', 'percent(cancelled_orders, total_orders)'),
            Cols::ratio('refunded_order_rate', 'Refunded orders (% of orders)', 'number', 'percent(refunded_orders, total_orders)'),
            Cols::ratio('portal_usage_rate', 'Portal usage (% of eligible orders)', 'number', 'percent(portal_used_orders, portal_eligible_orders)'),
            Cols::ratio('portal_on_time_rate', 'Portal on time (% of orders put into the portal)', 'number', 'percent(portal_on_time_orders, portal_used_orders)'),
            Cols::ratio('digital_penetration', 'Digital share of orders (%)', 'number', 'percent(digital_orders, total_orders)'),
            Cols::ratio('hnr_broken_promise_rate', 'Hot-N-Ready broken promises (% of Hot-N-Ready orders)', 'number', 'percent(hnr_broken_promises, hnr_transactions)'),
        ];
    }
}
