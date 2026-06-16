<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Aggregation\HourlyStoreSummary;
use App\Services\Aggregation\IntelligentAggregationService;
use App\Services\Analytics\SummaryQueryService;
use App\Services\Database\DatabaseRouter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Aggregation\DailyStoreSummary;
use App\Models\GoalMetric;
use App\Models\EnteredKeyValue;
use App\Models\NonNegotiableReport;
use App\Models\GoToCall;
use App\Models\TransferInOut;
use App\Models\InventoryOrder;
/**
 * DSPR Lite Report Controller
 *
 * - Cached by store + date
 * - ISO week number, business week = Tuesday → Monday
 * - Uses aggregation tables + DatabaseRouter
 */
class ReportsController extends Controller
{
    /**
     * Cache TTL (seconds)
     * 48 hours = 172800
     */
    private const CACHE_TTL = 172800;

    private const UPSELL_ITEM_IDS = ['201128', '201106'];
    private const UPSELL_ITEM_NAMES = [
        '201128' => 'EMB Cheese',
        '201106' => 'EMB Pepperoni',
    ];
    private const LTO_ITEM_IDS = [
        // Add LTO item IDs here, e.g. '201234', '205678'
        '204380'
    ];

    private const LABOR_ENTERED_KEY_ID = 23;
    private const IN_STORE_BUCKET = [
        'placed' => ['Register', 'Drive Thru', 'SoundHoundAgent', 'Phone', 'CallCenterAgent'],
        'fulfilled' => ['Register', 'Drive-Thru'],
    ];

    /**
     * Request-scoped memoization cache.
     *
     * Laravel resolves a fresh controller instance per request, so this is
     * naturally request-scoped: identical (store, range, ...) helper calls
     * across reports execute their DB work once. No cross-request leakage.
     */
    private array $memo = [];

    public function __construct(
        private readonly SummaryQueryService $summaryQuery,
        private readonly IntelligentAggregationService $intelligentAgg,
    ) {
    }

    /**
     * Return the memoized result for $key, computing it via $fn on first miss.
     */
    private function remember(string $key, callable $fn): mixed
    {
        return $this->memo[$key] ??= $fn();
    }

    /**
     * GET /api/reports/dspr-lite/{store}/{date}
     */
    public function dsprLite(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        $payload = $this->buildReport($store, $date);

        return response()->json($payload);
    }

    public function nonNegotiableReports(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildNonNegotiableReports($store, $date));
    }

    private function buildNonNegotiableReports(string $store, string $date)
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        return NonNegotiableReport::where('store_number', $store)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();
    }

    public function goToReport(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildGoToReport($store, $date));
    }

    private function buildGoToReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $calls = GoToCall::where('store_number', $store)
            ->whereBetween('datetime', [$weekStart->toDateTimeString(), $weekEnd->copy()->addDay()->toDateTimeString()])
            ->get();

        $totalCalls = $calls->count();
        $missedCount = $calls->where('status', 'is_missed')->count();
        $storeCount = $calls->where('status', 'is_store')->count();
        $storeManagerCount = $calls->where('status', 'is_store_manager')->count();
        $callCenterCount = $calls->where('status', 'is_call_center')->count();

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'summary' => [
                'total_calls' => $totalCalls,
                'missed' => $missedCount,
                'is_store' => $storeCount,
                'is_store_manager' => $storeManagerCount,
                'is_call_center' => $callCenterCount,
            ],
        ];
    }

    public function transferInOutReport(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildTransferInOutReport($store, $date));
    }

    private function buildTransferInOutReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $prevWeekStart = $weekStart->subWeek();
        $prevWeekEnd = $weekEnd->subWeek();

        $entries = TransferInOut::whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->where(function ($q) use ($store) {
                $q->where('from_store_number', $store)
                    ->orWhere('to_store_number', $store);
            })
            ->get(['date', 'ing_des', 'quantity', 'unit', 'total_cost', 'from_store_number', 'to_store_number']);

        $blueLineCurrent = (float) InventoryOrder::where('store_number', $store)
            ->whereBetween('delivery_date', [$weekStart->toDateString(), $day->toDateString()])
            ->where('vendor_name', 'like', '%BLUE LINE%')
            ->sum('invoice_total');

        $blueLinePrevious = (float) InventoryOrder::where('store_number', $store)
            ->whereBetween('delivery_date', [$prevWeekStart->toDateString(), $prevWeekEnd->toDateString()])
            ->where('vendor_name', 'like', '%BLUE LINE%')
            ->sum('invoice_total');

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'entries' => $entries,
            'sales' => [
                'current_week' => $this->salesTotal($store, $weekStart, $day),
                'previous_week' => $this->salesTotal($store, $prevWeekStart, $prevWeekEnd),
            ],
            'blue_line' => [
                'current_week' => round($blueLineCurrent, 2),
                'previous_week' => round($blueLinePrevious, 2),
            ]
        ];
    }

    public function ordersVsSalesReport(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildOrdersVsSalesReport($store, $date));
    }

    private function buildOrdersVsSalesReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'current_week' => $this->ordersVsSalesPeriod($store, $weekStart, $weekEnd),
            'four_weeks' => $this->ordersVsSalesPeriod($store, $weekStart->subWeeks(3), $weekEnd),
            'twelve_weeks' => $this->ordersVsSalesPeriod($store, $weekStart->subWeeks(11), $weekEnd),
            'six_months' => $this->ordersVsSalesPeriod($store, $weekStart->subMonths(6), $weekEnd),
        ];
    }

    private function ordersVsSalesPeriod(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sales = $this->salesTotal($store, $start, $end);

        $blueLine = (float) InventoryOrder::where('store_number', $store)
            ->whereBetween('delivery_date', [$start->toDateString(), $end->toDateString()])
            ->where('vendor_name', 'like', '%BLUE LINE%')
            ->sum('invoice_total');

        $pepsi = (float) InventoryOrder::where('store_number', $store)
            ->whereBetween('delivery_date', [$start->toDateString(), $end->toDateString()])
            ->where('vendor_name', 'like', '%PEPSI%')
            ->sum('invoice_total');

        return [
            'sales' => round($sales, 2),
            'blue_line_total' => round($blueLine, 2),
            'pepsi_total' => round($pepsi, 2),
            'blue_line_pct' => $sales > 0 ? round($blueLine / $sales * 100, 2) : 0,
            'pepsi_pct' => $sales > 0 ? round($pepsi / $sales * 100, 2) : 0,
        ];
    }

    /**
     * GET /api/reports/channel-sales/{store}/{date}
     */
    public function channelSales(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildChannelSales($store, $date));
    }

    private function buildChannelSales(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $prevWeekStart = $weekStart->subWeeks(1);
        $prevWeekEnd = $weekEnd->subWeeks(1);

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'current_week' => [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $day->toDateString(),
                ...$this->channelSalesForRange($store, $weekStart, $day),
            ],
            'previous_week' => [
                'week_start' => $prevWeekStart->toDateString(),
                'week_end' => $prevWeekEnd->toDateString(),
                ...$this->channelSalesForRange($store, $prevWeekStart, $prevWeekEnd),
            ],
        ];
    }

    /**
     * GET /api/reports/promo/{store}/{date}
     */
    public function promoReport(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);
        return response()->json($this->buildPromoReport($store, $date));
    }

    /**
     * GET /api/reports/lto/{store}/{date}
     */
    public function ltoReport(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);
        return response()->json($this->buildLtoReport($store, $date));
    }

    /**
     * GET /api/reports/phone-and-adjusted-sales/{store}/{date}
     */
    public function phoneAndAdjustedSales(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildPhoneAndAdjustedSalesReport($store, $date));
    }

    /**
     * GET /api/reports/portal-weekly/{store}/{date}
     */
    public function portalWeekly(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildPortalWeeklyReport($store, $date));
    }

    /**
     * GET /api/reports/customer-count-and-sales/{store}/{date}
     */
    public function customerCountAndSales(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildCustomerCountAndSalesReport($store, $date));
    }

    /**
     * GET /api/reports/dashboard/{store}/{date}
     *
     * Combined endpoint: every per-page report in a single response, keyed by
     * its URL slug. Each value is byte-for-byte identical to the standalone
     * endpoint. Overlapping DB work is deduped within the request via remember().
     */
    public function dashboard(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json([
            'dspr' => $this->buildReport($store, $date),
            'customer-count-and-sales' => $this->buildCustomerCountAndSalesReport($store, $date),
            'portal-weekly' => $this->buildPortalWeeklyReport($store, $date),
            'channel-sales' => $this->buildChannelSales($store, $date),
            'phone-and-adjusted-sales' => $this->buildPhoneAndAdjustedSalesReport($store, $date),
            'cash-control' => $this->buildCashControlReport($store, $date),
            'lto' => $this->buildLtoReport($store, $date),
            'promo' => $this->buildPromoReport($store, $date),
            'non-negotiable-reports' => $this->buildNonNegotiableReports($store, $date),
            'go-to' => $this->buildGoToReport($store, $date),
            'transfer-in-out' => $this->buildTransferInOutReport($store, $date),
            'orders-vs-sales' => $this->buildOrdersVsSalesReport($store, $date),
        ]);
    }

    // ---------------------------------------------------------------------
    // Core builder (cached)
    // ---------------------------------------------------------------------

    private function buildReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $prevWeekStart = $weekStart->subWeek();
        $prevWeekEnd = $weekEnd->subWeek();

        // Same business week last year (Tue–Mon)
        $lastYearWeekStart = $weekStart->subWeeks(52);
        $lastYearWeekEnd = $weekEnd->subWeeks(52);

        $weekToDateStart = $weekStart;
        $weekToDateEnd = $day;
        $weekToDateDayCount = (int) max(1, $weekToDateStart->diffInDays($weekToDateEnd) + 1);
        $daily = $this->dailySummary($store, $day);

        $hourlySalesByChannel = $this->hourlySalesByChannel($store, $day);
        $hourlySalesWeekToDateAvg = $this->hourlySalesByChannelAverage($store, $weekToDateStart, $weekToDateEnd);
        $hourlySalesWeekToDateSum = $this->hourlySalesByChannelSum($store, $weekToDateStart, $weekToDateEnd);
        $weekToDateTotals = $this->dailySummaryTotals($store, $weekToDateStart, $weekToDateEnd);
        $weekToDateTotalsAvg = $this->averageWeekToDateTotals($weekToDateTotals, $weekToDateDayCount);
        $weekToDateSalesTotals = $this->totalSalesByChannelForRange($store, $weekToDateStart, $weekToDateEnd);
        $weekToDateSalesTotalsAvg = $this->averageSalesByChannelTotals($weekToDateSalesTotals, $weekToDateDayCount);
        $weekToDateTopItems = $this->topItemsForRange($store, $weekToDateStart, $weekToDateEnd, 5, 'gross_sales');
        $weekToDateTopItemsByCount = $this->topItemsForRange($store, $weekToDateStart, $weekToDateEnd, 5, 'quantity_sold');
        $weekToDatePortal = $this->portalMetricsForRange($store, $weekToDateStart, $weekToDateEnd);
        $weekToDatePortalAvg = $this->portalMetricsAverage($weekToDatePortal, $weekToDateDayCount);
        $weekToDateDeposit = $this->totalDepositForRange($store, $weekToDateStart, $weekToDateEnd);
        $weekToDateWasteAlta = $this->altaInventoryWasteForRange($store, $weekToDateStart, $weekToDateEnd);
        $weekToDateWasteNormal = $this->normalWasteForRange($store, $weekToDateStart, $weekToDateEnd);
        $weekToDateTips = $this->summaryQuery->getTotalTips(
            $store,
            $weekToDateStart->toMutable(),
            $weekToDateEnd->toMutable()
        );

        $thisWeekByDay = $this->salesByDay($store, $weekStart, $weekEnd);
        $previousWeekByDay = $this->salesByDay($store, $prevWeekStart, $prevWeekEnd);
        $lastYearWeekByDay = $this->salesByDay($store, $lastYearWeekStart, $lastYearWeekEnd);

        $thisWeekTotal = $this->sumSalesByDay($thisWeekByDay);
        $previousWeekTotal = $this->sumSalesByDay($previousWeekByDay);
        $lastYearWeekTotal = $this->sumSalesByDay($lastYearWeekByDay);

        $daySales = (float) ($thisWeekByDay[$day->toDateString()] ?? 0.0);
        $weekToDateSalesTotal = $this->sumSalesByDayRange($thisWeekByDay, $weekToDateStart, $weekToDateEnd);
        $weekToDateSalesAvg = $this->averageValue($weekToDateSalesTotal, $weekToDateDayCount);

        $laborValueDay = $this->enteredKeyValueSumForRange(
            $store,
            self::LABOR_ENTERED_KEY_ID,
            $day,
            $day
        );
        $laborWeekToDateSum = $this->enteredKeyValueSumForRange(
            $store,
            self::LABOR_ENTERED_KEY_ID,
            $weekToDateStart,
            $weekToDateEnd
        );
        $laborWeekToDateAvgValue = $this->averageValue($laborWeekToDateSum, $weekToDateDayCount);

        $laborPercent = $this->percentOfSales($laborValueDay, $daySales);
        $laborWeekToDatePercent = $this->percentOfSales($laborWeekToDateSum, $weekToDateSalesTotal);
        $laborWeekToDateAvgPercent = $this->percentOfSales($laborWeekToDateAvgValue, $weekToDateSalesAvg);

        $upsellingDay = $this->upsellingForRange($store, $day, $day);
        $upsellingWeekToDate = $this->upsellingForRange($store, $weekToDateStart, $weekToDateEnd);
        $totalUpsellingDay = $this->totalUpsellingUnits($upsellingDay);
        $totalUpsellingWeekToDate = $this->totalUpsellingUnits($upsellingWeekToDate);

        $totalSales = [
            'royalty_obligation' => 0,
            'phone_sales' => 0,
            'call_center_sales' => 0,
            'drive_thru_sales' => 0,
            'website_sales' => 0,
            'mobile_sales' => 0,
            'doordash_sales' => 0,
            'ubereats_sales' => 0,
            'grubhub_sales' => 0,
        ];

        // Loop through the hourly data and sum the sales for each channel
        foreach ($hourlySalesByChannel as $hourlyData) {
            $totalSales['royalty_obligation'] += $hourlyData['royalty_obligation'];
            $totalSales['phone_sales'] += $hourlyData['phone_sales'];
            $totalSales['call_center_sales'] += $hourlyData['call_center_sales'];
            $totalSales['drive_thru_sales'] += $hourlyData['drive_thru_sales'];
            $totalSales['website_sales'] += $hourlyData['website_sales'];
            $totalSales['mobile_sales'] += $hourlyData['mobile_sales'];
            $totalSales['doordash_sales'] += $hourlyData['doordash_sales'];
            $totalSales['ubereats_sales'] += $hourlyData['ubereats_sales'];
            $totalSales['grubhub_sales'] += $hourlyData['grubhub_sales'];
        }

        $adjustedTotalSales = $totalSales['royalty_obligation'] - (
            $totalSales['phone_sales'] +
            $totalSales['call_center_sales'] +
            $totalSales['drive_thru_sales'] +
            $totalSales['website_sales'] +
            $totalSales['mobile_sales'] +
            $totalSales['doordash_sales'] +
            $totalSales['ubereats_sales'] +
            $totalSales['grubhub_sales']
        );
        foreach ($hourlySalesByChannel as &$hourlyData) {
            $hourlyData['adjusted_royalty_obligation'] = $hourlyData['royalty_obligation'] - (
                $hourlyData['phone_sales'] +
                $hourlyData['call_center_sales'] +
                $hourlyData['drive_thru_sales'] +
                $hourlyData['website_sales'] +
                $hourlyData['mobile_sales'] +
                $hourlyData['doordash_sales'] +
                $hourlyData['ubereats_sales'] +
                $hourlyData['grubhub_sales']
            );
        }
        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'iso_week' => $day->isoWeek(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'goal_metrics' => $this->getGoalsForStoreDate($store, $date),
            'sales' => [
                'this_week_by_day' => $thisWeekByDay,
                'previous_week_by_day' => $previousWeekByDay,
                'same_week_last_year_by_day' => $lastYearWeekByDay,
                'this_week_total' => $thisWeekTotal,
                'previous_week_total' => $previousWeekTotal,
                'same_week_last_year_total' => $lastYearWeekTotal,
            ],

            'top' => [
                'top_5_items_sales_for_day' => $this->topItemsForDay($store, $day, 5, 'gross_sales'),
                'top_5_items_sales_week_to_date' => $weekToDateTopItems,
                'top_5_items_sales_week_to_date_avg' => $this->averageTopItems($weekToDateTopItems, $weekToDateDayCount),

                'top_5_items_count_for_day' => $this->topItemsForDay($store, $day, 5, 'quantity_sold'),
                'top_5_items_count_week_to_date' => $weekToDateTopItemsByCount,
                'top_5_items_count_week_to_date_avg' => $this->averageTopItems($weekToDateTopItemsByCount, $weekToDateDayCount),

                'ingredients' => [
                    'top_5_ingredients_variance_high' => $this->topIngredientsForDay($store, $day, 5, 'desc'),
                    'top_5_ingredients_variance_low' => $this->topIngredientsForDay($store, $day, 5, 'asc'),

                    'main_5_ingredients_usage' => $this->mainFiveIngredientsUsage($store, $day),

                    'top_paper_5_ingredients_usage' => $this->topPaperFiveIngredientsUsage($store, $day),
                ],
            ],

            'day' => [
                'hourly_sales_and_channels' => $hourlySalesByChannel,
                'hourly_sales_and_channels_week_to_date_avg' => $hourlySalesWeekToDateAvg,
                'hourly_sales_and_channels_week_to_date_sum' => $hourlySalesWeekToDateSum,
                'total_sales' => [
                    'royalty_obligation' => round($adjustedTotalSales, 2),
                    'phone_sales' => round($totalSales['phone_sales'], 2),
                    'call_center_sales' => round($totalSales['call_center_sales'], 2),
                    'drive_thru_sales' => round($totalSales['drive_thru_sales'], 2),
                    'website_sales' => round($totalSales['website_sales'], 2),
                    'mobile_sales' => round($totalSales['mobile_sales'], 2),
                    'doordash_sales' => round($totalSales['doordash_sales'], 2),
                    'ubereats_sales' => round($totalSales['ubereats_sales'], 2),
                    'grubhub_sales' => round($totalSales['grubhub_sales'], 2),
                ],
                'total_sales_week_to_date' => $weekToDateSalesTotals,
                'total_sales_week_to_date_avg' => $weekToDateSalesTotalsAvg,

                'total_cash_sales' => (float) ($daily->cash_sales ?? 0),
                'total_cash_sales_week_to_date' => (float) ($weekToDateTotals['cash_sales'] ?? 0),
                'total_cash_sales_week_to_date_avg' => (float) ($weekToDateTotalsAvg['cash_sales'] ?? 0),
                'total_deposit' => $this->totalDepositForDay($store, $day),
                'total_deposit_week_to_date' => $weekToDateDeposit,
                'total_deposit_week_to_date_avg' => $this->averageValue($weekToDateDeposit, $weekToDateDayCount),

                'over_short' => (float) ($daily->over_short ?? 0),
                'over_short_week_to_date' => (float) ($weekToDateTotals['over_short'] ?? 0),
                'over_short_week_to_date_avg' => (float) ($weekToDateTotalsAvg['over_short'] ?? 0),

                'refunded_orders' => [
                    'count' => (int) ($daily->refunded_orders ?? 0),
                    'sales' => (float) ($daily->refund_amount ?? 0),
                ],
                'refunded_orders_week_to_date' => [
                    'count' => (int) ($weekToDateTotals['refunded_orders'] ?? 0),
                    'sales' => (float) ($weekToDateTotals['refund_amount'] ?? 0),
                ],
                'refunded_orders_week_to_date_avg' => [
                    'count' => (float) ($weekToDateTotalsAvg['refunded_orders'] ?? 0),
                    'sales' => (float) ($weekToDateTotalsAvg['refund_amount'] ?? 0),
                ],

                'customer_count' => (int) ($daily->customer_count ?? 0),
                'customer_count_week_to_date' => (int) ($weekToDateTotals['customer_count'] ?? 0),
                'customer_count_week_to_date_avg' => (float) ($weekToDateTotalsAvg['customer_count'] ?? 0),

                'waste' => [
                    'alta_inventory' => $this->altaInventoryWasteForDay($store, $day),
                    'normal' => $this->normalWasteForDay($store, $day),
                ],
                'waste_week_to_date' => [
                    'alta_inventory' => $weekToDateWasteAlta,
                    'normal' => $weekToDateWasteNormal,
                ],
                'waste_week_to_date_avg' => [
                    'alta_inventory' => $this->averageValue($weekToDateWasteAlta, $weekToDateDayCount),
                    'normal' => $this->averageValue($weekToDateWasteNormal, $weekToDateDayCount),
                ],

                'total_tips' => $this->summaryQuery->getTotalTips($store, $day->toMutable(), $day->toMutable()),
                'total_tips_week_to_date' => $weekToDateTips,
                'total_tips_week_to_date_avg' => $this->averageValue($weekToDateTips, $weekToDateDayCount),

                'hnr' => [
                    'hnr_transactions' => (int) ($daily->hnr_transactions ?? 0),
                    'hnr_broken_promises' => (int) ($daily->hnr_broken_promises ?? 0),
                    'hnr_promise_met' => (int) ($daily->hnr_transactions ?? 0) - (int) ($daily->hnr_broken_promises ?? 0),
                    'hnr_promise_met_percent' => ((int) ($daily->hnr_transactions ?? 0) > 0)
                        ? round((((int) ($daily->hnr_transactions ?? 0) - (int) ($daily->hnr_broken_promises ?? 0)) /
                            (int) ($daily->hnr_transactions ?? 0)) * 100, 2)
                        : 0.0,
                ],
                'hnr_week_to_date' => $this->hnrTotals($weekToDateTotals),
                'hnr_week_to_date_avg' => $this->hnrTotalsAverage($weekToDateTotals, $weekToDateDayCount),

                'upselling' => [
                    'day' => $upsellingDay,
                    'week_to_date' => $upsellingWeekToDate,
                    'total_upselling_day' => $totalUpsellingDay,
                    'total_upselling_week_to_date' => $totalUpsellingWeekToDate,
                ],

                'labor' => $laborPercent,
                'labor_week_to_date' => $laborWeekToDatePercent,
                'labor_week_to_date_avg' => $laborWeekToDateAvgPercent,

                'portal' => array_merge($this->portalMetrics($store, $day), [
                    'week_to_date' => $weekToDatePortal,
                    'week_to_date_avg' => $weekToDatePortalAvg,
                ]),
            ],
        ];
    }

    private function dailySummary(string $store, CarbonImmutable $day): ?DailyStoreSummary
    {
        return DailyStoreSummary::where('franchise_store', $store)
            ->where('business_date', $day->toDateString())
            ->first();
    }

    // ---------------------------------------------------------------------
    // Cache helpers
    // ---------------------------------------------------------------------

    private function cacheKey(string $store, string $date): string
    {
        return sprintf('reports:dspr-lite:%s:%s', strtolower($store), $date);
    }
    private function getGoalsForStoreDate(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        // Goals are weekly ranges, so keep any goal that overlaps the report week.
        $goalMetrics = GoalMetric::whereHas('goals', function ($query) use ($store, $weekStart, $weekEnd) {
            $query->where('store_id', $store)
                ->whereDate('week_start_date', '<=', $weekEnd)
                ->whereDate('week_end_date', '>=', $weekStart);
        })
            ->with([
                'goals' => function ($query) use ($store, $weekStart, $weekEnd) {
                    $query->where('store_id', $store)
                        ->whereDate('week_start_date', '<=', $weekEnd)
                        ->whereDate('week_end_date', '>=', $weekStart);
                }
            ])
            ->orderBy('name')
            ->get();

        return $goalMetrics->map(function ($metric) {
            return [
                'metric_id' => $metric->id,
                'metric_name' => $metric->name,
                'goals' => $metric->goals->map(fn($goal) => [
                    'goal_id' => $goal->id,
                    'week_start_date' => $goal->week_start_date->toDateString(),
                    'week_end_date' => $goal->week_end_date->toDateString(),
                    'goal' => $goal->goal,
                ])->toArray(),
            ];
        })->toArray();
    }
    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    private function validateInputs(string $store, string $date): void
    {
        if ($store === '') {
            throw ValidationException::withMessages(['store' => 'Store is required']);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages(['date' => 'Invalid date format']);
        }
    }

    // ---------------------------------------------------------------------
    // Week helpers
    // ---------------------------------------------------------------------

    private function isoBusinessWeek(CarbonImmutable $date): array
    {
        $start = $date->startOfWeek(CarbonInterface::TUESDAY);
        return [$start, $start->addDays(6)];
    }

    // ---------------------------------------------------------------------
    // Sales
    // ---------------------------------------------------------------------

    private function salesByDay(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = "salesByDay:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, function () use ($store, $start, $end) {
            $out = [];

            for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                $out[$d->toDateString()] = $this->summaryQuery->getSales(
                    $store,
                    $d->toMutable(),
                    $d->toMutable()
                );
            }

            return $out;
        });
    }

    private function sumSalesByDay(array $salesByDay): float
    {
        $total = 0.0;

        foreach ($salesByDay as $value) {
            $total += (float) $value;
        }

        return $total;
    }

    private function sumSalesByDayRange(
        array $salesByDay,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $total = 0.0;

        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $total += (float) ($salesByDay[$d->toDateString()] ?? 0.0);
        }

        return $total;
    }

    private function salesTotal(string $store, CarbonImmutable $start, CarbonImmutable $end): float
    {
        $key = "salesTotal:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): float => (float) $this->summaryQuery->getSales(
            $store,
            $start->toMutable(),
            $end->toMutable()
        ));
    }

    private function dailySummaryTotals(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = "dailySummaryTotals:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => $this->computeDailySummaryTotals($store, $start, $end));
    }

    private function computeDailySummaryTotals(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = DailyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'COALESCE(SUM(cash_sales), 0) as cash_sales,'
                . ' COALESCE(SUM(over_short), 0) as over_short,'
                . ' COALESCE(SUM(refunded_orders), 0) as refunded_orders,'
                . ' COALESCE(SUM(refund_amount), 0) as refund_amount,'
                . ' COALESCE(SUM(customer_count), 0) as customer_count,'
                . ' COALESCE(SUM(hnr_transactions), 0) as hnr_transactions,'
                . ' COALESCE(SUM(hnr_broken_promises), 0) as hnr_broken_promises'
            )
            ->first();

        return [
            'cash_sales' => (float) ($totals->cash_sales ?? 0),
            'over_short' => (float) ($totals->over_short ?? 0),
            'refunded_orders' => (int) ($totals->refunded_orders ?? 0),
            'refund_amount' => (float) ($totals->refund_amount ?? 0),
            'customer_count' => (int) ($totals->customer_count ?? 0),
            'hnr_transactions' => (int) ($totals->hnr_transactions ?? 0),
            'hnr_broken_promises' => (int) ($totals->hnr_broken_promises ?? 0),
        ];
    }

    private function hnrTotals(array $totals): array
    {
        $transactions = (int) ($totals['hnr_transactions'] ?? 0);
        $broken = (int) ($totals['hnr_broken_promises'] ?? 0);
        $promiseMet = $transactions - $broken;

        return [
            'hnr_transactions' => $transactions,
            'hnr_broken_promises' => $broken,
            'hnr_promise_met' => $promiseMet,
            'hnr_promise_met_percent' => $transactions > 0
                ? round(($promiseMet / $transactions) * 100, 2)
                : 0.0,
        ];
    }

    // ---------------------------------------------------------------------
    // Top Items
    // ---------------------------------------------------------------------

    private function topItemsForDay(
        string $store,
        CarbonImmutable $day,
        int $limit,
        string $orderByField = 'gross_sales'
    ): array {
        return $this->topItemsForRange($store, $day, $day, $limit, $orderByField);
    }

    private function topItemsForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $limit,
        string $orderByField = 'gross_sales'
    ): array {
        $orderByField = $orderByField === 'quantity_sold' ? 'quantity_sold' : 'gross_sales';

        $key = "topItemsForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}:{$limit}:{$orderByField}";

        return $this->remember($key, fn(): array => $this->computeTopItemsForRange($store, $start, $end, $limit, $orderByField));
    }

    private function computeTopItemsForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $limit,
        string $orderByField
    ): array {
        $result = $this->intelligentAgg->fetchAggregatedData([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'summary_type' => 'item',
            'metrics' => [
                ['field' => 'gross_sales', 'agg' => 'SUM', 'alias' => 'gross_sales'],
                ['field' => 'quantity_sold', 'agg' => 'SUM', 'alias' => 'quantity_sold'],
            ],
            'filters' => ['franchise_store' => $store],
            'order_by' => $orderByField . ' DESC',
            'limit' => $limit,
        ]);

        return $result['data'] ?? [];
    }

    private function averageTopItems(array $items, int $days): array
    {
        if ($days <= 0) {
            return $items;
        }

        return array_map(static function (array $item) use ($days) {
            if (isset($item['quantity_sold'])) {
                $item['quantity_sold'] = round((float) $item['quantity_sold'] / $days, 2);
            }

            if (isset($item['gross_sales'])) {
                $item['gross_sales'] = round((float) $item['gross_sales'] / $days, 2);
            }

            return $item;
        }, $items);
    }

    // ---------------------------------------------------------------------
    // ✅ FIXED: Top Ingredients (uses correct schema)
    // ---------------------------------------------------------------------

    private function topIngredientsForDay(
        string $store,
        CarbonImmutable $day,
        int $limit = 5,
        string $direction = 'desc'
    ): array {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $queries = DatabaseRouter::routedQueries(
            'alta_inventory_ingredient_usage',
            $day->toMutable(),
            $day->toMutable()
        );

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        $varianceExpr = DB::raw('SUM(variance_qty) * SUM(ingredient_unit_cost)');

        return DB::query()
            ->fromSub($union, 'u')
            ->where('franchise_store', $store)
            ->groupBy('ingredient_id', 'ingredient_description')
            ->orderBy($varianceExpr, $direction)
            ->limit($limit)
            ->get([
                'ingredient_id',
                'ingredient_description',
                DB::raw('SUM(actual_usage) as total_actual_usage'),
                DB::raw('SUM(variance_qty) * SUM(ingredient_unit_cost) as total_variance_value'),
            ])
            ->map(static function ($row) {
                return [
                    'ingredient_id' => $row->ingredient_id,
                    'ingredient_description' => $row->ingredient_description,
                    'actual_usage' => round((float) $row->total_actual_usage, 2),
                    'variance_value' => round((float) $row->total_variance_value, 2),
                ];
            })
            ->toArray();
    }

    private function mainFiveIngredientsUsage(string $store, CarbonImmutable $day): array
    {
        $ids = [1515, 1103, 3813, 1042, 404, 406];

        $results = $this->fetchIngredientSet($store, $day, $ids);

        // 🔥 Merge 404 + 406
        $merged = [];
        $totalUsage = 0;
        $variance = 0;

        foreach ($results as $row) {
            if (in_array($row['ingredient_id'], [404, 406])) {
                $totalUsage += $row['actual_usage'];
                $variance += $row['variance_value'];
            } else {
                $merged[] = $row;
            }
        }

        if ($totalUsage > 0) {
            $merged[] = [
                'ingredient_id' => 404,
                'ingredient_description' => $this->getIngredientName($results, 404),
                'actual_usage' => round($totalUsage, 2),
                'variance_value' => round($variance, 2),
            ];
        }

        // Sort descending by variance
        usort($merged, fn($a, $b) => $b['variance_value'] <=> $a['variance_value']);

        return $merged;
    }

    private function topPaperFiveIngredientsUsage(string $store, CarbonImmutable $day): array
    {
        $ids = [4659, '4660/4621', 5858, 4540, 4342];

        $results = $this->fetchIngredientSet($store, $day, $ids);

        usort($results, fn($a, $b) => $b['variance_value'] <=> $a['variance_value']);

        return $results;
    }

    private function getIngredientName(array $results, int $id): string
    {
        foreach ($results as $row) {
            if ((int) $row['ingredient_id'] === $id) {
                return $row['ingredient_description'];
            }
        }

        return 'Ingredient 404';
    }

    private function fetchIngredientSet(string $store, CarbonImmutable $day, array $ids): array
    {
        $queries = DatabaseRouter::routedQueries(
            'alta_inventory_ingredient_usage',
            $day->toMutable(),
            $day->toMutable()
        );

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        return DB::query()
            ->fromSub($union, 'u')
            ->where('franchise_store', $store)
            ->whereIn('ingredient_id', $ids)
            ->groupBy('ingredient_id', 'ingredient_description')
            ->get([
                'ingredient_id',
                'ingredient_description',
                DB::raw('SUM(actual_usage) as total_actual_usage'),
                DB::raw('SUM(variance_qty * ingredient_unit_cost) as total_variance_value'),
            ])
            ->map(static function ($row) {
                return [
                    'ingredient_id' => $row->ingredient_id,
                    'ingredient_description' => $row->ingredient_description,
                    'actual_usage' => round((float) $row->total_actual_usage, 2),
                    'variance_value' => round((float) $row->total_variance_value, 2),
                ];
            })
            ->toArray();
    }
    // ---------------------------------------------------------------------
    // Hourly
    // ---------------------------------------------------------------------

    private function hourlySalesByChannel(string $store, CarbonImmutable $day): array
    {
        return HourlyStoreSummary::where('franchise_store', $store)
            ->where('business_date', $day->toDateString())
            ->orderBy('hour')
            ->get([
                'hour',
                'royalty_obligation',
                'phone_sales',
                'call_center_sales',
                'drive_thru_sales',
                'website_sales',
                'mobile_sales',
                'doordash_sales',
                'ubereats_sales',
                'grubhub_sales',
            ])
            ->toArray();
    }

    private function hourlySalesByChannelAverage(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        return HourlyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'hour,'
                . ' AVG(royalty_obligation) as royalty_obligation,'
                . ' AVG(phone_sales) as phone_sales,'
                . ' AVG(call_center_sales) as call_center_sales,'
                . ' AVG(drive_thru_sales) as drive_thru_sales,'
                . ' AVG(website_sales) as website_sales,'
                . ' AVG(mobile_sales) as mobile_sales,'
                . ' AVG(doordash_sales) as doordash_sales,'
                . ' AVG(ubereats_sales) as ubereats_sales,'
                . ' AVG(grubhub_sales) as grubhub_sales'
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(static function ($row) {
                $royalty = (float) $row->royalty_obligation;
                $phone = (float) $row->phone_sales;
                $callCenter = (float) $row->call_center_sales;
                $driveThru = (float) $row->drive_thru_sales;
                $website = (float) $row->website_sales;
                $mobile = (float) $row->mobile_sales;
                $doordash = (float) $row->doordash_sales;
                $ubereats = (float) $row->ubereats_sales;
                $grubhub = (float) $row->grubhub_sales;

                $adjusted = $royalty - (
                    $phone + $callCenter + $driveThru + $website + $mobile + $doordash + $ubereats + $grubhub
                );

                return [
                    'hour' => (int) $row->hour,
                    'royalty_obligation' => round($royalty, 2),
                    'phone_sales' => round($phone, 2),
                    'call_center_sales' => round($callCenter, 2),
                    'drive_thru_sales' => round($driveThru, 2),
                    'website_sales' => round($website, 2),
                    'mobile_sales' => round($mobile, 2),
                    'doordash_sales' => round($doordash, 2),
                    'ubereats_sales' => round($ubereats, 2),
                    'grubhub_sales' => round($grubhub, 2),
                    'adjusted_royalty_obligation' => round($adjusted, 2),
                ];
            })
            ->toArray();
    }

    private function hourlySalesByChannelSum(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        return HourlyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'hour,'
                . ' SUM(royalty_obligation) as royalty_obligation,'
                . ' SUM(phone_sales) as phone_sales,'
                . ' SUM(call_center_sales) as call_center_sales,'
                . ' SUM(drive_thru_sales) as drive_thru_sales,'
                . ' SUM(website_sales) as website_sales,'
                . ' SUM(mobile_sales) as mobile_sales,'
                . ' SUM(doordash_sales) as doordash_sales,'
                . ' SUM(ubereats_sales) as ubereats_sales,'
                . ' SUM(grubhub_sales) as grubhub_sales'
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(static function ($row) {
                $royalty = (float) $row->royalty_obligation;
                $phone = (float) $row->phone_sales;
                $callCenter = (float) $row->call_center_sales;
                $driveThru = (float) $row->drive_thru_sales;
                $website = (float) $row->website_sales;
                $mobile = (float) $row->mobile_sales;
                $doordash = (float) $row->doordash_sales;
                $ubereats = (float) $row->ubereats_sales;
                $grubhub = (float) $row->grubhub_sales;

                $adjusted = $royalty - (
                    $phone + $callCenter + $driveThru + $website + $mobile + $doordash + $ubereats + $grubhub
                );

                return [
                    'hour' => (int) $row->hour,
                    'royalty_obligation' => round($royalty, 2),
                    'phone_sales' => round($phone, 2),
                    'call_center_sales' => round($callCenter, 2),
                    'drive_thru_sales' => round($driveThru, 2),
                    'website_sales' => round($website, 2),
                    'mobile_sales' => round($mobile, 2),
                    'doordash_sales' => round($doordash, 2),
                    'ubereats_sales' => round($ubereats, 2),
                    'grubhub_sales' => round($grubhub, 2),
                    'adjusted_royalty_obligation' => round($adjusted, 2),
                ];
            })
            ->toArray();
    }

    private function averageValue(float $value, int $days): float
    {
        if ($days <= 0) {
            return 0.0;
        }

        return round($value / $days, 2);
    }

    private function averageSalesByChannelTotals(array $totals, int $days): array
    {
        return [
            'royalty_obligation' => $this->averageValue((float) ($totals['royalty_obligation'] ?? 0), $days),
            'phone_sales' => $this->averageValue((float) ($totals['phone_sales'] ?? 0), $days),
            'call_center_sales' => $this->averageValue((float) ($totals['call_center_sales'] ?? 0), $days),
            'drive_thru_sales' => $this->averageValue((float) ($totals['drive_thru_sales'] ?? 0), $days),
            'website_sales' => $this->averageValue((float) ($totals['website_sales'] ?? 0), $days),
            'mobile_sales' => $this->averageValue((float) ($totals['mobile_sales'] ?? 0), $days),
            'doordash_sales' => $this->averageValue((float) ($totals['doordash_sales'] ?? 0), $days),
            'ubereats_sales' => $this->averageValue((float) ($totals['ubereats_sales'] ?? 0), $days),
            'grubhub_sales' => $this->averageValue((float) ($totals['grubhub_sales'] ?? 0), $days),
        ];
    }

    private function enteredKeyValueSumForRange(
        string $store,
        int $keyId,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        return (float) EnteredKeyValue::query()
            ->where('store_id', $store)
            ->where('key_id', $keyId)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->sum('value_number');
    }

    private function percentOfSales(float $value, float $sales): float
    {
        if ($sales <= 0) {
            return 0.0;
        }

        return round(($value / $sales) * 100, 2);
    }

    private function averageWeekToDateTotals(array $totals, int $days): array
    {
        return [
            'cash_sales' => $this->averageValue((float) ($totals['cash_sales'] ?? 0), $days),
            'over_short' => $this->averageValue((float) ($totals['over_short'] ?? 0), $days),
            'refunded_orders' => $this->averageValue((float) ($totals['refunded_orders'] ?? 0), $days),
            'refund_amount' => $this->averageValue((float) ($totals['refund_amount'] ?? 0), $days),
            'customer_count' => $this->averageValue((float) ($totals['customer_count'] ?? 0), $days),
            'hnr_transactions' => $this->averageValue((float) ($totals['hnr_transactions'] ?? 0), $days),
            'hnr_broken_promises' => $this->averageValue((float) ($totals['hnr_broken_promises'] ?? 0), $days),
        ];
    }

    private function hnrTotalsAverage(array $totals, int $days): array
    {
        $transactions = $this->averageValue((float) ($totals['hnr_transactions'] ?? 0), $days);
        $broken = $this->averageValue((float) ($totals['hnr_broken_promises'] ?? 0), $days);
        $promiseMet = $transactions - $broken;

        return [
            'hnr_transactions' => $transactions,
            'hnr_broken_promises' => $broken,
            'hnr_promise_met' => round($promiseMet, 2),
            'hnr_promise_met_percent' => $transactions > 0
                ? round(($promiseMet / $transactions) * 100, 2)
                : 0.0,
        ];
    }

    private function portalMetricsAverage(array $metrics, int $days): array
    {
        $eligible = $this->averageValue((float) ($metrics['portal_eligible_orders'] ?? 0), $days);
        $used = $this->averageValue((float) ($metrics['portal_used_orders'] ?? 0), $days);
        $onTime = $this->averageValue((float) ($metrics['portal_on_time_orders'] ?? 0), $days);

        return [
            'portal_eligible_orders' => $eligible,
            'portal_used_orders' => $used,
            'portal_on_time_orders' => $onTime,
            'put_into_portal_percent' => $eligible > 0 ? round(($used / $eligible) * 100, 2) : 0,
            'in_portal_on_time_percent' => $used > 0 ? round(($onTime / $used) * 100, 2) : 0,
        ];
    }

    private function totalSalesByChannelForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $key = "totalSalesByChannelForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => $this->computeTotalSalesByChannelForRange($store, $start, $end));
    }

    private function computeTotalSalesByChannelForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $totals = HourlyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'COALESCE(SUM(royalty_obligation), 0) as royalty_obligation,'
                . ' COALESCE(SUM(phone_sales), 0) as phone_sales,'
                . ' COALESCE(SUM(call_center_sales), 0) as call_center_sales,'
                . ' COALESCE(SUM(drive_thru_sales), 0) as drive_thru_sales,'
                . ' COALESCE(SUM(website_sales), 0) as website_sales,'
                . ' COALESCE(SUM(mobile_sales), 0) as mobile_sales,'
                . ' COALESCE(SUM(doordash_sales), 0) as doordash_sales,'
                . ' COALESCE(SUM(ubereats_sales), 0) as ubereats_sales,'
                . ' COALESCE(SUM(grubhub_sales), 0) as grubhub_sales'
            )
            ->first();

        $royalty = (float) ($totals->royalty_obligation ?? 0);
        $phone = (float) ($totals->phone_sales ?? 0);
        $callCenter = (float) ($totals->call_center_sales ?? 0);
        $driveThru = (float) ($totals->drive_thru_sales ?? 0);
        $website = (float) ($totals->website_sales ?? 0);
        $mobile = (float) ($totals->mobile_sales ?? 0);
        $doordash = (float) ($totals->doordash_sales ?? 0);
        $ubereats = (float) ($totals->ubereats_sales ?? 0);
        $grubhub = (float) ($totals->grubhub_sales ?? 0);

        $adjusted = $royalty - (
            $phone + $callCenter + $driveThru + $website + $mobile + $doordash + $ubereats + $grubhub
        );

        return [
            'royalty_obligation' => round($adjusted, 2),
            'phone_sales' => round($phone, 2),
            'call_center_sales' => round($callCenter, 2),
            'drive_thru_sales' => round($driveThru, 2),
            'website_sales' => round($website, 2),
            'mobile_sales' => round($mobile, 2),
            'doordash_sales' => round($doordash, 2),
            'ubereats_sales' => round($ubereats, 2),
            'grubhub_sales' => round($grubhub, 2),
        ];
    }

    private function channelSalesForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $totals = $this->totalSalesByChannelForRange($store, $start, $end);
        $split = $this->websiteAndMobileSplitForRange($store, $start, $end);

        return [
            'royalty_obligation' => $totals['royalty_obligation'],
            'phone_sales' => $totals['phone_sales'],
            'call_center_sales' => $totals['call_center_sales'],
            'drive_thru_sales' => $totals['drive_thru_sales'],
            'website_sales' => $split['website_sales'],
            'mobile_sales' => $split['mobile_sales'],
            'doordash_sales' => $totals['doordash_sales'],
            'ubereats_sales' => $totals['ubereats_sales'],
            'grubhub_sales' => $totals['grubhub_sales'],
        ];
    }

    private function websiteAndMobileSplitForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $key = "websiteAndMobileSplitForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => $this->computeWebsiteAndMobileSplitForRange($store, $start, $end));
    }

    private function computeWebsiteAndMobileSplitForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $queries = DatabaseRouter::routedQueries('detail_orders', $start->toMutable(), $end->toMutable());

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        $row = DB::query()
            ->fromSub($union, 'd')
            ->where('franchise_store', $store)
            ->whereIn('order_placed_method', ['Website', 'Mobile'])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN order_placed_method = 'Website' AND order_fulfilled_method IN ('Register','Drive-Thru','In Store Only') THEN royalty_obligation ELSE 0 END), 0) as website_in_store,
                COALESCE(SUM(CASE WHEN order_placed_method = 'Website' AND order_fulfilled_method = 'Delivery' THEN royalty_obligation ELSE 0 END), 0) as website_delivery,
                COALESCE(SUM(CASE WHEN order_placed_method = 'Mobile'  AND order_fulfilled_method IN ('Register','Drive-Thru','In Store Only') THEN royalty_obligation ELSE 0 END), 0) as mobile_in_store,
                COALESCE(SUM(CASE WHEN order_placed_method = 'Mobile'  AND order_fulfilled_method = 'Delivery' THEN royalty_obligation ELSE 0 END), 0) as mobile_delivery
            ")
            ->first();

        $webIn = round((float) ($row->website_in_store ?? 0), 2);
        $webDel = round((float) ($row->website_delivery ?? 0), 2);
        $mobIn = round((float) ($row->mobile_in_store ?? 0), 2);
        $mobDel = round((float) ($row->mobile_delivery ?? 0), 2);

        return [
            'website_sales' => [
                'in_store' => $webIn,
                'delivery' => $webDel,
                'total' => round($webIn + $webDel, 2),
            ],
            'mobile_sales' => [
                'in_store' => $mobIn,
                'delivery' => $mobDel,
                'total' => round($mobIn + $mobDel, 2),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Deposit
    // ---------------------------------------------------------------------

    private function totalDepositForDay(string $store, CarbonImmutable $day): float
    {
        return $this->totalDepositForRange($store, $day, $day);
    }

    private function totalDepositForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $key = "totalDepositForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): float => $this->computeTotalDepositForRange($store, $start, $end));
    }

    private function computeTotalDepositForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $queries = DatabaseRouter::routedQueries(
            'financial_views',
            $start->toMutable(),
            $end->toMutable()
        );

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        return (float) DB::query()
            ->fromSub($union, 'f')
            ->where('franchise_store', $store)
            ->where('sub_account', 'Cash-Check-Deposit')
            ->sum('amount');
    }

    // ---------------------------------------------------------------------
    // Waste
    // ---------------------------------------------------------------------

    private function altaInventoryWasteForDay(string $store, CarbonImmutable $day): float
    {
        return $this->altaInventoryWasteForRange($store, $day, $day);
    }

    private function normalWasteForDay(string $store, CarbonImmutable $day): float
    {
        return $this->normalWasteForRange($store, $day, $day);
    }

    private function altaInventoryWasteForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $key = "altaInventoryWasteForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): float => $this->computeAltaInventoryWasteForRange($store, $start, $end));
    }

    private function computeAltaInventoryWasteForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $queries = DatabaseRouter::routedQueries(
            'alta_inventory_waste',
            $start->toMutable(),
            $end->toMutable()
        );

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        return (float) DB::query()
            ->fromSub($union, 'w')
            ->where('franchise_store', $store)
            ->selectRaw('SUM(unit_food_cost * qty) as total_waste_cost')
            ->value('total_waste_cost') ?? 0.0;
    }

    private function normalWasteForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $key = "normalWasteForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): float => $this->computeNormalWasteForRange($store, $start, $end));
    }

    private function computeNormalWasteForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $queries = DatabaseRouter::routedQueries(
            'waste',
            $start->toMutable(),
            $end->toMutable()
        );

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        return (float) DB::query()
            ->fromSub($union, 'w')
            ->where('franchise_store', $store)
            ->selectRaw('SUM(item_cost * quantity) as total_waste_cost')
            ->value('total_waste_cost') ?? 0.0;
    }

    // ---------------------------------------------------------------------
    // Upselling (in-store bucket only)
    // ---------------------------------------------------------------------

    private function upsellingForRange(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return array_merge(
            $this->soldWithPizzaUnitsForRange($store, $start, $end),
            $this->upsellingItemsForRange($store, $start, $end)
        );
    }

    private function totalUpsellingUnits(array $upselling): int
    {
        $excludedKeys = ['pizza_base', 'crazy_puffs', 'beverages'];
        $total = 0;

        foreach ($upselling as $key => $units) {
            if (in_array((string) $key, $excludedKeys, true)) {
                continue;
            }

            $total += (int) $units;
        }

        return $total;
    }

    private function soldWithPizzaUnitsForRange(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $base = $this->applyInStoreBucketFilters(
            $this->orderLineSource($start, $end)->where('franchise_store', $store)
        );

        $pizzaOrders = (clone $base)
            ->whereNotNull('order_id')
            ->where('is_pizza', 1)
            ->select('order_id')
            ->distinct();

        $pizzaBase = (int) (clone $base)
            ->where('is_pizza', 1)
            ->sum('quantity');

        $totals = (clone $base)
            ->whereNotNull('order_id')
            ->whereIn('order_id', $pizzaOrders)
            ->selectRaw("\n                SUM(CASE WHEN item_id = '103001' THEN COALESCE(quantity, 0) ELSE 0 END) as crazy_bread,\n                SUM(CASE WHEN item_id IN ('101288', '101289') THEN COALESCE(quantity, 0) ELSE 0 END) as cookies,\n                SUM(CASE WHEN item_id = '103002' THEN COALESCE(quantity, 0) ELSE 0 END) as sauce,\n                SUM(CASE WHEN is_wings = 1 THEN COALESCE(quantity, 0) ELSE 0 END) as wings,\n                SUM(CASE WHEN is_beverages = 1 THEN COALESCE(quantity, 0) ELSE 0 END) as beverages,\n                SUM(CASE WHEN is_crazy_puffs = 1 THEN COALESCE(quantity, 0) ELSE 0 END) as crazy_puffs,\n                SUM(CASE WHEN item_id = '204100' THEN COALESCE(quantity, 0) ELSE 0 END) as bev_20oz,\n                SUM(CASE WHEN item_id = '204200' THEN COALESCE(quantity, 0) ELSE 0 END) as bev_2l,\n                SUM(CASE WHEN item_id IN ('203003', '103003') THEN COALESCE(quantity, 0) ELSE 0 END) as italian_cheese_bread\n            ")
            ->first();

        return [
            'crazy_bread' => (int) ($totals->crazy_bread ?? 0),
            'cookies' => (int) ($totals->cookies ?? 0),
            'sauce' => (int) ($totals->sauce ?? 0),
            'wings' => (int) ($totals->wings ?? 0),
            'beverages' => (int) ($totals->beverages ?? 0),
            'crazy_puffs' => (int) ($totals->crazy_puffs ?? 0),
            'bev_20oz' => (int) ($totals->bev_20oz ?? 0),
            'bev_2l' => (int) ($totals->bev_2l ?? 0),
            'italian_cheese_bread' => (int) ($totals->italian_cheese_bread ?? 0),
            'pizza_base' => $pizzaBase,
        ];
    }

    private function upsellingItemsForRange(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->applyInStoreBucketFilters(
            $this->orderLineSource($start, $end)
                ->where('franchise_store', $store)
                ->whereIn('item_id', self::UPSELL_ITEM_IDS)
        )
            ->selectRaw('item_id, MAX(menu_item_name) as menu_item_name, SUM(COALESCE(quantity, 0)) as units_sold')
            ->groupBy('item_id')
            ->get();

        $items = [];
        foreach (self::UPSELL_ITEM_IDS as $id) {
            $items[$id] = 0;
        }

        foreach ($rows as $row) {
            $id = (string) $row->item_id;
            if (!array_key_exists($id, $items)) {
                continue;
            }

            $items[$id] = (int) ($row->units_sold ?? 0);
        }

        $itemsByName = [];
        foreach ($items as $id => $unitsSold) {
            $name = self::UPSELL_ITEM_NAMES[$id] ?? $id;
            $itemsByName[$name] = (int) $unitsSold;
        }

        return $itemsByName;
    }

    private function orderLineSource(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $queries = DatabaseRouter::routedQueries('order_line', $start->toMutable(), $end->toMutable());

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        // Union hot + archive order_line tables for the requested range.
        return DB::query()->fromSub($union, 'ol');
    }

    private function applyInStoreBucketFilters(Builder $query): Builder
    {
        return $query
            ->whereIn('order_placed_method', self::IN_STORE_BUCKET['placed'])
            ->whereIn('order_fulfilled_method', self::IN_STORE_BUCKET['fulfilled']);
    }


    // ---------------------------------------------------------------------
    // Portal
    // ---------------------------------------------------------------------

    private function portalMetrics(string $store, CarbonImmutable $day): array
    {
        return $this->portalMetricsForRange($store, $day, $day);
    }

    private function portalMetricsForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $key = "portalMetricsForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => $this->computePortalMetricsForRange($store, $start, $end));
    }

    private function computePortalMetricsForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $eligible = $this->summaryQuery->getPortalEligibleOrders(
            $store,
            $start->toMutable(),
            $end->toMutable()
        );
        $used = $this->summaryQuery->getPortalUsedOrders(
            $store,
            $start->toMutable(),
            $end->toMutable()
        );
        $onTime = $this->summaryQuery->getPortalOnTimeOrders(
            $store,
            $start->toMutable(),
            $end->toMutable()
        );

        return [
            'portal_eligible_orders' => $eligible,
            'portal_used_orders' => $used,
            'portal_on_time_orders' => $onTime,
            'put_into_portal_percent' => $eligible > 0 ? round(($used / $eligible) * 100, 2) : 0,
            'in_portal_on_time_percent' => $used > 0 ? round(($onTime / $used) * 100, 2) : 0,
        ];
    }

    private function hnrMetricsForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $totals = $this->dailySummaryTotals($store, $start, $end);
        return $this->hnrTotals($totals);
    }

    // ---------------------------------------------------------------------
    // Promo Report
    // ---------------------------------------------------------------------

    private function buildPromoReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);
        $prevWeekStart = $weekStart->subWeeks(1);
        $prevWeekEnd = $weekEnd->subWeeks(1);

        $currentTotalSales = round($this->salesTotal($store, $weekStart, $day), 2);
        $prevTotalSales = round($this->salesTotal($store, $prevWeekStart, $prevWeekEnd), 2);

        $currentBreakdown = $this->promoBreakdownForRange($store, $weekStart, $day, $currentTotalSales);
        $prevBreakdown = $this->promoBreakdownForRange($store, $prevWeekStart, $prevWeekEnd, $prevTotalSales);

        $currentTotals = $this->sumPromoBreakdown($currentBreakdown, $currentTotalSales);
        $prevTotals = $this->sumPromoBreakdown($prevBreakdown, $prevTotalSales);

        $currentPromo = $currentTotals['total_promo_sales'];
        $prevPromo = $prevTotals['total_promo_sales'];
        $currentPct = $currentTotals['pct_of_store_sales'];
        $prevPct = $prevTotals['pct_of_store_sales'];

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $day->toDateString(),
            ],
            'current_week' => [
                'total_store_sales' => $currentTotalSales,
                'promo_breakdown' => $currentBreakdown,
                'promo_totals' => $currentTotals,
            ],
            'previous_week' => [
                'week_start' => $prevWeekStart->toDateString(),
                'week_end' => $prevWeekEnd->toDateString(),
                'total_store_sales' => $prevTotalSales,
                'promo_breakdown' => $prevBreakdown,
                'promo_totals' => $prevTotals,
            ],
            'week_over_week' => [
                'promo_sales_change_pct' => $prevPromo > 0
                    ? round(($currentPromo - $prevPromo) / $prevPromo * 100, 2) : 0.0,
                'current_week_promo_to_sales_pct' => $currentPct,
                'previous_week_promo_to_sales_pct' => $prevPct,
                'promo_to_sales_pct_change' => round($currentPct - $prevPct, 2),
            ],
        ];
    }

    private function promoBreakdownForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        float $storeTotalSales
    ): array {
        $queries = DatabaseRouter::routedQueries('detail_orders', $start->toMutable(), $end->toMutable());
        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        $rows = DB::query()
            ->fromSub($union, 'd')
            ->where('franchise_store', $store)
            ->whereNotNull('modification_reason')
            ->where('modification_reason', '<>', '')
            ->selectRaw("
                modification_reason,
                COALESCE(SUM(royalty_obligation), 0) as promo_sales,
                COALESCE(SUM(CASE WHEN order_placed_method = 'Doordash'
                    THEN royalty_obligation ELSE 0 END), 0) as doordash_sales,
                COALESCE(SUM(CASE WHEN order_placed_method IN ('UberEats','Uber Eats')
                    THEN royalty_obligation ELSE 0 END), 0) as ubereats_sales,
                COALESCE(SUM(CASE WHEN order_placed_method IN ('Grubhub','GrubHub')
                    THEN royalty_obligation ELSE 0 END), 0) as grubhub_sales
            ")
            ->groupBy('modification_reason')
            ->orderByDesc('promo_sales')
            ->get();

        return $rows->map(function ($row) use ($storeTotalSales) {
            $promoSales = round((float) $row->promo_sales, 2);
            $doordash = round((float) $row->doordash_sales, 2);
            $ubereats = round((float) $row->ubereats_sales, 2);
            $grubhub = round((float) $row->grubhub_sales, 2);

            return [
                'modification_reason' => $row->modification_reason,
                'promo_sales' => $promoSales,
                'doordash_sales' => $doordash,
                'ubereats_sales' => $ubereats,
                'grubhub_sales' => $grubhub,
                'lc_sales' => round($promoSales - $doordash - $ubereats - $grubhub, 2),
                'pct_of_store_sales' => $storeTotalSales > 0
                    ? round($promoSales / $storeTotalSales * 100, 2) : 0.0,
            ];
        })->toArray();
    }

    private function sumPromoBreakdown(array $breakdown, float $storeTotalSales): array
    {
        $totalPromo = 0.0;
        $totalDoordash = 0.0;
        $totalUbereats = 0.0;
        $totalGrubhub = 0.0;

        foreach ($breakdown as $row) {
            $totalPromo += $row['promo_sales'];
            $totalDoordash += $row['doordash_sales'];
            $totalUbereats += $row['ubereats_sales'];
            $totalGrubhub += $row['grubhub_sales'];
        }

        $totalPromo = round($totalPromo, 2);

        return [
            'total_promo_sales' => $totalPromo,
            'total_doordash' => round($totalDoordash, 2),
            'total_ubereats' => round($totalUbereats, 2),
            'total_grubhub' => round($totalGrubhub, 2),
            'total_lc' => round($totalPromo - $totalDoordash - $totalUbereats - $totalGrubhub, 2),
            'pct_of_store_sales' => $storeTotalSales > 0
                ? round($totalPromo / $storeTotalSales * 100, 2) : 0.0,
        ];
    }

    // ---------------------------------------------------------------------
    // LTO Report
    // ---------------------------------------------------------------------

    private function buildLtoReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);
        $prevWeekStart = $weekStart->subWeeks(1);
        $prevWeekEnd = $weekEnd->subWeeks(1);

        $mWeekStart = $weekStart->toMutable();
        $mDay = $day->toMutable();
        $mPrevWeekStart = $prevWeekStart->toMutable();
        $mPrevWeekEnd = $prevWeekEnd->toMutable();

        $storeTotalSales = round($this->salesTotal($store, $weekStart, $day), 2);
        $storeTotalQuantity = $this->storeTotalQuantityForRange($store, $weekStart, $day);

        $items = [];
        $totalLtoSales = 0.0;
        $totalLtoQty = 0;

        foreach (self::LTO_ITEM_IDS as $itemId) {
            $id = (string) $itemId;
            $sales = round((float) $this->summaryQuery->getItemSales($store, $mWeekStart, $mDay, $id), 2);
            $qty = (int) $this->summaryQuery->getItemQuantity($store, $mWeekStart, $mDay, $id);
            $prevQty = (int) $this->summaryQuery->getItemQuantity($store, $mPrevWeekStart, $mPrevWeekEnd, $id);

            $totalLtoSales += $sales;
            $totalLtoQty += $qty;

            $items[] = [
                'item_id' => $id,
                'current_week_sales' => $sales,
                'current_week_quantity' => $qty,
                'previous_week_quantity' => $prevQty,
                'pct_of_store_sales' => $storeTotalSales > 0
                    ? round($sales / $storeTotalSales * 100, 2) : 0.0,
                'pct_of_store_quantity' => $storeTotalQuantity > 0
                    ? round($qty / $storeTotalQuantity * 100, 2) : 0.0,
            ];
        }

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $day->toDateString(),
            ],
            'store_totals' => [
                'total_sales' => $storeTotalSales,
                'total_quantity' => $storeTotalQuantity,
            ],
            'lto_totals' => [
                'total_sales' => round($totalLtoSales, 2),
                'total_quantity' => $totalLtoQty,
                'pct_of_store_sales' => $storeTotalSales > 0
                    ? round($totalLtoSales / $storeTotalSales * 100, 2) : 0.0,
                'pct_of_store_quantity' => $storeTotalQuantity > 0
                    ? round($totalLtoQty / $storeTotalQuantity * 100, 2) : 0.0,
            ],
            'items' => $items,
        ];
    }

    private function storeTotalQuantityForRange(string $store, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $mStart = $start->toMutable();
        $mEnd = $end->toMutable();

        return $this->summaryQuery->getPizzaQuantity($store, $mStart, $mEnd)
            + $this->summaryQuery->getHnrQuantity($store, $mStart, $mEnd)
            + $this->summaryQuery->getBreadQuantity($store, $mStart, $mEnd)
            + $this->summaryQuery->getWingsQuantity($store, $mStart, $mEnd)
            + $this->summaryQuery->getBeveragesQuantity($store, $mStart, $mEnd)
            + $this->summaryQuery->getOtherFoodsQuantity($store, $mStart, $mEnd);
    }

    // ---------------------------------------------------------------------
    // Portal Weekly
    // ---------------------------------------------------------------------

    private function buildPortalWeeklyReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $weeks = [];

        // Current week: WTD (weekStart → $day)
        $weeks[] = [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $day->toDateString(),
            ...$this->portalMetricsForRange($store, $weekStart, $day),
            ...$this->hnrMetricsForRange($store, $weekStart, $day),
        ];

        // 7 previous complete weeks
        for ($i = 1; $i <= 7; $i++) {
            $start = $weekStart->subWeeks($i);
            $end = $weekEnd->subWeeks($i);
            $weeks[] = [
                'week_start' => $start->toDateString(),
                'week_end' => $end->toDateString(),
                ...$this->portalMetricsForRange($store, $start, $end),
                ...$this->hnrMetricsForRange($store, $start, $end),
            ];
        }

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'weeks' => $weeks,
        ];
    }

    // ---------------------------------------------------------------------
    // Cash Control
    // ---------------------------------------------------------------------

    /**
     * GET /api/reports/cash-control/{store}/{date}
     */
    public function cashControl(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildCashControlReport($store, $date));
    }

    private function buildCashControlReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        [$weekStart] = $this->isoBusinessWeek($day);

        $fiscalYear = $this->fiscalYearOf($day);
        $yearStart = $this->fiscalYearStart($fiscalYear);
        $weekIdx = (int) ($yearStart->diffInDays($weekStart) / 7);
        $periodIdx = (int) floor($weekIdx / 4);
        $periodStart = $yearStart->addWeeks($periodIdx * 4);
        $quarterStart = $periodStart->subWeeks(8);

        $weekData = $this->cashControlDataForRange($store, $weekStart, $day);
        $periodData = $this->cashControlDataForRange($store, $periodStart, $day);
        $quarterData = $this->cashControlDataForRange($store, $quarterStart, $day);
        $yearData = $this->cashControlDataForRange($store, $yearStart, $day);

        $financialKeys = ['cash_sales', 'deposit', 'deposit_minus_cash_sales', 'over_short'];

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'fiscal_year' => $fiscalYear,
                'week_start' => $weekStart->toDateString(),
                'period_number' => $periodIdx + 1,
                'period_start' => $periodStart->toDateString(),
                'quarter_start' => $quarterStart->toDateString(),
                'year_start' => $yearStart->toDateString(),
            ],
            'week' => $weekData,
            'period' => array_intersect_key($periodData, array_flip($financialKeys)),
            'quarter' => array_intersect_key($quarterData, array_flip($financialKeys)),
            'year' => array_intersect_key($yearData, array_flip($financialKeys)),
        ];
    }

    private function cashControlDataForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $key = "cashControlDataForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => $this->computeCashControlDataForRange($store, $start, $end));
    }

    private function computeCashControlDataForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $row = DailyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'COALESCE(SUM(cash_sales), 0) as cash_sales,'
                . ' COALESCE(SUM(over_short), 0) as over_short,'
                . ' COALESCE(SUM(modified_orders), 0) as modified_orders,'
                . ' COALESCE(SUM(refunded_orders), 0) as refunded_orders,'
                . ' COALESCE(SUM(cancelled_orders), 0) as voided_orders'
            )
            ->first();

        $cashSales = round((float) ($row->cash_sales ?? 0), 2);
        $deposit = round($this->totalDepositForRange($store, $start, $end), 2);

        return [
            'cash_sales' => $cashSales,
            'deposit' => $deposit,
            'deposit_minus_cash_sales' => round($deposit - $cashSales, 2),
            'over_short' => round((float) ($row->over_short ?? 0), 2),
            'modified_orders' => (int) ($row->modified_orders ?? 0),
            'refunded_orders' => (int) ($row->refunded_orders ?? 0),
            'voided_orders' => (int) ($row->voided_orders ?? 0),
        ];
    }

    // Customer Count and Sales
    // ---------------------------------------------------------------------

    private function buildCustomerCountAndSalesReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        // ── Week ─────────────────────────────────────────────────────────
        [$weekStart] = $this->isoBusinessWeek($day);
        $daysInWeek = (int) $weekStart->diffInDays($day);

        $prevWeekStart = $weekStart->subWeeks(1);
        $lastYearWeekStart = $weekStart->subWeeks(52);

        // ── Period ───────────────────────────────────────────────────────
        $fiscalYear = $this->fiscalYearOf($day);
        $yearStart = $this->fiscalYearStart($fiscalYear);
        $weekIdx = (int) ($yearStart->diffInDays($weekStart) / 7);
        $periodIdx = (int) floor($weekIdx / 4);
        $periodStart = $yearStart->addWeeks($periodIdx * 4);
        $daysInPeriod = (int) $periodStart->diffInDays($day);

        $prevPeriodStart = $periodStart->subWeeks(4);
        $lastYearPeriodStart = $periodStart->subWeeks(52);

        // ── Quarter (rolling 3-period window ending at current period) ───
        $quarterStart = $periodStart->subWeeks(8);
        $daysInQuarter = (int) $quarterStart->diffInDays($day);

        $prevQuarterStart = $quarterStart->subWeeks(12);
        $lastYearQuarterStart = $quarterStart->subWeeks(52);

        // ── Year ─────────────────────────────────────────────────────────
        $daysInYear = (int) $yearStart->diffInDays($day);
        $prevYearStart = $yearStart->subWeeks(52);

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'fiscal_year' => $fiscalYear,
                'week_number' => $periodIdx * 4 + $weekIdx % 4 + 1,
                'week_start' => $weekStart->toDateString(),
                'period_number' => $periodIdx + 1,
                'period_start' => $periodStart->toDateString(),
                'quarter_start' => $quarterStart->toDateString(),
                'year_start' => $yearStart->toDateString(),
            ],
            'week' => [
                'current' => $this->salesAndCustomerCount($store, $weekStart, $day),
                'previous' => $this->salesAndCustomerCount($store, $prevWeekStart, $prevWeekStart->addDays($daysInWeek)),
                'same_week_last_year' => $this->salesAndCustomerCount($store, $lastYearWeekStart, $lastYearWeekStart->addDays($daysInWeek)),
            ],
            'period' => [
                'current' => $this->salesAndCustomerCount($store, $periodStart, $day),
                'previous' => $this->salesAndCustomerCount($store, $prevPeriodStart, $prevPeriodStart->addDays($daysInPeriod)),
                'same_period_last_year' => $this->salesAndCustomerCount($store, $lastYearPeriodStart, $lastYearPeriodStart->addDays($daysInPeriod)),
            ],
            'quarter' => [
                'current' => $this->salesAndCustomerCount($store, $quarterStart, $day),
                'previous' => $this->salesAndCustomerCount($store, $prevQuarterStart, $prevQuarterStart->addDays($daysInQuarter)),
                'same_quarter_last_year' => $this->salesAndCustomerCount($store, $lastYearQuarterStart, $lastYearQuarterStart->addDays($daysInQuarter)),
            ],
            'year' => [
                'current' => $this->salesAndCustomerCount($store, $yearStart, $day),
                'previous' => $this->salesAndCustomerCount($store, $prevYearStart, $prevYearStart->addDays($daysInYear)),
            ],
        ];
    }

    private function fiscalYearStart(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 1, 1)->startOfWeek(CarbonInterface::TUESDAY);
    }

    private function fiscalYearOf(CarbonImmutable $date): int
    {
        $y = $date->year;

        if ($date->lt($this->fiscalYearStart($y))) {
            return $y - 1;
        }

        if ($date->gte($this->fiscalYearStart($y + 1))) {
            return $y + 1;
        }

        return $y;
    }

    private function buildPhoneAndAdjustedSalesReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        [$weekStart] = $this->isoBusinessWeek($day);
        $daysInWeek = (int) $weekStart->diffInDays($day);

        $prevWeekStart = $weekStart->subWeeks(1);
        $lastYearWeekStart = $weekStart->subWeeks(52);

        $fiscalYear = $this->fiscalYearOf($day);
        $yearStart = $this->fiscalYearStart($fiscalYear);
        $weekIdx = (int) ($yearStart->diffInDays($weekStart) / 7);
        $periodIdx = (int) floor($weekIdx / 4);
        $periodStart = $yearStart->addWeeks($periodIdx * 4);
        $daysInPeriod = (int) $periodStart->diffInDays($day);

        $prevPeriodStart = $periodStart->subWeeks(4);
        $lastYearPeriodStart = $periodStart->subWeeks(52);

        $quarterStart = $periodStart->subWeeks(8);
        $daysInQuarter = (int) $quarterStart->diffInDays($day);

        $prevQuarterStart = $quarterStart->subWeeks(12);
        $lastYearQuarterStart = $quarterStart->subWeeks(52);

        $daysInYear = (int) $yearStart->diffInDays($day);
        $prevYearStart = $yearStart->subWeeks(52);

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'fiscal_year' => $fiscalYear,
                'week_number' => $periodIdx * 4 + $weekIdx % 4 + 1,
                'week_start' => $weekStart->toDateString(),
                'period_number' => $periodIdx + 1,
                'period_start' => $periodStart->toDateString(),
                'quarter_start' => $quarterStart->toDateString(),
                'year_start' => $yearStart->toDateString(),
            ],
            'week' => [
                'current' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $weekStart, $day),
                'previous' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $prevWeekStart, $prevWeekStart->addDays($daysInWeek)),
                'same_week_last_year' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $lastYearWeekStart, $lastYearWeekStart->addDays($daysInWeek)),
            ],
            'period' => [
                'current' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $periodStart, $day),
                'previous' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $prevPeriodStart, $prevPeriodStart->addDays($daysInPeriod)),
                'same_period_last_year' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $lastYearPeriodStart, $lastYearPeriodStart->addDays($daysInPeriod)),
            ],
            'quarter' => [
                'current' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $quarterStart, $day),
                'previous' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $prevQuarterStart, $prevQuarterStart->addDays($daysInQuarter)),
                'same_quarter_last_year' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $lastYearQuarterStart, $lastYearQuarterStart->addDays($daysInQuarter)),
            ],
            'year' => [
                'current' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $yearStart, $day),
                'previous' => $this->phoneSalesAndAdjustedRoyaltyForRange($store, $prevYearStart, $prevYearStart->addDays($daysInYear)),
            ],
        ];
    }

    private function phoneSalesAndAdjustedRoyaltyForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $key = "phoneSalesAndAdjustedRoyaltyForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => $this->computePhoneSalesAndAdjustedRoyaltyForRange($store, $start, $end));
    }

    private function computePhoneSalesAndAdjustedRoyaltyForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $row = HourlyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'COALESCE(SUM(phone_sales), 0) as phone_sales,'
                . ' COALESCE(SUM(royalty_obligation) - SUM(phone_sales) - SUM(call_center_sales)'
                . ' - SUM(drive_thru_sales) - SUM(website_sales) - SUM(mobile_sales)'
                . ' - SUM(doordash_sales) - SUM(ubereats_sales) - SUM(grubhub_sales), 0)'
                . ' as adjusted_royalty_obligation'
            )
            ->first();

        return [
            'phone_sales' => round((float) ($row->phone_sales ?? 0), 2),
            'adjusted_royalty_obligation' => round((float) ($row->adjusted_royalty_obligation ?? 0), 2),
        ];
    }

    private function salesAndCustomerCount(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $key = "salesAndCustomerCount:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => $this->computeSalesAndCustomerCount($store, $start, $end));
    }

    private function computeSalesAndCustomerCount(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        $row = DailyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'COALESCE(SUM(royalty_obligation), 0) as total_sales,'
                . ' COALESCE(SUM(customer_count), 0) as customer_count'
            )
            ->first();

        return [
            'total_sales' => round((float) ($row->total_sales ?? 0), 2),
            'customer_count' => (int) ($row->customer_count ?? 0),
        ];
    }
}
