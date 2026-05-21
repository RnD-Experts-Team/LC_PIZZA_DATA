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
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Aggregation\DailyStoreSummary;

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

    public function __construct(
        private readonly SummaryQueryService $summaryQuery,
        private readonly IntelligentAggregationService $intelligentAgg,
    ) {
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
        $weekToDateTopItems = $this->topItemsForRange($store, $weekToDateStart, $weekToDateEnd, 5);
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

            'sales' => [
                'this_week_by_day' => $this->salesByDay($store, $weekStart, $weekEnd),
                'previous_week_by_day' => $this->salesByDay($store, $prevWeekStart, $prevWeekEnd),
                'same_week_last_year_by_day' => $this->salesByDay($store, $lastYearWeekStart, $lastYearWeekEnd),
                'this_week_total' => $this->salesTotal($store, $weekStart, $weekEnd),
                'previous_week_total' => $this->salesTotal($store, $prevWeekStart, $prevWeekEnd),
                'same_week_last_year_total' => $this->salesTotal($store, $lastYearWeekStart, $lastYearWeekEnd),
            ],

            'top' => [
                'top_5_items_sales_for_day' => $this->topItemsForDay($store, $day, 5),
                'top_5_items_sales_week_to_date' => $weekToDateTopItems,

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

                'labor' => 0,
                'labor_week_to_date' => 0,
                'labor_week_to_date_avg' => 0,

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
        $out = [];

        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $out[$d->toDateString()] = $this->summaryQuery->getSales(
                $store,
                $d->toMutable(),
                $d->toMutable()
            );
        }

        return $out;
    }

    private function salesTotal(string $store, CarbonImmutable $start, CarbonImmutable $end): float
    {
        return (float) $this->summaryQuery->getSales(
            $store,
            $start->toMutable(),
            $end->toMutable()
        );
    }

    private function dailySummaryTotals(string $store, CarbonImmutable $start, CarbonImmutable $end): array
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

    private function topItemsForDay(string $store, CarbonImmutable $day, int $limit): array
    {
        return $this->topItemsForRange($store, $day, $day, $limit);
    }

    private function topItemsForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        int $limit
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
            'order_by' => 'gross_sales DESC',
            'limit' => $limit,
        ]);

        return $result['data'] ?? [];
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
}
