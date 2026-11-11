<?php

namespace App\Support;

/**
 * Which orders and items the order-level figures count — shared by the
 * reports (V1, Business Reports) and the daily aggregation that stores them,
 * so they can never drift apart.
 */
final class OrderBuckets
{
    /** In-store orders: how they were placed and fulfilled. */
    public const IN_STORE = [
        'placed' => ['Register', 'Drive Thru', 'SoundHoundAgent', 'Phone', 'CallCenterAgent'],
        'fulfilled' => ['Register', 'Drive-Thru'],
    ];

    /** Fulfilment methods that count as "in store" for the website/mobile split. */
    public const IN_STORE_FULFILLED = ['Register', 'Drive-Thru', 'In Store Only'];

    /** The special items whose orders the special-items HnR looks at. */
    public const IMPORTANT_ITEMS_HNR_ITEM_IDS = ['103001', '101001', '103044', '105001', '201048'];

    /** Upselling's EMB item ids. */
    public const UPSELL_ITEM_IDS = ['201128', '201106'];

    /** Upselling's EMB items → their names. */
    public const UPSELL_ITEM_NAMES = [
        '201128' => 'EMB Cheese',
        '201106' => 'EMB Pepperoni',
    ];
}
