<?php

namespace App\Services\Aggregation;

use App\Models\Aggregation\HourlyStoreSummary;
use App\Models\Aggregation\HourlyItemSummary;
use App\Models\Aggregation\DailyStoreSummary;
use App\Models\Aggregation\DailyItemSummary;
use App\Models\Aggregation\WeeklyStoreSummary;
use App\Models\Aggregation\WeeklyItemSummary;
use App\Models\Aggregation\MonthlyStoreSummary;
use App\Models\Aggregation\MonthlyItemSummary;
use App\Models\Aggregation\QuarterlyStoreSummary;
use App\Models\Aggregation\QuarterlyItemSummary;
use App\Models\Aggregation\YearlyStoreSummary;
use App\Models\Aggregation\YearlyItemSummary;
use App\Services\Database\DatabaseRouter;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregationService
{
    /**
     * Build a single query (as a subquery) that unions hot + archive for a base table,
     * filtered to the given date range (via DatabaseRouter).
     *
     * NOTE: Uses Query Builder (not Eloquent).
     */
    private function routedSource(string $baseTable, ?Carbon $startDate = null, ?Carbon $endDate = null): Builder
    {
        $queries = DatabaseRouter::routedQueries($baseTable, $startDate, $endDate);

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        return DB::query()->fromSub($union, 'src');
    }

    private function replaceRow(string $modelClass, array $key, array $data): void
    {
        DB::transaction(function () use ($modelClass, $key, $data) {
            $modelClass::where($key)->delete();
            $modelClass::create($data);
        });
    }

    private function replaceRowsForGroup(string $modelClass, array $scopeKey, array $rows): void
    {
        DB::transaction(function () use ($modelClass, $scopeKey, $rows) {
            $modelClass::where($scopeKey)->delete();

            if (!empty($rows)) {
                $modelClass::insert($rows);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC ENTRY POINTS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Update hourly summaries from RAW transactional data (hot + archive)
     */
    public function updateHourlySummaries(Carbon $date, ?string $rebuildId = null): void
    {
        $dateStr = $date->toDateString();


        $stores = $this->routedSource('detail_orders', $date, $date)
            ->where('business_date', $dateStr)
            ->distinct()
            ->pluck('franchise_store');




        foreach ($stores as $store) {
            try {


                $this->updateHourlyStoreSummary((string) $store, $date, $rebuildId);
                $this->updateHourlyItemSummary((string) $store, $date, $rebuildId);


            } catch (\Throwable $e) {
                Log::error('Hourly aggregation failed for store', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'exception' => $e,
                ]);
            }
        }

    }

    /**
     * Update daily summaries from HOURLY data
     */
    public function updateDailySummaries(Carbon $date, ?string $rebuildId = null): void
    {
        $dateStr = $date->toDateString();



        $stores = HourlyStoreSummary::where('business_date', $dateStr)
            ->distinct()
            ->pluck('franchise_store');




        foreach ($stores as $store) {
            try {


                $this->aggregateDailyFromHourly((string) $store, $date);
                $this->aggregateDailyItemsFromHourly((string) $store, $date);


            } catch (\Throwable $e) {
                Log::error('Daily aggregation failed for store', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'exception' => $e,
                ]);
            }
        }

    }

    /**
     * Update weekly summaries from DAILY data
     */
    public function updateWeeklySummaries(Carbon $date): void
    {
        $weekStart = $date->copy()->startOfWeek(Carbon::TUESDAY);
        $weekEnd = $date->copy()->endOfWeek(Carbon::MONDAY);


        $this->updateWeeklySummariesRange($weekStart, $weekEnd);
    }

    /**
     * Update monthly summaries from WEEKLY data
     */
    public function updateMonthlySummaries(Carbon $date): void
    {
        $year = $date->year;
        $month = $date->month;


        $this->updateMonthlySummariesYearMonth($year, $month);
    }

    /**
     * Update quarterly summaries from MONTHLY data
     */
    public function updateQuarterlySummaries(Carbon $date): void
    {
        $year = $date->year;
        $quarter = (int) ceil($date->month / 3);


        $this->updateQuarterlySummariesYearQuarter($year, $quarter);
    }

    /**
     * Update yearly summaries from QUARTERLY data
     */
    public function updateYearlySummaries(Carbon $date): void
    {
        $year = $date->year;


        $this->updateYearlySummariesYear($year);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HOURLY AGGREGATION FROM RAW DATA (HOT + ARCHIVE)
    // ═══════════════════════════════════════════════════════════════════════════

    private function updateHourlyStoreSummary(string $store, Carbon $date, ?string $rebuildId = null): void
    {
        $dateStr = $date->toDateString();

        $ordersSrc = $this->routedSource('detail_orders', $date, $date);

        $hours = $ordersSrc
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->selectRaw('DISTINCT HOUR(date_time_fulfilled) as hour')
            ->orderBy('hour')
            ->pluck('hour')
            ->map(fn($hour) => (int) $hour)
            ->values();


        foreach ($hours as $hour) {
            try {
                $this->aggregateHourlyStoreData($store, $dateStr, (int) $hour, $rebuildId);


            } catch (\Throwable $e) {
                Log::error('Hourly aggregation failed for store', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'hour' => (int) $hour,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Hourly store summary:
     * - Category splits (delivery vs carryout) are driven by:
     *   menu_item_account in (Pizza/HNR/Bread/Wings/Beverages/Other Foods/Side Items)
     *   delivery = order_fulfilled_method == "Delivery"
     *   carryout = order_fulfilled_method != "Delivery" (including NULL)
     * - delivery_orders/sales and carryout_orders/sales are sums of the category splits
     * - cash_sales hourly is estimated; daily overrides with financial_views "Total Cash Sales"
     */
    private function aggregateHourlyStoreData(string $store, string $date, int $hour, ?string $rebuildId = null): void
    {
        $day = Carbon::parse($date);



        $baseOrders = $this->routedSource('detail_orders', $day, $day)
            ->where('franchise_store', $store)
            ->where('business_date', $date)
            ->whereRaw('HOUR(date_time_fulfilled) = ?', [$hour]);



        $totalSales = (float) (clone $baseOrders)->sum('royalty_obligation');
        $grossSales = (float) (clone $baseOrders)->sum('gross_sales');

        $netSales = (clone $baseOrders)
            ->get(['taxable_amount', 'non_taxable_amount'])
            ->sum(fn($r) => (float) $r->taxable_amount + (float) ($r->non_taxable_amount ?? 0));

        $refundAmount = (float) (clone $baseOrders)
            ->where('refunded', 'Yes')
            ->sum('royalty_obligation');

        $hnrTransactions = (int) (clone $baseOrders)->where('hnrOrder', 'Yes')->count();
        $hnrBrokenPromises = (int) (clone $baseOrders)->where('hnrOrder', 'Yes')->where('broken_promise', 'Yes')->count();

        $totalOrders = (int) (clone $baseOrders)->where('customer_count', '>', 0)->distinct()->count('order_id');

        $refundedOrders = (int) (clone $baseOrders)
            ->where('refunded', 'Yes')
            ->distinct()
            ->count('order_id');

        $modifiedOrders = (int) (clone $baseOrders)
            ->whereNotNull('override_approval_employee')
            ->where('override_approval_employee', '!=', '')
            ->distinct()
            ->count('order_id');

        $cancelledOrders = (int) (clone $baseOrders)
            ->where('transaction_type', 'Cancelled')
            ->distinct()
            ->count('order_id');

        $customerCount = (int) (clone $baseOrders)->sum('customer_count');

        $phoneOrders = (int) (clone $baseOrders)->where('order_placed_method', 'Phone')->distinct()->count('order_id');
        $phoneSales = (float) (clone $baseOrders)->where('order_placed_method', 'Phone')->sum('royalty_obligation');

        $websiteOrders = (int) (clone $baseOrders)->where('order_placed_method', 'Website')->distinct()->count('order_id');
        $websiteSales = (float) (clone $baseOrders)->where('order_placed_method', 'Website')->sum('royalty_obligation');

        $mobileOrders = (int) (clone $baseOrders)->where('order_placed_method', 'Mobile')->distinct()->count('order_id');
        $mobileSales = (float) (clone $baseOrders)->where('order_placed_method', 'Mobile')->sum('royalty_obligation');

        $callCenterOrders = (int) (clone $baseOrders)->whereIn('order_placed_method', ['SoundHoundAgent', 'CallCenterAgent'])->distinct()->count('order_id');
        $callCenterSales = (float) (clone $baseOrders)->whereIn('order_placed_method', ['SoundHoundAgent', 'CallCenterAgent'])->sum('royalty_obligation');

        $driveThruOrders = (int) (clone $baseOrders)->where('order_placed_method', 'Drive Thru')->distinct()->count('order_id');
        $driveThruSales = (float) (clone $baseOrders)->where('order_placed_method', 'Drive Thru')->sum('royalty_obligation');

        $doordashOrders = (int) (clone $baseOrders)->where('order_placed_method', 'DoorDash')->distinct()->count('order_id');
        $doordashSales = (float) (clone $baseOrders)->where('order_placed_method', 'DoorDash')->sum('royalty_obligation');

        $ubereatsOrders = (int) (clone $baseOrders)->where('order_placed_method', 'UberEats')->distinct()->count('order_id');
        $ubereatsSales = (float) (clone $baseOrders)->where('order_placed_method', 'UberEats')->sum('royalty_obligation');

        $grubhubOrders = (int) (clone $baseOrders)->where('order_placed_method', 'Grubhub')->distinct()->count('order_id');
        $grubhubSales = (float) (clone $baseOrders)->where('order_placed_method', 'Grubhub')->sum('royalty_obligation');

        $salesTax = (float) (clone $baseOrders)->sum('sales_tax');
        $deliveryFees = (float) (clone $baseOrders)->sum('delivery_fee');
        $deliveryTips = (float) (clone $baseOrders)->sum('delivery_tip');
        $storeTips = (float) (clone $baseOrders)->sum('store_tip_amount');

        $portalEligible = (int) (clone $baseOrders)->where('portal_eligible', 'Yes')->distinct()->count('order_id');
        $portalUsed = (int) (clone $baseOrders)->where('portal_used', 'Yes')->distinct()->count('order_id');
        $portalOnTime = (int) (clone $baseOrders)->where('put_into_portal_before_promise_time', 'Yes')->distinct()->count('order_id');

        $baseLines = $this->routedSource('order_line', $day, $day)
            ->where('franchise_store', $store)
            ->where('business_date', $date)
            ->whereRaw('HOUR(date_time_fulfilled) = ?', [$hour]);

        $deliveryLines = (clone $baseLines)->where('order_fulfilled_method', 'Delivery');
        $carryoutLines = (clone $baseLines)->where(function ($q) {
            $q->whereNull('order_fulfilled_method')
                ->orWhere('order_fulfilled_method', '!=', 'Delivery');
        });

        $cats = [
            'pizza' => 'Pizza',
            'hnr' => 'HNR',
            'bread' => 'Bread',
            'wings' => 'Wings',
            'beverages' => 'Beverages',
            'other_foods' => 'Other Foods',
            'side_items' => 'Side Items',
        ];

        $split = [];
        $deliveryQtyTotal = 0;
        $deliverySalesTotal = 0.0;
        $carryoutQtyTotal = 0;
        $carryoutSalesTotal = 0.0;

        foreach ($cats as $key => $account) {
            $dQty = (int) (clone $deliveryLines)->where('menu_item_account', $account)->sum('quantity');
            $dSales = (float) (clone $deliveryLines)->where('menu_item_account', $account)->sum('net_amount');

            $cQty = (int) (clone $carryoutLines)->where('menu_item_account', $account)->sum('quantity');
            $cSales = (float) (clone $carryoutLines)->where('menu_item_account', $account)->sum('net_amount');

            $split[$key] = [
                'dQty' => $dQty,
                'dSales' => $dSales,
                'cQty' => $cQty,
                'cSales' => $cSales,
            ];

            $deliveryQtyTotal += $dQty;
            $deliverySalesTotal += $dSales;
            $carryoutQtyTotal += $cQty;
            $carryoutSalesTotal += $cSales;
        }

        $ordersWithPayments = (clone $baseOrders)->get(['payment_methods', 'royalty_obligation']);
        $cashSales = 0.0;

        foreach ($ordersWithPayments as $order) {
            $paymentMethod = (string) ($order->payment_methods ?? '');
            $amount = (float) ($order->royalty_obligation ?? 0);

            if (stripos($paymentMethod, 'Cash') !== false) {
                $cashSales += $amount;
            }
        }

        $overShort = 0.0;

        $digitalOrders = $websiteOrders + $mobileOrders;
        $digitalSales = $websiteSales + $mobileSales;

        $data = [
            'franchise_store' => $store,
            'business_date' => $date,
            'hour' => $hour,

            'royalty_obligation' => round($totalSales, 2),
            'gross_sales' => round($grossSales, 2),
            'net_sales' => round((float) $netSales, 2),
            'refund_amount' => round($refundAmount, 2),

            'total_orders' => $totalOrders,
            'completed_orders' => max(0, $totalOrders - $refundedOrders - $cancelledOrders),
            'cancelled_orders' => $cancelledOrders,
            'modified_orders' => $modifiedOrders,
            'refunded_orders' => $refundedOrders,
            'avg_order_value' => $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0,
            'customer_count' => $customerCount,

            'phone_orders' => $phoneOrders,
            'phone_sales' => round($phoneSales, 2),
            'website_orders' => $websiteOrders,
            'website_sales' => round($websiteSales, 2),
            'mobile_orders' => $mobileOrders,
            'mobile_sales' => round($mobileSales, 2),
            'call_center_orders' => $callCenterOrders,
            'call_center_sales' => round($callCenterSales, 2),
            'drive_thru_orders' => $driveThruOrders,
            'drive_thru_sales' => round($driveThruSales, 2),

            'doordash_orders' => $doordashOrders,
            'doordash_sales' => round($doordashSales, 2),
            'ubereats_orders' => $ubereatsOrders,
            'ubereats_sales' => round($ubereatsSales, 2),
            'grubhub_orders' => $grubhubOrders,
            'grubhub_sales' => round($grubhubSales, 2),

            'delivery_orders' => $deliveryQtyTotal,
            'delivery_sales' => round($deliverySalesTotal, 2),
            'carryout_orders' => $carryoutQtyTotal,
            'carryout_sales' => round($carryoutSalesTotal, 2),

            'pizza_delivery_quantity' => $split['pizza']['dQty'],
            'pizza_delivery_sales' => round($split['pizza']['dSales'], 2),
            'pizza_carryout_quantity' => $split['pizza']['cQty'],
            'pizza_carryout_sales' => round($split['pizza']['cSales'], 2),

            'hnr_delivery_quantity' => $split['hnr']['dQty'],
            'hnr_delivery_sales' => round($split['hnr']['dSales'], 2),
            'hnr_carryout_quantity' => $split['hnr']['cQty'],
            'hnr_carryout_sales' => round($split['hnr']['cSales'], 2),

            'bread_delivery_quantity' => $split['bread']['dQty'],
            'bread_delivery_sales' => round($split['bread']['dSales'], 2),
            'bread_carryout_quantity' => $split['bread']['cQty'],
            'bread_carryout_sales' => round($split['bread']['cSales'], 2),

            'wings_delivery_quantity' => $split['wings']['dQty'],
            'wings_delivery_sales' => round($split['wings']['dSales'], 2),
            'wings_carryout_quantity' => $split['wings']['cQty'],
            'wings_carryout_sales' => round($split['wings']['cSales'], 2),

            'beverages_delivery_quantity' => $split['beverages']['dQty'],
            'beverages_delivery_sales' => round($split['beverages']['dSales'], 2),
            'beverages_carryout_quantity' => $split['beverages']['cQty'],
            'beverages_carryout_sales' => round($split['beverages']['cSales'], 2),

            'other_foods_delivery_quantity' => $split['other_foods']['dQty'],
            'other_foods_delivery_sales' => round($split['other_foods']['dSales'], 2),
            'other_foods_carryout_quantity' => $split['other_foods']['cQty'],
            'other_foods_carryout_sales' => round($split['other_foods']['cSales'], 2),

            'side_items_delivery_quantity' => $split['side_items']['dQty'],
            'side_items_delivery_sales' => round($split['side_items']['dSales'], 2),
            'side_items_carryout_quantity' => $split['side_items']['cQty'],
            'side_items_carryout_sales' => round($split['side_items']['cSales'], 2),

            'sales_tax' => round($salesTax, 2),
            'delivery_fees' => round($deliveryFees, 2),
            'delivery_tips' => round($deliveryTips, 2),
            'store_tips' => round($storeTips, 2),
            'total_tips' => round($deliveryTips + $storeTips, 2),

            'cash_sales' => round($cashSales, 2),
            'over_short' => round($overShort, 2),

            'portal_eligible_orders' => $portalEligible,
            'portal_used_orders' => $portalUsed,
            'portal_on_time_orders' => $portalOnTime,
            'portal_usage_rate' => $portalEligible > 0 ? round(($portalUsed / $portalEligible) * 100, 2) : 0,
            'portal_on_time_rate' => $portalUsed > 0 ? round(($portalOnTime / $portalUsed) * 100, 2) : 0,

            'digital_orders' => $digitalOrders,
            'digital_sales' => round($digitalSales, 2),
            'digital_penetration' => $totalOrders > 0 ? round(($digitalOrders / $totalOrders) * 100, 2) : 0,

            'hnr_transactions' => $hnrTransactions,
            'hnr_broken_promises' => $hnrBrokenPromises,
        ];



        $this->replaceRow(HourlyStoreSummary::class, [
            'franchise_store' => $store,
            'business_date' => $date,
            'hour' => $hour,
        ], $data);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HOURLY ITEM AGGREGATION FROM RAW LINES (HOT + ARCHIVE)
    // ═══════════════════════════════════════════════════════════════════════════

    private function updateHourlyItemSummary(string $store, Carbon $date, ?string $rebuildId = null): void
    {
        $dateStr = $date->toDateString();

        $linesSrc = $this->routedSource('order_line', $date, $date);

        $hours = $linesSrc
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->selectRaw('DISTINCT HOUR(date_time_fulfilled) as hour')
            ->orderBy('hour')
            ->pluck('hour')
            ->map(fn($hour) => (int) $hour)
            ->values();





        foreach ($hours as $hour) {
            try {
                $this->aggregateHourlyItemData($store, $dateStr, (int) $hour, $rebuildId);


            } catch (\Throwable $e) {
                Log::error('Hourly item aggregation failed for store', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'hour' => (int) $hour,
                    'exception' => $e,
                ]);
            }
        }
    }

    private function aggregateHourlyItemData(string $store, string $date, int $hour, ?string $rebuildId = null): void
    {
        $day = Carbon::parse($date);



        $lines = $this->routedSource('order_line', $day, $day)
            ->where('franchise_store', $store)
            ->where('business_date', $date)
            ->whereRaw('HOUR(date_time_fulfilled) = ?', [$hour])
            ->get([
                'franchise_store',
                'business_date',
                'item_id',
                'menu_item_name',
                'menu_item_account',
                'quantity',
                'net_amount',
                'modification_reason',
                'order_fulfilled_method',
                'refunded',
                'modified_order_amount',
            ]);



        $items = $lines->groupBy(
            fn($r) =>
            "{$r->franchise_store}|{$r->business_date}|{$r->item_id}|{$r->menu_item_name}|{$r->menu_item_account}"
        );



        foreach ($items as $group) {
            $first = $group->first();

            $qty = (float) $group->sum('quantity');
            $gross = (float) $group->sum('net_amount');

            $data = [
                'franchise_store' => $first->franchise_store,
                'business_date' => $first->business_date,
                'hour' => $hour,
                'item_id' => $first->item_id,
                'menu_item_name' => $first->menu_item_name,
                'menu_item_account' => $first->menu_item_account,

                'quantity_sold' => $qty,
                'gross_sales' => round($gross, 2),

                'net_sales' => round(
                    (float) $group->filter(fn($r) => empty($r->modification_reason))->sum('net_amount'),
                    2
                ),

                'avg_item_price' => $qty > 0 ? round($gross / $qty, 2) : 0,

                'delivery_quantity' => (float) $group->where('order_fulfilled_method', 'Delivery')->sum('quantity'),
                'carryout_quantity' => (float) $group->filter(
                    fn($r) =>
                    empty($r->order_fulfilled_method) || $r->order_fulfilled_method !== 'Delivery'
                )->sum('quantity'),

                'modified_quantity' => (float) $group->filter(fn($r) => !empty($r->modified_order_amount))->sum('quantity'),
                'refunded_quantity' => (float) $group->where('refunded', 'Yes')->sum('quantity'),
            ];



            $this->replaceRow(HourlyItemSummary::class, [
                'franchise_store' => $first->franchise_store,
                'business_date' => $first->business_date,
                'hour' => $hour,
                'item_id' => $first->item_id,
            ], $data);
        }
    }
    // ═══════════════════════════════════════════════════════════════════════════
    // DAILY AGGREGATION FROM HOURLY
    // ═══════════════════════════════════════════════════════════════════════════

    private function aggregateDailyFromHourly(string $store, Carbon $date): void
    {
        $dateStr = $date->toDateString();

        $hourly = HourlyStoreSummary::where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->get();

        if ($hourly->isEmpty()) {
            return;
        }

        // Over/short from summary_sales (hot+archive)
        $dailySummary = $this->routedSource('summary_sales', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->first();

        $overShort = (float) ($dailySummary->over_short ?? 0);

        // ✅ Cash sales from financial_views (hot+archive) where sub_account = "Total Cash Sales"
        $cashSales = (float) $this->routedSource('financial_views', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->where('sub_account', 'Total Cash Sales')
            ->sum('amount');

        $summary = $this->sumStorePeriod($hourly, [
            'franchise_store' => $store,
            'business_date' => $dateStr,
            'over_short' => $overShort,
        ]);

        // Override daily cash with financial_views truth
        $summary['cash_sales'] = round($cashSales, 2);

        $inStoreTips = (float) $this->routedSource('financial_views', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->whereIn('sub_account', ['InStoreTipAmount', 'Prepaid-InStoreTipAmount'])
            ->sum('amount');

        $deliveryTips = (float) $this->routedSource('financial_views', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->whereIn('sub_account', ['Delivery-Tips', 'Prepaid-Delivery-Tips'])
            ->sum('amount');

        $summary['store_tips'] = round($inStoreTips, 2);
        $summary['delivery_tips'] = round($deliveryTips, 2);
        $summary['total_tips'] = round($deliveryTips + $inStoreTips, 2);
        $this->replaceRow(DailyStoreSummary::class, [
            'franchise_store' => $store,
            'business_date' => $dateStr,
        ], $summary);
    }

    private function aggregateDailyItemsFromHourly(string $store, Carbon $date): void
    {
        $dateStr = $date->toDateString();

        $items = HourlyItemSummary::where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->selectRaw('
                item_id,
                menu_item_name,
                menu_item_account,
                SUM(quantity_sold) as quantity_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                AVG(avg_item_price) as avg_item_price,
                SUM(delivery_quantity) as delivery_quantity,
                SUM(carryout_quantity) as carryout_quantity,
                SUM(modified_quantity) as modified_quantity,
                SUM(refunded_quantity) as refunded_quantity
            ')
            ->groupBy('item_id', 'menu_item_name', 'menu_item_account')
            ->get();

        foreach ($items as $item) {
            $this->replaceRow(DailyItemSummary::class, [
                'franchise_store' => $store,
                'business_date' => $dateStr,
                'item_id' => $item->item_id,
            ], [
                'franchise_store' => $store,
                'business_date' => $dateStr,
                'item_id' => $item->item_id,
                'menu_item_name' => $item->menu_item_name,
                'menu_item_account' => $item->menu_item_account,
                'quantity_sold' => $item->quantity_sold,
                'gross_sales' => round($item->gross_sales, 2),
                'net_sales' => round($item->net_sales, 2),
                'avg_item_price' => round($item->avg_item_price, 2),
                'delivery_quantity' => $item->delivery_quantity,
                'carryout_quantity' => $item->carryout_quantity,
                'modified_quantity' => $item->modified_quantity,
                'refunded_quantity' => $item->refunded_quantity,
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WEEKLY AGGREGATION FROM DAILY
    // ═══════════════════════════════════════════════════════════════════════════

    public function updateWeeklySummariesRange(Carbon $start, Carbon $end): void
    {
        $weeks = DailyStoreSummary::whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('DISTINCT franchise_store, YEAR(business_date) as y, WEEK(business_date, 3) as w')
            ->get();

        foreach ($weeks as $week) {
            $this->aggregateWeeklyStore((string) $week->franchise_store, (int) $week->y, (int) $week->w);
            $this->aggregateWeeklyItems((string) $week->franchise_store, (int) $week->y, (int) $week->w);
        }
    }

    private function aggregateWeeklyStore(string $store, int $year, int $week): void
    {
        $weekStart = Carbon::now()->setISODate($year, $week)->startOfWeek(Carbon::TUESDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::MONDAY);

        $daily = DailyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();

        if ($daily->isEmpty()) {
            return;
        }

        $summary = $this->sumStorePeriod($daily, [
            'franchise_store' => $store,
            'year_num' => $year,
            'week_num' => $week,
            'week_start_date' => $weekStart->toDateString(),
            'week_end_date' => $weekEnd->toDateString(),
        ]);

        $daysCount = $daily->count();
        $summary['avg_daily_sales'] = $daysCount > 0 ? round($summary['royalty_obligation'] / $daysCount, 2) : 0;
        $summary['avg_daily_orders'] = $daysCount > 0 ? round($summary['total_orders'] / $daysCount, 2) : 0;

        $priorWeek = WeeklyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $week == 1 ? $year - 1 : $year)
            ->where('week_num', $week == 1 ? 52 : $week - 1)
            ->first();

        if ($priorWeek) {
            $priorSales = (float) ($priorWeek->royalty_obligation ?? 0);
            $summary['sales_vs_prior_week'] = round($summary['royalty_obligation'] - $priorSales, 2);
            $summary['sales_growth_percent'] = $priorSales > 0
                ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                : 0;
        }

        $priorYear = WeeklyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $year - 1)
            ->where('week_num', $week)
            ->first();

        if ($priorYear) {
            $priorSales = (float) ($priorYear->royalty_obligation ?? 0);
            $summary['sales_vs_same_week_prior_year'] = round($summary['royalty_obligation'] - $priorSales, 2);
            $summary['yoy_growth_percent'] = $priorSales > 0
                ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                : 0;
        }

        $this->replaceRow(WeeklyStoreSummary::class, [
            'franchise_store' => $store,
            'year_num' => $year,
            'week_num' => $week,
        ], $summary);
    }

    private function aggregateWeeklyItems(string $store, int $year, int $week): void
    {
        $weekStart = Carbon::now()->setISODate($year, $week)->startOfWeek(Carbon::TUESDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::MONDAY);

        $items = DailyItemSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->selectRaw('
                item_id,
                menu_item_name,
                menu_item_account,
                SUM(quantity_sold) as quantity_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                AVG(avg_item_price) as avg_item_price,
                AVG(quantity_sold) as avg_daily_quantity,
                SUM(delivery_quantity) as delivery_quantity,
                SUM(carryout_quantity) as carryout_quantity
            ')
            ->groupBy('item_id', 'menu_item_name', 'menu_item_account')
            ->get();

        foreach ($items as $item) {
            $this->replaceRow(WeeklyItemSummary::class, [
                'franchise_store' => $store,
                'year_num' => $year,
                'week_num' => $week,
                'item_id' => $item->item_id,
            ], [
                'franchise_store' => $store,
                'year_num' => $year,
                'week_num' => $week,
                'item_id' => $item->item_id,
                'menu_item_name' => $item->menu_item_name,
                'menu_item_account' => $item->menu_item_account,
                'quantity_sold' => $item->quantity_sold,
                'gross_sales' => round($item->gross_sales, 2),
                'net_sales' => round($item->net_sales, 2),
                'avg_item_price' => round($item->avg_item_price, 2),
                'avg_daily_quantity' => round($item->avg_daily_quantity, 2),
                'delivery_quantity' => $item->delivery_quantity,
                'carryout_quantity' => $item->carryout_quantity,
                'week_start_date' => $weekStart->toDateString(),
                'week_end_date' => $weekEnd->toDateString(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MONTHLY AGGREGATION FROM WEEKLY
    // ═══════════════════════════════════════════════════════════════════════════

    public function updateMonthlySummariesYearMonth(int $year, int $month): void
    {
        $monthStart = Carbon::create($year, $month, 1);
        $monthEnd = $monthStart->copy()->endOfMonth();

        $stores = WeeklyStoreSummary::where('year_num', $year)
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('week_start_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('week_end_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            })
            ->distinct()
            ->pluck('franchise_store');

        foreach ($stores as $store) {
            $this->aggregateMonthlyStore((string) $store, $year, $month);
            $this->aggregateMonthlyItems((string) $store, $year, $month);
        }
    }

    private function aggregateMonthlyStore(string $store, int $year, int $month): void
    {
        $monthStart = Carbon::create($year, $month, 1);
        $monthEnd = $monthStart->copy()->endOfMonth();

        $weekly = WeeklyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $year)
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('week_start_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('week_end_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            })
            ->get();

        if ($weekly->isEmpty()) {
            return;
        }

        $operationalDays = DailyStoreSummary::where('franchise_store', $store)
            ->whereYear('business_date', $year)
            ->whereMonth('business_date', $month)
            ->count();

        $summary = $this->sumStorePeriod($weekly, [
            'franchise_store' => $store,
            'year_num' => $year,
            'month_num' => $month,
            'month_name' => $monthStart->format('F'),
            'operational_days' => $operationalDays,
        ]);

        $priorMonth = MonthlyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $month == 1 ? $year - 1 : $year)
            ->where('month_num', $month == 1 ? 12 : $month - 1)
            ->first();

        if ($priorMonth) {
            $priorSales = (float) ($priorMonth->royalty_obligation ?? 0);
            $summary['sales_vs_prior_month'] = round($summary['royalty_obligation'] - $priorSales, 2);
            $summary['sales_growth_percent'] = $priorSales > 0
                ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                : 0;
        }

        $priorYear = MonthlyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $year - 1)
            ->where('month_num', $month)
            ->first();

        if ($priorYear) {
            $priorSales = (float) ($priorYear->royalty_obligation ?? 0);
            $summary['sales_vs_same_month_prior_year'] = round($summary['royalty_obligation'] - $priorSales, 2);
            $summary['yoy_growth_percent'] = $priorSales > 0
                ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                : 0;
        }

        $this->replaceRow(MonthlyStoreSummary::class, [
            'franchise_store' => $store,
            'year_num' => $year,
            'month_num' => $month,
        ], $summary);
    }

    private function aggregateMonthlyItems(string $store, int $year, int $month): void
    {
        $monthStart = Carbon::create($year, $month, 1);
        $monthEnd = $monthStart->copy()->endOfMonth();

        $items = WeeklyItemSummary::where('franchise_store', $store)
            ->where('year_num', $year)
            ->where(function ($q) use ($monthStart, $monthEnd) {
                $q->whereBetween('week_start_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                    ->orWhereBetween('week_end_date', [$monthStart->toDateString(), $monthEnd->toDateString()]);
            })
            ->selectRaw('
                item_id,
                menu_item_name,
                menu_item_account,
                SUM(quantity_sold) as quantity_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                AVG(avg_item_price) as avg_item_price,
                AVG(avg_daily_quantity) as avg_daily_quantity,
                SUM(delivery_quantity) as delivery_quantity,
                SUM(carryout_quantity) as carryout_quantity
            ')
            ->groupBy('item_id', 'menu_item_name', 'menu_item_account')
            ->get();

        foreach ($items as $item) {
            $this->replaceRow(MonthlyItemSummary::class, [
                'franchise_store' => $store,
                'year_num' => $year,
                'month_num' => $month,
                'item_id' => $item->item_id,
            ], [
                'franchise_store' => $store,
                'year_num' => $year,
                'month_num' => $month,
                'item_id' => $item->item_id,
                'menu_item_name' => $item->menu_item_name,
                'menu_item_account' => $item->menu_item_account,
                'quantity_sold' => $item->quantity_sold,
                'gross_sales' => round($item->gross_sales, 2),
                'net_sales' => round($item->net_sales, 2),
                'avg_item_price' => round($item->avg_item_price, 2),
                'avg_daily_quantity' => round($item->avg_daily_quantity, 2),
                'delivery_quantity' => $item->delivery_quantity,
                'carryout_quantity' => $item->carryout_quantity,
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // QUARTERLY AGGREGATION FROM MONTHLY
    // ═══════════════════════════════════════════════════════════════════════════

    public function updateQuarterlySummariesYearQuarter(int $year, int $quarter): void
    {
        $m1 = ($quarter - 1) * 3 + 1;
        $m3 = $quarter * 3;

        $stores = MonthlyStoreSummary::where('year_num', $year)
            ->whereBetween('month_num', [$m1, $m3])
            ->distinct()
            ->pluck('franchise_store');

        foreach ($stores as $store) {
            $this->aggregateQuarterlyStore((string) $store, $year, $quarter);
            $this->aggregateQuarterlyItems((string) $store, $year, $quarter);
        }
    }

    private function aggregateQuarterlyStore(string $store, int $year, int $quarter): void
    {
        $m1 = ($quarter - 1) * 3 + 1;
        $m3 = $quarter * 3;

        $monthly = MonthlyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $year)
            ->whereBetween('month_num', [$m1, $m3])
            ->get();

        if ($monthly->isEmpty()) {
            return;
        }

        $qStart = Carbon::create($year, $m1, 1);
        $qEnd = Carbon::create($year, $m3, 1)->endOfMonth();

        $summary = $this->sumStorePeriod($monthly, [
            'franchise_store' => $store,
            'year_num' => $year,
            'quarter_num' => $quarter,
            'quarter_start_date' => $qStart->toDateString(),
            'quarter_end_date' => $qEnd->toDateString(),
            'operational_days' => $monthly->sum('operational_days'),
            'operational_months' => $monthly->count(),
        ]);

        $priorQuarter = QuarterlyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $quarter == 1 ? $year - 1 : $year)
            ->where('quarter_num', $quarter == 1 ? 4 : $quarter - 1)
            ->first();

        if ($priorQuarter) {
            $priorSales = (float) ($priorQuarter->royalty_obligation ?? 0);
            $summary['sales_vs_prior_quarter'] = round($summary['royalty_obligation'] - $priorSales, 2);
            $summary['sales_growth_percent'] = $priorSales > 0
                ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                : 0;
        }

        $priorYear = QuarterlyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $year - 1)
            ->where('quarter_num', $quarter)
            ->first();

        if ($priorYear) {
            $priorSales = (float) ($priorYear->royalty_obligation ?? 0);
            $summary['sales_vs_same_quarter_prior_year'] = round($summary['royalty_obligation'] - $priorSales, 2);
            $summary['yoy_growth_percent'] = $priorSales > 0
                ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                : 0;
        }

        $this->replaceRow(QuarterlyStoreSummary::class, [
            'franchise_store' => $store,
            'year_num' => $year,
            'quarter_num' => $quarter,
        ], $summary);
    }

    private function aggregateQuarterlyItems(string $store, int $year, int $quarter): void
    {
        $m1 = ($quarter - 1) * 3 + 1;
        $m3 = $quarter * 3;

        $qStart = Carbon::create($year, $m1, 1);
        $qEnd = Carbon::create($year, $m3, 1)->endOfMonth();

        $items = MonthlyItemSummary::where('franchise_store', $store)
            ->where('year_num', $year)
            ->whereBetween('month_num', [$m1, $m3])
            ->selectRaw('
                item_id,
                menu_item_name,
                menu_item_account,
                SUM(quantity_sold) as quantity_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                AVG(avg_item_price) as avg_item_price,
                AVG(avg_daily_quantity) as avg_daily_quantity,
                SUM(delivery_quantity) as delivery_quantity,
                SUM(carryout_quantity) as carryout_quantity
            ')
            ->groupBy('item_id', 'menu_item_name', 'menu_item_account')
            ->get();

        foreach ($items as $item) {
            $this->replaceRow(QuarterlyItemSummary::class, [
                'franchise_store' => $store,
                'year_num' => $year,
                'quarter_num' => $quarter,
                'item_id' => $item->item_id,
            ], [
                'franchise_store' => $store,
                'year_num' => $year,
                'quarter_num' => $quarter,
                'item_id' => $item->item_id,
                'menu_item_name' => $item->menu_item_name,
                'menu_item_account' => $item->menu_item_account,
                'quantity_sold' => $item->quantity_sold,
                'gross_sales' => round($item->gross_sales, 2),
                'net_sales' => round($item->net_sales, 2),
                'avg_item_price' => round($item->avg_item_price, 2),
                'avg_daily_quantity' => round($item->avg_daily_quantity, 2),
                'delivery_quantity' => $item->delivery_quantity,
                'carryout_quantity' => $item->carryout_quantity,
                'quarter_start_date' => $qStart->toDateString(),
                'quarter_end_date' => $qEnd->toDateString(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // YEARLY AGGREGATION FROM QUARTERLY
    // ═══════════════════════════════════════════════════════════════════════════

    public function updateYearlySummariesYear(int $year): void
    {
        $stores = QuarterlyStoreSummary::where('year_num', $year)
            ->distinct()
            ->pluck('franchise_store');

        foreach ($stores as $store) {
            $this->aggregateYearlyStore((string) $store, $year);
            $this->aggregateYearlyItems((string) $store, $year);
        }
    }

    private function aggregateYearlyStore(string $store, int $year): void
    {
        $quarterly = QuarterlyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $year)
            ->get();

        if ($quarterly->isEmpty()) {
            return;
        }

        $summary = $this->sumStorePeriod($quarterly, [
            'franchise_store' => $store,
            'year_num' => $year,
            'operational_days' => $quarterly->sum('operational_days'),
            'operational_months' => $quarterly->sum('operational_months'),
        ]);

        $priorYear = YearlyStoreSummary::where('franchise_store', $store)
            ->where('year_num', $year - 1)
            ->first();

        if ($priorYear) {
            $priorSales = (float) ($priorYear->royalty_obligation ?? 0);
            $summary['sales_vs_prior_year'] = round($summary['royalty_obligation'] - $priorSales, 2);
            $summary['sales_growth_percent'] = $priorSales > 0
                ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                : 0;
        }

        $this->replaceRow(YearlyStoreSummary::class, [
            'franchise_store' => $store,
            'year_num' => $year,
        ], $summary);
    }

    private function aggregateYearlyItems(string $store, int $year): void
    {
        $items = QuarterlyItemSummary::where('franchise_store', $store)
            ->where('year_num', $year)
            ->selectRaw('
                item_id,
                menu_item_name,
                menu_item_account,
                SUM(quantity_sold) as quantity_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                AVG(avg_item_price) as avg_item_price,
                AVG(avg_daily_quantity) as avg_daily_quantity,
                SUM(delivery_quantity) as delivery_quantity,
                SUM(carryout_quantity) as carryout_quantity
            ')
            ->groupBy('item_id', 'menu_item_name', 'menu_item_account')
            ->get();

        foreach ($items as $item) {
            $this->replaceRow(YearlyItemSummary::class, [
                'franchise_store' => $store,
                'year_num' => $year,
                'item_id' => $item->item_id,
            ], [
                'franchise_store' => $store,
                'year_num' => $year,
                'item_id' => $item->item_id,
                'menu_item_name' => $item->menu_item_name,
                'menu_item_account' => $item->menu_item_account,
                'quantity_sold' => $item->quantity_sold,
                'gross_sales' => round($item->gross_sales, 2),
                'net_sales' => round($item->net_sales, 2),
                'avg_item_price' => round($item->avg_item_price, 2),
                'avg_daily_quantity' => round($item->avg_daily_quantity, 2),
                'delivery_quantity' => $item->delivery_quantity,
                'carryout_quantity' => $item->carryout_quantity,
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPER - SUM ALL METRICS FOR A PERIOD
    // ═══════════════════════════════════════════════════════════════════════════

    private function sumStorePeriod($records, array $base): array
    {
        $summary = $base + [
            // Sales
            'royalty_obligation' => round((float) $records->sum('royalty_obligation'), 2),
            'gross_sales' => round((float) $records->sum('gross_sales'), 2),
            'net_sales' => round((float) $records->sum('net_sales'), 2),
            'refund_amount' => round((float) $records->sum('refund_amount'), 2),

            // Orders
            'total_orders' => (int) $records->sum('total_orders'),
            'completed_orders' => (int) $records->sum('completed_orders'),
            'cancelled_orders' => (int) $records->sum('cancelled_orders'),
            'modified_orders' => (int) $records->sum('modified_orders'),
            'refunded_orders' => (int) $records->sum('refunded_orders'),
            'customer_count' => (int) $records->sum('customer_count'),

            // Channels (Orders)
            'phone_orders' => (int) $records->sum('phone_orders'),
            'website_orders' => (int) $records->sum('website_orders'),
            'mobile_orders' => (int) $records->sum('mobile_orders'),
            'call_center_orders' => (int) $records->sum('call_center_orders'),
            'drive_thru_orders' => (int) $records->sum('drive_thru_orders'),

            // Channels (Sales)
            'phone_sales' => round((float) $records->sum('phone_sales'), 2),
            'website_sales' => round((float) $records->sum('website_sales'), 2),
            'mobile_sales' => round((float) $records->sum('mobile_sales'), 2),
            'call_center_sales' => round((float) $records->sum('call_center_sales'), 2),
            'drive_thru_sales' => round((float) $records->sum('drive_thru_sales'), 2),

            // Marketplace
            'doordash_orders' => (int) $records->sum('doordash_orders'),
            'doordash_sales' => round((float) $records->sum('doordash_sales'), 2),
            'ubereats_orders' => (int) $records->sum('ubereats_orders'),
            'ubereats_sales' => round((float) $records->sum('ubereats_sales'), 2),
            'grubhub_orders' => (int) $records->sum('grubhub_orders'),
            'grubhub_sales' => round((float) $records->sum('grubhub_sales'), 2),

            // Fulfillment totals (these will be recomputed at end to ensure correctness)
            'delivery_orders' => (int) $records->sum('delivery_orders'),
            'delivery_sales' => round((float) $records->sum('delivery_sales'), 2),
            'carryout_orders' => (int) $records->sum('carryout_orders'),
            'carryout_sales' => round((float) $records->sum('carryout_sales'), 2),

            // Category splits (Delivery vs Carryout)
            'pizza_delivery_quantity' => (int) $records->sum('pizza_delivery_quantity'),
            'pizza_delivery_sales' => round((float) $records->sum('pizza_delivery_sales'), 2),
            'pizza_carryout_quantity' => (int) $records->sum('pizza_carryout_quantity'),
            'pizza_carryout_sales' => round((float) $records->sum('pizza_carryout_sales'), 2),

            'hnr_delivery_quantity' => (int) $records->sum('hnr_delivery_quantity'),
            'hnr_delivery_sales' => round((float) $records->sum('hnr_delivery_sales'), 2),
            'hnr_carryout_quantity' => (int) $records->sum('hnr_carryout_quantity'),
            'hnr_carryout_sales' => round((float) $records->sum('hnr_carryout_sales'), 2),

            'bread_delivery_quantity' => (int) $records->sum('bread_delivery_quantity'),
            'bread_delivery_sales' => round((float) $records->sum('bread_delivery_sales'), 2),
            'bread_carryout_quantity' => (int) $records->sum('bread_carryout_quantity'),
            'bread_carryout_sales' => round((float) $records->sum('bread_carryout_sales'), 2),

            'wings_delivery_quantity' => (int) $records->sum('wings_delivery_quantity'),
            'wings_delivery_sales' => round((float) $records->sum('wings_delivery_sales'), 2),
            'wings_carryout_quantity' => (int) $records->sum('wings_carryout_quantity'),
            'wings_carryout_sales' => round((float) $records->sum('wings_carryout_sales'), 2),

            'beverages_delivery_quantity' => (int) $records->sum('beverages_delivery_quantity'),
            'beverages_delivery_sales' => round((float) $records->sum('beverages_delivery_sales'), 2),
            'beverages_carryout_quantity' => (int) $records->sum('beverages_carryout_quantity'),
            'beverages_carryout_sales' => round((float) $records->sum('beverages_carryout_sales'), 2),

            'other_foods_delivery_quantity' => (int) $records->sum('other_foods_delivery_quantity'),
            'other_foods_delivery_sales' => round((float) $records->sum('other_foods_delivery_sales'), 2),
            'other_foods_carryout_quantity' => (int) $records->sum('other_foods_carryout_quantity'),
            'other_foods_carryout_sales' => round((float) $records->sum('other_foods_carryout_sales'), 2),

            'side_items_delivery_quantity' => (int) $records->sum('side_items_delivery_quantity'),
            'side_items_delivery_sales' => round((float) $records->sum('side_items_delivery_sales'), 2),
            'side_items_carryout_quantity' => (int) $records->sum('side_items_carryout_quantity'),
            'side_items_carryout_sales' => round((float) $records->sum('side_items_carryout_sales'), 2),

            // Financial
            'sales_tax' => round((float) $records->sum('sales_tax'), 2),
            'delivery_fees' => round((float) $records->sum('delivery_fees'), 2),
            'delivery_tips' => round((float) $records->sum('delivery_tips'), 2),
            'store_tips' => round((float) $records->sum('store_tips'), 2),
            'total_tips' => round((float) $records->sum('total_tips'), 2),

            // Payments
            'cash_sales' => round((float) $records->sum('cash_sales'), 2),
            'over_short' => round((float) $records->sum('over_short'), 2),

            // Portal
            'portal_eligible_orders' => (int) $records->sum('portal_eligible_orders'),
            'portal_used_orders' => (int) $records->sum('portal_used_orders'),
            'portal_on_time_orders' => (int) $records->sum('portal_on_time_orders'),

            // Digital
            'digital_orders' => (int) $records->sum('digital_orders'),
            'digital_sales' => round((float) $records->sum('digital_sales'), 2),

            'hnr_transactions' => (int) $records->sum('hnr_transactions'),
            'hnr_broken_promises' => (int) $records->sum('hnr_broken_promises'),
        ];

        // Recompute derived rates
        $summary['avg_order_value'] = $summary['total_orders'] > 0
            ? round($summary['royalty_obligation'] / $summary['total_orders'], 2)
            : 0;

        $summary['portal_usage_rate'] = $summary['portal_eligible_orders'] > 0
            ? round(($summary['portal_used_orders'] / $summary['portal_eligible_orders']) * 100, 2)
            : 0;

        $summary['portal_on_time_rate'] = $summary['portal_used_orders'] > 0
            ? round(($summary['portal_on_time_orders'] / $summary['portal_used_orders']) * 100, 2)
            : 0;

        $summary['digital_penetration'] = $summary['total_orders'] > 0
            ? round(($summary['digital_orders'] / $summary['total_orders']) * 100, 2)
            : 0;

        // ✅ Ensure fulfillment totals ALWAYS equal the sum of the category splits
        $summary['delivery_orders'] =
            (int) (
                $summary['pizza_delivery_quantity']
                + $summary['hnr_delivery_quantity']
                + $summary['bread_delivery_quantity']
                + $summary['wings_delivery_quantity']
                + $summary['beverages_delivery_quantity']
                + $summary['other_foods_delivery_quantity']
                + $summary['side_items_delivery_quantity']
            );

        $summary['delivery_sales'] = round(
            $summary['pizza_delivery_sales']
            + $summary['hnr_delivery_sales']
            + $summary['bread_delivery_sales']
            + $summary['wings_delivery_sales']
            + $summary['beverages_delivery_sales']
            + $summary['other_foods_delivery_sales']
            + $summary['side_items_delivery_sales'],
            2
        );

        $summary['carryout_orders'] =
            (int) (
                $summary['pizza_carryout_quantity']
                + $summary['hnr_carryout_quantity']
                + $summary['bread_carryout_quantity']
                + $summary['wings_carryout_quantity']
                + $summary['beverages_carryout_quantity']
                + $summary['other_foods_carryout_quantity']
                + $summary['side_items_carryout_quantity']
            );

        $summary['carryout_sales'] = round(
            $summary['pizza_carryout_sales']
            + $summary['hnr_carryout_sales']
            + $summary['bread_carryout_sales']
            + $summary['wings_carryout_sales']
            + $summary['beverages_carryout_sales']
            + $summary['other_foods_carryout_sales']
            + $summary['side_items_carryout_sales'],
            2
        );

        return $summary;
    }
}
