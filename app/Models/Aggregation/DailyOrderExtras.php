<?php

namespace App\Models\Aggregation;

/** Order-level figures per store and day (channels, special-items HnR, upselling); see the migration. */
class DailyOrderExtras extends AggregationModel
{
    protected $table = 'daily_order_extras';

    protected $fillable = [
        'franchise_store',
        'business_date',
        'customers_total',
        'customers_phone',
        'customers_call_center',
        'customers_drive_thru',
        'customers_doordash',
        'customers_ubereats',
        'customers_grubhub',
        'website_in_store',
        'website_delivery',
        'mobile_in_store',
        'mobile_delivery',
        'website_in_store_customers',
        'website_delivery_customers',
        'mobile_in_store_customers',
        'mobile_delivery_customers',
        'special_hnr_transactions',
        'special_hnr_broken_promises',
        'upsell_crazy_bread',
        'upsell_cookies',
        'upsell_sauce',
        'upsell_wings',
        'upsell_beverages',
        'upsell_crazy_puffs',
        'upsell_bev_20oz',
        'upsell_bev_2l',
        'upsell_italian_cheese_bread',
        'upsell_pizza_base',
        'upsell_emb_cheese',
        'upsell_emb_pepperoni',
    ];
}
