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
use App\Models\CleaningReview;
use App\Models\CustomerService;
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
        '406152'
    ];

    private const LABOR_ENTERED_KEY_ID = 23;
    // Starting 2026-07-07, labor is entered a day late under key 28 ("Yesterday's
    // Labor Cost"): the entry dated D holds the labor cost for business date D-1.
    // So a business date on/after the cutoff pulls key 28 from entry_date = date+1.
    private const LABOR_YESTERDAY_KEY_ID = 28;
    private const LABOR_YESTERDAY_KEY_CUTOFF = '2026-07-07';
    private const IN_STORE_BUCKET = [
        'placed' => ['Register', 'Drive Thru', 'SoundHoundAgent', 'Phone', 'CallCenterAgent'],
        'fulfilled' => ['Register', 'Drive-Thru'],
    ];

    // -----------------------------------------------------------------
    // Store score configuration
    //
    // The store score is a 0–100 weekly (week-to-date) performance grade.
    // Category maxima sum to exactly 100. Goal metrics are created at
    // runtime (not seeded), so their ids live here for easy per-env change.
    // -----------------------------------------------------------------

    /** score >= threshold => label (kept descending). */
    private const STORE_SCORE_LABELS = [
        80 => 'Fantastic +',
        70 => 'Fantastic',
        60 => 'Good',
        50 => 'Fair',
        0 => 'Poor',
    ];

    /** goal_metric_id for each scored metric. */
    private const SCORE_GOAL_METRIC_IDS = [
        'sales' => 4,
        'normal_hours' => 9,
        'normal_hours_floor' => 17,
        'normal_hours_ceil' => 18,
        'overtime_hours' => 10,
        'portal' => 11,
        'hnr' => 12,
        'orders_vs_sales' => 15,
    ];

    /** Dates on/after this use the floor/ceil normal-hours formula. */
    private const NORMAL_HOURS_NEW_CUTOFF = '2026-06-30';

    /** entered_keys ids feeding the score. */
    private const SCORE_KEY_NORMAL_HOURS = 25;
    private const SCORE_KEY_OVERTIME_HOURS = 26;
    private const SCORE_KEY_ITEMS_OFF = 27;

    /** Maximum points per category (sum = 100). */
    private const SCORE_MAX = [
        'normal_hours' => 35,
        'overtime_hours' => 15,
        'portal' => 10,
        'hnr' => 10,
        'transfer_in' => 7.5,
        'items_turned_off' => 7.5,
        'orders_vs_sales' => 15,
    ];

    /** Whole-score deduction per non-negotiable entry. */
    private const NON_NEGOTIABLE_DOWNTIME_PENALTY = 25;
    private const NON_NEGOTIABLE_OTHER_PENALTY = 35;

    /** Sales dollars that shift the hours goals by one flex step. */
    private const SCORE_FLEX_DOLLARS_PER_STEP = 1000;
    private const SCORE_FLEX_NORMAL_HOURS_STEP = 6;
    private const SCORE_FLEX_OVERTIME_HOURS_STEP = 2;

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

    public function cleaningReviewReport(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildCleaningReviewReport($store, $date));
    }

    private function buildCleaningReviewReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $entries = CleaningReview::where('store_number', $store)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get(['review_place', 'score']);

        $total = $entries->count();
        $passes = $entries->where('score', 'Pass')->count();
        $overallScore = $total > 0 ? round($passes / $total * 100, 2) : 0;

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'overall_score' => $overallScore,
            'entries' => $entries->map(fn($e) => [
                'review_place' => $e->review_place,
                'score' => $e->score,
            ])->values(),
        ];
    }

    public function customerServiceReport(string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        return response()->json($this->buildCustomerServiceReport($store, $date));
    }

    private function buildCustomerServiceReport(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $entries = CustomerService::where('store_number', $store)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get(['date', 'lobby_in', 'lobby_out', 'drive_thru_in', 'drive_thru_out', 'guest_service']);

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'entries' => $entries->map(fn($e) => [
                'date' => $e->date->toDateString(),
                'guest_service' => (float) $e->guest_service,
                'lobby_points' => $this->timePoints($e->lobby_in, $e->lobby_out),
                'drive_thru_points' => $this->timePoints($e->drive_thru_in, $e->drive_thru_out),
            ])->values(),
        ];
    }

    private function timePoints(?string $start, ?string $end): ?float
    {
        if ($start === null || $end === null) {
            return null;
        }

        return round($this->timeToMinutes($end) - $this->timeToMinutes($start), 2);
    }

    private function timeToMinutes(string $time): float
    {
        [$hours, $minutes, $seconds] = array_pad(explode(':', $time), 3, 0);

        return ((int) $hours) * 60 + ((int) $minutes) + ((int) $seconds) / 60;
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

    // ---------------------------------------------------------------------
    // Sales history (all-time weekly series + fiscal period/quarter/year rollups)
    // ---------------------------------------------------------------------

    /** Numeric columns summed at every granularity (weeks, periods, quarters, years). */
    private const SALES_HISTORY_MEASURES = [
        'total_sales',
        'customer_count',
        'royalty_obligation',
        'phone_sales',
        'call_center_sales',
        'drive_thru_sales',
        'website_sales',
        'mobile_sales',
        'doordash_sales',
        'ubereats_sales',
        'grubhub_sales',
    ];

    /**
     * All-time sales/customer-count/channel series for a store, in a single query,
     * bucketed into Tuesday-Monday business weeks and rolled up (in memory) into
     * fiscal periods, quarters, and years so the frontend can switch views freely.
     */
    private function buildSalesHistory(string $store, string $date): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        // Bucket each business_date into its Tuesday-starting business week (matches
        // isoBusinessWeek()'s Tue-Mon def). DAYOFWEEK: Sun=1..Sat=7 (locale-independent),
        // so (DAYOFWEEK + 4) % 7 = days to subtract to reach the week's Tuesday.
        $weekStartExpr = 'DATE_SUB(business_date, INTERVAL ((DAYOFWEEK(business_date) + 4) % 7) DAY)';

        $rows = DailyStoreSummary::where('franchise_store', $store)
            ->selectRaw(
                "{$weekStartExpr} as week_start,"
                . ' COALESCE(SUM(royalty_obligation), 0) as total_sales,'
                . ' COALESCE(SUM(customer_count), 0) as customer_count,'
                . ' COALESCE(SUM(royalty_obligation) - SUM(phone_sales) - SUM(call_center_sales)'
                . ' - SUM(drive_thru_sales) - SUM(website_sales) - SUM(mobile_sales)'
                . ' - SUM(doordash_sales) - SUM(ubereats_sales) - SUM(grubhub_sales), 0) as royalty_obligation,'
                . ' COALESCE(SUM(phone_sales), 0) as phone_sales,'
                . ' COALESCE(SUM(call_center_sales), 0) as call_center_sales,'
                . ' COALESCE(SUM(drive_thru_sales), 0) as drive_thru_sales,'
                . ' COALESCE(SUM(website_sales), 0) as website_sales,'
                . ' COALESCE(SUM(mobile_sales), 0) as mobile_sales,'
                . ' COALESCE(SUM(doordash_sales), 0) as doordash_sales,'
                . ' COALESCE(SUM(ubereats_sales), 0) as ubereats_sales,'
                . ' COALESCE(SUM(grubhub_sales), 0) as grubhub_sales'
            )
            ->groupByRaw($weekStartExpr)
            ->orderByRaw($weekStartExpr)
            ->get();

        $weeks = [];
        $periods = [];
        $quarters = [];
        $years = [];

        foreach ($rows as $row) {
            $weekStart = CarbonImmutable::parse($row->week_start);

            // --- Fiscal calendar (mirrors buildCustomerCountAndSalesReport) ---
            $fiscalYear = $this->fiscalYearOf($weekStart);
            $yearStart = $this->fiscalYearStart($fiscalYear);
            $weekIdx = (int) ($yearStart->diffInDays($weekStart) / 7);
            $periodIdx = (int) floor($weekIdx / 4);
            $periodNumber = $periodIdx + 1;
            $weekNumber = $periodIdx * 4 + $weekIdx % 4 + 1;

            // 3-3-3-4 quarters (mirrors quarterBounds); any rare 53rd-week overflow folds into Q4.
            $quarterNumber = 4;
            $quarterStartPeriodIdx = 9;
            $acc = 0;
            foreach ([3, 3, 3, 4] as $qi => $n) {
                if ($periodIdx < $acc + $n) {
                    $quarterNumber = $qi + 1;
                    $quarterStartPeriodIdx = $acc;
                    break;
                }
                $acc += $n;
            }

            $periodStart = $yearStart->addWeeks($periodIdx * 4);
            $quarterStart = $yearStart->addWeeks($quarterStartPeriodIdx * 4);
            $quarterWeeks = $quarterNumber === 4 ? 16 : 12;

            // --- Week row (enriched with fiscal labels so the frontend can also regroup client-side) ---
            $weeks[] = [
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekStart->addDays(6)->toDateString(),
                'fiscal_year' => $fiscalYear,
                'week_number' => $weekNumber,
                'period_number' => $periodNumber,
                'quarter_number' => $quarterNumber,
            ] + $this->formatSalesMeasures($this->addSalesMeasures([], $row));

            // --- Roll up into period / quarter / year buckets (keyed by canonical fiscal start) ---
            $pKey = $periodStart->toDateString();
            $periods[$pKey] ??= [
                'fiscal_year' => $fiscalYear,
                'number' => $periodNumber,
                'start' => $periodStart,
                'end' => $periodStart->addWeeks(4)->subDay(),
            ];
            $periods[$pKey] = $this->addSalesMeasures($periods[$pKey], $row);

            $qKey = $quarterStart->toDateString();
            $quarters[$qKey] ??= [
                'fiscal_year' => $fiscalYear,
                'number' => $quarterNumber,
                'start' => $quarterStart,
                'end' => $quarterStart->addWeeks($quarterWeeks)->subDay(),
            ];
            $quarters[$qKey] = $this->addSalesMeasures($quarters[$qKey], $row);

            $yKey = (string) $fiscalYear;
            $years[$yKey] ??= [
                'fiscal_year' => $fiscalYear,
                'number' => null,
                'start' => $yearStart,
                'end' => $this->fiscalYearStart($fiscalYear + 1)->subDay(),
            ];
            $years[$yKey] = $this->addSalesMeasures($years[$yKey], $row);
        }

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
            ],
            'weeks' => $weeks,
            'periods' => $this->formatSalesBuckets($periods, 'period_number', 'period'),
            'quarters' => $this->formatSalesBuckets($quarters, 'quarter_number', 'quarter'),
            'years' => $this->formatSalesBuckets($years, null, 'year'),
        ];
    }

    /** Accumulate one weekly SQL row's measures into a bucket (creates keys on first hit). */
    private function addSalesMeasures(array $bucket, object $row): array
    {
        foreach (self::SALES_HISTORY_MEASURES as $m) {
            $bucket[$m] = ($bucket[$m] ?? 0) + (float) $row->$m;
        }
        $bucket['weeks_count'] = ($bucket['weeks_count'] ?? 0) + 1;

        return $bucket;
    }

    /** Round measures for output; customer_count as int, money to 2dp. */
    private function formatSalesMeasures(array $bucket): array
    {
        $out = [];
        foreach (self::SALES_HISTORY_MEASURES as $m) {
            $out[$m] = $m === 'customer_count'
                ? (int) round($bucket[$m] ?? 0)
                : round((float) ($bucket[$m] ?? 0), 2);
        }

        return $out;
    }

    /** Turn the keyed period/quarter/year buckets into an ordered, labeled list. */
    private function formatSalesBuckets(array $buckets, ?string $numberKey, string $label): array
    {
        $out = [];
        foreach ($buckets as $b) {                 // insertion order = chronological
            $meta = ['fiscal_year' => $b['fiscal_year']];
            if ($numberKey !== null) {
                $meta[$numberKey] = $b['number'];
            }
            $meta["{$label}_start"] = $b['start']->toDateString();
            $meta["{$label}_end"] = $b['end']->toDateString();
            $meta['weeks_count'] = $b['weeks_count'];
            $out[] = $meta + $this->formatSalesMeasures($b);
        }

        return $out;
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
            'cleaning-review' => $this->buildCleaningReviewReport($store, $date),
            'customer-service' => $this->buildCustomerServiceReport($store, $date),
            'transfer-in-out' => $this->buildTransferInOutReport($store, $date),
            'orders-vs-sales' => $this->buildOrdersVsSalesReport($store, $date),
            'sales-history' => $this->buildSalesHistory($store, $date),
        ]);
    }

    public function multiStoreDashboard(): JsonResponse
    {
        $input = request()->all();

        if (empty($input['start_date']) || empty($input['end_date']) || empty($input['stores'])) {
            throw ValidationException::withMessages([
                'start_date' => 'start_date is required',
                'end_date' => 'end_date is required',
                'stores' => 'stores is required',
            ]);
        }

        $startStr = $input['start_date'];
        $endStr = $input['end_date'];
        $storesInput = $input['stores'];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endStr)) {
            throw ValidationException::withMessages([
                'date_format' => 'start_date and end_date must be in Y-m-d format'
            ]);
        }

        $startDate = CarbonImmutable::parse($startStr)->startOfDay();
        $endDate = CarbonImmutable::parse($endStr)->endOfDay();

        if ($endDate->isBefore($startDate)) {
            throw ValidationException::withMessages([
                'end_date' => 'end_date must be after or equal to start_date'
            ]);
        }

        $dayCount = (int) $startDate->diffInDays($endDate) + 1;
        if ($dayCount > 366) {
            throw ValidationException::withMessages([
                'date_range' => 'Date range must not exceed 366 days.'
            ]);
        }

        if ($storesInput === 'all' || $storesInput === ['all']) {
            $storesInput = 'all';
        } elseif (is_array($storesInput)) {
            if (count($storesInput) > 100) {
                throw ValidationException::withMessages([
                    'stores' => 'Cannot request more than 100 stores'
                ]);
            }
        } else {
            throw ValidationException::withMessages([
                'stores' => 'stores must be either "all" or an array of store numbers'
            ]);
        }

        $cacheKey = 'multi_dashboard_' . md5(
            (is_array($storesInput) ? implode(',', $storesInput) : 'all') .
            $startDate->toDateString() .
            $endDate->toDateString()
        );
        $ttl = $endDate->isPast() ? 86400 : 300;

        $result = Cache::remember(
            $cacheKey,
            $ttl,
            fn() =>
            $this->buildMultiDashboard($storesInput, $startDate, $endDate)
        );

        return response()->json($result);
    }

    // ---------------------------------------------------------------------
    // Multi-store dashboard builder (cached)
    // ---------------------------------------------------------------------

    private function buildMultiDashboard(mixed $storesInput, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $stores = $this->resolveStores($storesInput, $start, $end);

        if (empty($stores)) {
            return [
                'meta' => [
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'total_days' => (int) $start->diffInDays($end) + 1,
                    'stores' => [],
                    'store_count' => 0,
                ],
                'totals' => [],
                'averages' => [],
                'by_store' => [],
                'by_date' => [],
            ];
        }

        $rows = DailyStoreSummary::whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('business_date')
            ->orderBy('franchise_store')
            ->get();

        $transfers = TransferInOut::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(function ($q) use ($stores) {
                $q->whereIn('from_store_number', $stores)
                    ->orWhereIn('to_store_number', $stores);
            })
            ->get();

        $nonNegotiables = NonNegotiableReport::whereIn('store_number', $stores)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        $goToCalls = GoToCall::whereIn('store_number', $stores)
            ->whereBetween('datetime', [$start, $end])
            ->with('participants')
            ->get();

        // One value per store per day: take the latest entry for that store/day
        // (whichever user filled it out last), not a sum across every filler.
        $laborEntries = EnteredKeyValue::whereIn('id', function ($q) use ($stores, $start, $end) {
            $q->from('entered_key_values')
                ->selectRaw('MAX(id)')
                ->whereIn('store_id', $stores)
                ->where('is_mistaken', false);
            $this->applyLaborKeySegments($q, $start, $end);
            $q->groupBy('store_id', 'entry_date');
        })->get();

        $grandTotals = $this->initializeMetricsBag();
        $byStore = [];
        $byDate = [];
        $dayCount = (int) $start->diffInDays($end) + 1;

        foreach ($rows as $row) {
            $store = $row->franchise_store;
            $date = $row->business_date->toDateString();

            if (!isset($byStore[$store])) {
                $byStore[$store] = [
                    'totals' => $this->initializeMetricsBag(),
                    'supplemental' => ['labor_total' => 0, 'transfers_in_cost' => 0, 'transfers_out_cost' => 0, 'non_negotiable_count' => 0, 'go_to_calls' => []],
                    'by_date' => [],
                ];
            }

            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'totals' => $this->initializeMetricsBag(),
                    'by_store' => [],
                ];
            }

            $rowArray = $row->toArray();
            $byStore[$store]['by_date'][$date] = $rowArray;
            $byDate[$date]['by_store'][$store] = $rowArray;

            $this->aggregateMetrics($grandTotals, $row);
            $this->aggregateMetrics($byStore[$store]['totals'], $row);
            $this->aggregateMetrics($byDate[$date]['totals'], $row);
        }

        foreach ($transfers as $transfer) {
            $toStore = $transfer->to_store_number;
            $fromStore = $transfer->from_store_number;
            $cost = (float) ($transfer->total_cost ?? 0);

            if (isset($byStore[$toStore])) {
                $byStore[$toStore]['supplemental']['transfers_in_cost'] += $cost;
            }
            if (isset($byStore[$fromStore])) {
                $byStore[$fromStore]['supplemental']['transfers_out_cost'] += $cost;
            }
            $grandTotals['transfers_in_cost'] += $cost;
        }

        foreach ($nonNegotiables as $nn) {
            $store = $nn->store_number;
            if (isset($byStore[$store])) {
                $byStore[$store]['supplemental']['non_negotiable_count']++;
            }
            $grandTotals['non_negotiable_count']++;
        }

        foreach ($goToCalls as $call) {
            $store = $call->store_number;
            if (isset($byStore[$store])) {
                $byStore[$store]['supplemental']['go_to_calls'][] = $call->toArray();
            }
            $grandTotals['go_to_calls_count']++;
        }

        foreach ($laborEntries as $entry) {
            $store = $entry->store_id;
            $amount = (float) ($entry->value_number ?? $entry->value_text ?? 0);
            if (isset($byStore[$store])) {
                $byStore[$store]['supplemental']['labor_total'] += $amount;
            }
            $grandTotals['labor_total'] += $amount;
        }

        $grandAverages = $this->computeAverages($grandTotals, count($stores), $dayCount);
        $storeAverages = [];
        foreach ($byStore as $store => $data) {
            $storeAverages[$store] = $this->computeAverages($data['totals'], 1, $dayCount);
        }

        foreach ($byStore as $store => &$data) {
            $data['averages'] = $storeAverages[$store];
            unset($data);
        }

        foreach ($byDate as &$dayData) {
            $dayData['totals'] = $this->deriveRatios($dayData['totals']);
            unset($dayData);
        }

        return [
            'meta' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_days' => $dayCount,
                'stores' => $stores,
                'store_count' => count($stores),
            ],
            'totals' => $this->deriveRatios($grandTotals),
            'averages' => $grandAverages,
            'by_store' => $byStore,
            'by_date' => $byDate,
        ];
    }

    private function resolveStores(mixed $input, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($input === 'all') {
            return DailyStoreSummary::whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
                ->distinct()
                ->pluck('franchise_store')
                ->all();
        }

        if (is_array($input)) {
            $stores = array_filter(array_map('trim', $input), 'strlen');
            if (count($stores) > 100) {
                $stores = array_slice($stores, 0, 100);
            }
            return array_values($stores);
        }

        return [];
    }

    private function initializeMetricsBag(): array
    {
        return [
            'gross_sales' => 0,
            'royalty_obligation' => 0,
            'net_sales' => 0,
            'refund_amount' => 0,
            'total_orders' => 0,
            'completed_orders' => 0,
            'cancelled_orders' => 0,
            'modified_orders' => 0,
            'refunded_orders' => 0,
            'customer_count' => 0,
            'phone_orders' => 0,
            'phone_sales' => 0,
            'website_orders' => 0,
            'website_sales' => 0,
            'mobile_orders' => 0,
            'mobile_sales' => 0,
            'call_center_orders' => 0,
            'call_center_sales' => 0,
            'drive_thru_orders' => 0,
            'drive_thru_sales' => 0,
            'doordash_orders' => 0,
            'doordash_sales' => 0,
            'ubereats_orders' => 0,
            'ubereats_sales' => 0,
            'grubhub_orders' => 0,
            'grubhub_sales' => 0,
            'delivery_orders' => 0,
            'delivery_sales' => 0,
            'carryout_orders' => 0,
            'carryout_sales' => 0,
            'pizza_delivery_quantity' => 0,
            'pizza_delivery_sales' => 0,
            'pizza_carryout_quantity' => 0,
            'pizza_carryout_sales' => 0,
            'hnr_delivery_quantity' => 0,
            'hnr_delivery_sales' => 0,
            'hnr_carryout_quantity' => 0,
            'hnr_carryout_sales' => 0,
            'bread_delivery_quantity' => 0,
            'bread_delivery_sales' => 0,
            'bread_carryout_quantity' => 0,
            'bread_carryout_sales' => 0,
            'wings_delivery_quantity' => 0,
            'wings_delivery_sales' => 0,
            'wings_carryout_quantity' => 0,
            'wings_carryout_sales' => 0,
            'beverages_delivery_quantity' => 0,
            'beverages_delivery_sales' => 0,
            'beverages_carryout_quantity' => 0,
            'beverages_carryout_sales' => 0,
            'other_foods_delivery_quantity' => 0,
            'other_foods_delivery_sales' => 0,
            'other_foods_carryout_quantity' => 0,
            'other_foods_carryout_sales' => 0,
            'side_items_delivery_quantity' => 0,
            'side_items_delivery_sales' => 0,
            'side_items_carryout_quantity' => 0,
            'side_items_carryout_sales' => 0,
            'sales_tax' => 0,
            'delivery_fees' => 0,
            'delivery_tips' => 0,
            'store_tips' => 0,
            'total_tips' => 0,
            'cash_sales' => 0,
            'over_short' => 0,
            'portal_eligible_orders' => 0,
            'portal_used_orders' => 0,
            'portal_on_time_orders' => 0,
            'digital_orders' => 0,
            'digital_sales' => 0,
            'hnr_transactions' => 0,
            'hnr_broken_promises' => 0,
            'labor_total' => 0,
            'transfers_in_cost' => 0,
            'transfers_out_cost' => 0,
            'non_negotiable_count' => 0,
            'go_to_calls_count' => 0,
        ];
    }

    private function aggregateMetrics(array &$accumulator, DailyStoreSummary $row): void
    {
        $numeric_fields = [
            'gross_sales',
            'royalty_obligation',
            'net_sales',
            'refund_amount',
            'total_orders',
            'completed_orders',
            'cancelled_orders',
            'modified_orders',
            'refunded_orders',
            'customer_count',
            'phone_orders',
            'phone_sales',
            'website_orders',
            'website_sales',
            'mobile_orders',
            'mobile_sales',
            'call_center_orders',
            'call_center_sales',
            'drive_thru_orders',
            'drive_thru_sales',
            'doordash_orders',
            'doordash_sales',
            'ubereats_orders',
            'ubereats_sales',
            'grubhub_orders',
            'grubhub_sales',
            'delivery_orders',
            'delivery_sales',
            'carryout_orders',
            'carryout_sales',
            'pizza_delivery_quantity',
            'pizza_delivery_sales',
            'pizza_carryout_quantity',
            'pizza_carryout_sales',
            'hnr_delivery_quantity',
            'hnr_delivery_sales',
            'hnr_carryout_quantity',
            'hnr_carryout_sales',
            'bread_delivery_quantity',
            'bread_delivery_sales',
            'bread_carryout_quantity',
            'bread_carryout_sales',
            'wings_delivery_quantity',
            'wings_delivery_sales',
            'wings_carryout_quantity',
            'wings_carryout_sales',
            'beverages_delivery_quantity',
            'beverages_delivery_sales',
            'beverages_carryout_quantity',
            'beverages_carryout_sales',
            'other_foods_delivery_quantity',
            'other_foods_delivery_sales',
            'other_foods_carryout_quantity',
            'other_foods_carryout_sales',
            'side_items_delivery_quantity',
            'side_items_delivery_sales',
            'side_items_carryout_quantity',
            'side_items_carryout_sales',
            'sales_tax',
            'delivery_fees',
            'delivery_tips',
            'store_tips',
            'total_tips',
            'cash_sales',
            'over_short',
            'portal_eligible_orders',
            'portal_used_orders',
            'portal_on_time_orders',
            'digital_orders',
            'digital_sales',
            'hnr_transactions',
            'hnr_broken_promises',
        ];

        foreach ($numeric_fields as $field) {
            $value = (float) ($row->{$field} ?? 0);
            $accumulator[$field] += $value;
        }
    }

    private function computeAverages(array $totals, int $storeCount, int $dayCount): array
    {
        $totalDays = max(1, $storeCount * $dayCount);

        return [
            'gross_sales_per_store_per_day' => $totals['gross_sales'] / $totalDays,
            'royalty_obligation_per_store_per_day' => $totals['royalty_obligation'] / $totalDays,
            'net_sales_per_store_per_day' => $totals['net_sales'] / $totalDays,
            'orders_per_store_per_day' => $totals['total_orders'] / $totalDays,
            'customer_count_per_store_per_day' => $totals['customer_count'] / $totalDays,
            'avg_order_value' => $totals['total_orders'] > 0 ? $totals['royalty_obligation'] / $totals['total_orders'] : 0,
            'portal_usage_rate' => $totals['portal_eligible_orders'] > 0 ? ($totals['portal_used_orders'] / $totals['portal_eligible_orders']) * 100 : 0,
            'portal_on_time_rate' => $totals['portal_used_orders'] > 0 ? ($totals['portal_on_time_orders'] / $totals['portal_used_orders']) * 100 : 0,
            'digital_penetration' => $totals['gross_sales'] > 0 ? ($totals['digital_sales'] / $totals['gross_sales']) * 100 : 0,
            'delivery_rate' => $totals['total_orders'] > 0 ? ($totals['delivery_orders'] / $totals['total_orders']) * 100 : 0,
        ];
    }

    private function deriveRatios(array $totals): array
    {
        $totals['avg_order_value'] = $totals['total_orders'] > 0 ? $totals['royalty_obligation'] / $totals['total_orders'] : 0;
        $totals['portal_usage_rate'] = $totals['portal_eligible_orders'] > 0 ? ($totals['portal_used_orders'] / $totals['portal_eligible_orders']) * 100 : 0;
        $totals['portal_on_time_rate'] = $totals['portal_used_orders'] > 0 ? ($totals['portal_on_time_orders'] / $totals['portal_used_orders']) * 100 : 0;
        $totals['digital_penetration'] = $totals['gross_sales'] > 0 ? ($totals['digital_sales'] / $totals['gross_sales']) * 100 : 0;
        $totals['delivery_rate'] = $totals['total_orders'] > 0 ? ($totals['delivery_orders'] / $totals['total_orders']) * 100 : 0;

        return $totals;
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

        $laborValueDay = $this->laborValueSumForRange(
            $store,
            $day,
            $day
        );
        $laborWeekToDateSum = $this->laborValueSumForRange(
            $store,
            $weekToDateStart,
            $weekToDateEnd
        );
        $laborWeekToDateAvgValue = $this->averageValue($laborWeekToDateSum, $weekToDateDayCount);

        $laborPercent = $this->percentOfSales($laborValueDay, $daySales);
        $laborWeekToDatePercent = $this->percentOfSales($laborWeekToDateSum, $weekToDateSalesTotal);
        $laborWeekToDateAvgPercent = $this->percentOfSales($laborWeekToDateAvgValue, $weekToDateSalesAvg);
        $laborWeekToDateByDay = $this->laborByDayForRange($store, $weekToDateStart, $weekToDateEnd, $thisWeekByDay);

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

        // Goals overlapping the report week (reused by goal_metrics + store score).
        $goalMetrics = $this->getGoalsForStoreDate($store, $date);

        $storeScore = $this->computeStoreScore(
            $store,
            $weekStart,
            $day,
            $weekToDateDayCount,
            $previousWeekByDay,
            $prevWeekStart,
            $previousWeekTotal,
            $weekToDateSalesTotal,
            $goalMetrics
        );

        return [
            'filtering' => [
                'store' => $store,
                'date' => $day->toDateString(),
                'iso_week' => $day->isoWeek(),
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
            ],
            'goal_metrics' => $goalMetrics,
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
                'labor_week_to_date_by_day' => $laborWeekToDateByDay,

                'portal' => array_merge($this->portalMetrics($store, $day), [
                    'week_to_date' => $weekToDatePortal,
                    'week_to_date_avg' => $weekToDatePortalAvg,
                ]),
            ],
            'store_score' => $storeScore,
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
    // Store score (0–100 weekly performance grade)
    // ---------------------------------------------------------------------

    /**
     * Week-to-date store score (0–100), its label, and a per-category
     * breakdown. Reuses data already gathered by buildReport():
     * previous-week sales-by-day (hours proration), week-to-date sales,
     * and the overlapping goal metrics — only small lookups are added.
     */
    private function computeStoreScore(
        string $store,
        CarbonImmutable $weekStart,
        CarbonImmutable $day,
        int $weekToDateDayCount,
        array $previousWeekByDay,
        CarbonImmutable $prevWeekStart,
        float $previousWeekTotal,
        float $weekToDateSalesTotal,
        array $goalMetrics
    ): array {
        // Per-day rows shared by the HnR and portal daily-mean calcs (one query).
        $dailyRows = $this->dailySummaryRowsForRange($store, $weekStart, $day);

        $hours = $this->scoreHours(
            $store,
            $weekStart,
            $day,
            $weekToDateDayCount,
            $previousWeekByDay,
            $prevWeekStart,
            $previousWeekTotal,
            $weekToDateSalesTotal,
            $goalMetrics
        );

        $details = [
            $hours['normal_hours'],
            $hours['overtime_hours'],
            $this->scorePortal(
                $dailyRows,
                $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['portal'])
            ),
            $this->scoreHnr(
                $dailyRows,
                $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['hnr'])
            ),
            $this->scoreTransferIn($store, $weekStart, $day),
            $this->scoreItemsTurnedOff($store, $weekStart, $day),
            $this->scoreOrdersVsSales(
                $store,
                $weekStart->subWeeks(3),
                $day,
                $this->salesTotal($store, $weekStart->subWeeks(3), $day),
                $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['orders_vs_sales'])
            ),
        ];

        $rawScore = 0.0;
        foreach ($details as $detail) {
            $rawScore += (float) $detail['score'];
        }

        $nonNegotiable = $this->scoreNonNegotiablePenalty($store, $weekStart, $day);

        $score = max(0.0, $rawScore - $nonNegotiable['penalty']);

        return [
            'score' => round($score, 2),
            'label' => $this->labelForScore($score),
            'details' => $details,
            'non_negotiable' => $nonNegotiable,
            'raw_score' => round($rawScore, 2),
        ];
    }

    /**
     * Normal-hours (max 35) and overtime-hours (max 15) categories.
     *
     * For dates on/after NORMAL_HOURS_NEW_CUTOFF: uses a floor goal (id 17)
     * and ceil goal (id 18). Both are prorated and flex-adjusted independently.
     * Scoring: full 35 if actual is within [floor, ceil]; deduct proportionally
     * otherwise (denominator is always the ceil/highest goal).
     *
     * For earlier dates: single normal-hours goal (id 9) with the original
     * multi-case spreadsheet formula.
     */
    private function scoreHours(
        string $store,
        CarbonImmutable $weekStart,
        CarbonImmutable $day,
        int $weekToDateDayCount,
        array $previousWeekByDay,
        CarbonImmutable $prevWeekStart,
        float $previousWeekTotal,
        float $weekToDateSalesTotal,
        array $goalMetrics
    ): array {
        $normalMax = self::SCORE_MAX['normal_hours'];
        $otMax = self::SCORE_MAX['overtime_hours'];

        $salesGoal = $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['sales']);
        $otGoal = $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['overtime_hours']);

        // Prorate fraction W: share of last week's sales falling on the elapsed days.
        if ($previousWeekTotal > 0) {
            $w = 0.0;
            for ($i = 0; $i < $weekToDateDayCount; $i++) {
                $dateKey = $prevWeekStart->addDays($i)->toDateString();
                $w += (float) ($previousWeekByDay[$dateKey] ?? 0.0) / $previousWeekTotal;
            }
        } else {
            $w = $weekToDateDayCount / 7;
        }

        $actNormal = $this->enteredKeyValueLatest($store, self::SCORE_KEY_NORMAL_HOURS, $weekStart, $day);
        $actOt = $this->enteredKeyValueLatest($store, self::SCORE_KEY_OVERTIME_HOURS, $weekStart, $day);

        if ($day->toDateString() >= self::NORMAL_HOURS_NEW_CUTOFF) {
            // --- New formula (floor/ceil) ---
            $floorGoal = $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['normal_hours_floor']);
            $ceilGoal = $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['normal_hours_ceil']);

            $haveGoals = $salesGoal !== null && $floorGoal !== null && $ceilGoal !== null && $otGoal !== null;

            if ($haveGoals) {
                $origFloor = $floorGoal * $w;
                $origCeil = $ceilGoal * $w;
                $proratedSalesGoal = $salesGoal * $w;
                $salesDiff = $weekToDateSalesTotal - $proratedSalesGoal;
                $steps = $salesDiff / self::SCORE_FLEX_DOLLARS_PER_STEP;
                $updFloor = $origFloor + $steps * self::SCORE_FLEX_NORMAL_HOURS_STEP;
                $updCeil = $origCeil + $steps * self::SCORE_FLEX_NORMAL_HOURS_STEP;
                $origOt = $otGoal * $w;
                $updOt = $origOt + $steps * self::SCORE_FLEX_OVERTIME_HOURS_STEP;

                [$normalScore, $normalCase] = $this->normalHoursScoreNew($updFloor, $updCeil, $actNormal);
                $otScore = $this->overtimeHoursScore($updOt, $actOt, $updCeil);
            } else {
                $origFloor = $origCeil = $origOt = $proratedSalesGoal = 0.0;
                $salesDiff = $steps = $updFloor = $updCeil = $updOt = 0.0;
                $normalScore = $otScore = 0.0;
                $normalCase = 0;
            }

            return [
                'normal_hours' => [
                    'key' => 'normal_hours',
                    'label' => 'Normal Hours',
                    'score' => round($normalScore, 2),
                    'max' => $normalMax,
                    'actual_hours' => round($actNormal, 2),
                    'weekly_goal_floor' => $floorGoal,
                    'weekly_goal_ceil' => $ceilGoal,
                    'prorate_fraction' => round($w, 4),
                    'floor_goal' => round($updFloor, 2),
                    'ceil_goal' => round($updCeil, 2),
                    'prorated_sales_goal' => round($proratedSalesGoal, 2),
                    'week_to_date_sales' => round($weekToDateSalesTotal, 2),
                    'sales_diff' => round($salesDiff, 2),
                    'days_elapsed' => $weekToDateDayCount,
                    'case' => $normalCase,
                ],
                'overtime_hours' => [
                    'key' => 'overtime_hours',
                    'label' => 'Overtime Hours',
                    'score' => round($otScore, 2),
                    'max' => $otMax,
                    'actual_overtime_hours' => round($actOt, 2),
                    'weekly_goal' => $otGoal,
                    'original_goal' => round($origOt, 2),
                    'updated_goal' => round($updOt, 2),
                    'normal_goal_denominator' => round($updCeil, 2),
                ],
            ];
        }

        // --- Old formula (single normal-hours goal id 9) ---
        $normalGoal = $this->goalValueFromMetrics($goalMetrics, self::SCORE_GOAL_METRIC_IDS['normal_hours']);

        $haveGoals = $salesGoal !== null && $normalGoal !== null && $otGoal !== null;

        if ($haveGoals) {
            $origNormal = $normalGoal * $w;
            $origOt = $otGoal * $w;
            $proratedSalesGoal = $salesGoal * $w;
            $salesDiff = $weekToDateSalesTotal - $proratedSalesGoal;
            $steps = $salesDiff / self::SCORE_FLEX_DOLLARS_PER_STEP;
            $updNormal = $origNormal + $steps * self::SCORE_FLEX_NORMAL_HOURS_STEP;
            $updOt = $origOt + $steps * self::SCORE_FLEX_OVERTIME_HOURS_STEP;

            [$normalScore, $normalCase] = $this->normalHoursScore($origNormal, $updNormal, $actNormal);
            $otScore = $this->overtimeHoursScore($updOt, $actOt, $updNormal);
        } else {
            $origNormal = $origOt = $proratedSalesGoal = 0.0;
            $salesDiff = $steps = $updNormal = $updOt = 0.0;
            $normalScore = $otScore = 0.0;
            $normalCase = 0;
        }

        return [
            'normal_hours' => [
                'key' => 'normal_hours',
                'label' => 'Normal Hours',
                'score' => round($normalScore, 2),
                'max' => $normalMax,
                'actual_hours' => round($actNormal, 2),
                'weekly_goal' => $normalGoal,
                'prorate_fraction' => round($w, 4),
                'original_goal' => round($origNormal, 2),
                'updated_goal' => round($updNormal, 2),
                'prorated_sales_goal' => round($proratedSalesGoal, 2),
                'week_to_date_sales' => round($weekToDateSalesTotal, 2),
                'sales_diff' => round($salesDiff, 2),
                'days_elapsed' => $weekToDateDayCount,
                'case' => $normalCase,
            ],
            'overtime_hours' => [
                'key' => 'overtime_hours',
                'label' => 'Overtime Hours',
                'score' => round($otScore, 2),
                'max' => $otMax,
                'actual_overtime_hours' => round($actOt, 2),
                'weekly_goal' => $otGoal,
                'original_goal' => round($origOt, 2),
                'updated_goal' => round($updOt, 2),
                'normal_goal_denominator' => round($updNormal, 2),
            ],
        ];
    }

    /**
     * Normal-hours score (out of 35) for given original (prorated) goal,
     * sales-adjusted goal, and actual hours. Pure translation of the source
     * spreadsheet formula (literal *2 / *3 multipliers). Returns [score, case].
     */
    private function normalHoursScore(float $origNormal, float $updNormal, float $actNormal): array
    {
        if ($updNormal <= 0) {
            return [0.0, 0];
        }

        if ($updNormal >= $origNormal && $actNormal >= $updNormal) {
            $deduction = (($actNormal - $updNormal) / $updNormal) * 2;
            $case = 1;
        } elseif ($updNormal >= $origNormal && $actNormal >= $origNormal) {
            $deduction = 0.0;
            $case = 2;
        } elseif ($updNormal >= $origNormal && $actNormal <= $origNormal) {
            $deduction = ($updNormal - $actNormal) / $updNormal;
            $case = 3;
        } elseif ($updNormal <= $origNormal && $actNormal >= $origNormal) {
            $deduction = (($actNormal - $updNormal) / $updNormal) * 3;
            $case = 4;
        } elseif ($updNormal <= $origNormal && $actNormal <= $updNormal) {
            $deduction = (($updNormal - $actNormal) / $updNormal) * 2;
            $case = 5;
        } else {
            $deduction = (($actNormal - $updNormal) / $updNormal) * 2;
            $case = 6;
        }

        // Formula yields a fraction of 1.0; the 0.35 cap maps to 35 points.
        return [max(0.0, 0.35 - $deduction) * 100, $case];
    }

    /**
     * Normal-hours score (out of 35) — new formula (dates >= NORMAL_HOURS_NEW_CUTOFF).
     * Full 35 when actual is within [floor, ceil].
     * Above ceil: deduct ((actual - ceil) / ceil) * 5.
     * Below floor: deduct ((floor - actual) / ceil) * 5.
     * Returns [score, case] where case 1=over, 2=under, 3=within.
     */
    private function normalHoursScoreNew(float $floor, float $ceil, float $actual): array
    {
        if ($ceil <= 0) {
            return [0.0, 0];
        }

        if ($actual > $ceil) {
            $deduction = (($actual - $ceil) / $ceil) * 5;
            $case = 1;
        } elseif ($actual < $floor) {
            $deduction = (($floor - $actual) / $ceil) * 5;
            $case = 2;
        } else {
            $deduction = 0.0;
            $case = 3;
        }

        return [max(0.0, 35.0 - $deduction * 100), $case];
    }

    /**
     * Overtime-hours score (out of 15). Full points when actual <= goal;
     * otherwise penalized by the gap relative to the updated NORMAL goal.
     */
    private function overtimeHoursScore(float $updOt, float $actOt, float $updNormal): float
    {
        if ($updNormal <= 0) {
            return 0.0;
        }

        if ($actOt <= $updOt) {
            return 0.15 * 100;
        }

        return max(0.0, 0.15 - (abs($updOt - $actOt) / $updNormal) * 2) * 100;
    }

    /**
     * Portal category (max 10): per-day mean of (put-into-portal% +
     * in-portal-on-time%)/2 across days with eligible orders, compared to
     * the portal goal. Only penalized when below goal.
     */
    private function scorePortal(array $dailyRows, ?float $goal): array
    {
        $max = self::SCORE_MAX['portal'];
        $daily = [];
        $count = 0;
        $sumPutPct = 0.0;
        $sumOnTimePct = 0.0;

        foreach ($dailyRows as $row) {
            $eligible = (int) ($row->portal_eligible_orders ?? 0);
            if ($eligible <= 0) {
                continue;
            }
            $used = (int) ($row->portal_used_orders ?? 0);
            $onTime = (int) ($row->portal_on_time_orders ?? 0);
            $putPct = ($used / $eligible) * 100;
            $onTimePct = $used > 0 ? ($onTime / $used) * 100 : 0.0;
            $dayScore = ($putPct + $onTimePct) / 2;

            $daily[CarbonImmutable::parse($row->business_date)->toDateString()] = round($dayScore, 2);
            $sumPutPct += $putPct;
            $sumOnTimePct += $onTimePct;
            $count++;
        }

        if ($count === 0) {
            $score = (float) $max;
            $actual = null;
            $avgPutPct = null;
            $avgOnTimePct = null;
        } else {
            $avgPutPct = round($sumPutPct / $count, 2);
            $avgOnTimePct = round($sumOnTimePct / $count, 2);
            $actual = round(($avgPutPct + $avgOnTimePct) / 2, 2);
            if ($goal === null) {
                $score = 0.0;
            } else {
                $below = max(0.0, $goal - $actual);
                $score = round(max(0.0, $max - $below), 2);
            }
        }

        return [
            'key' => 'portal',
            'label' => 'Portal',
            'score' => $score,
            'max' => $max,
            'actual_percent' => $actual,
            'avg_put_into_portal_percent' => $avgPutPct,
            'avg_in_portal_on_time_percent' => $avgOnTimePct,
            'goal_percent' => $goal,
            'days_counted' => $count,
            'daily' => $daily,
        ];
    }

    /**
     * HnR category (max 10): mean of each day's promise-met % across days
     * with transactions, compared to the HnR goal. Only penalized below goal.
     */
    private function scoreHnr(array $dailyRows, ?float $goal): array
    {
        $max = self::SCORE_MAX['hnr'];
        $daily = [];
        $count = 0;
        $totalTransactions = 0;
        $totalBroken = 0;

        foreach ($dailyRows as $row) {
            $transactions = (int) ($row->hnr_transactions ?? 0);
            if ($transactions <= 0) {
                continue;
            }
            $broken = (int) ($row->hnr_broken_promises ?? 0);
            $pct = (($transactions - $broken) / $transactions) * 100;

            $daily[CarbonImmutable::parse($row->business_date)->toDateString()] = round($pct, 2);
            $totalTransactions += $transactions;
            $totalBroken += $broken;
            $count++;
        }

        if ($totalTransactions === 0) {
            $score = (float) $max;
            $actual = null;
        } else {
            $actual = round(($totalTransactions - $totalBroken) / $totalTransactions * 100, 2);
            if ($goal === null) {
                $score = 0.0;
            } else {
                $below = max(0.0, $goal - $actual);
                $score = round(max(0.0, $max - $below), 2);
            }
        }

        return [
            'key' => 'hnr',
            'label' => 'HnR',
            'score' => $score,
            'max' => $max,
            'actual_percent' => $actual,
            'goal_percent' => $goal,
            'days_counted' => $count,
            'daily' => $daily,
        ];
    }

    /**
     * Transfer-in category (max 7.5): zero if any transfer INTO this store
     * (to_store_number) exists within the range, otherwise full points.
     */
    private function scoreTransferIn(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $max = self::SCORE_MAX['transfer_in'];
        $count = TransferInOut::where('to_store_number', $store)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->count();
        $has = $count > 0;

        return [
            'key' => 'transfer_in',
            'label' => 'Transfer In',
            'score' => $has ? 0.0 : (float) $max,
            'max' => $max,
            'has_transfer_in' => $has,
            'transfer_in_count' => $count,
        ];
    }

    /**
     * Items-turned-off category (max 7.5): sum of entered-key 27 over the
     * range. 0 => full, 1 => lose 3.5, 2+ => zero.
     */
    private function scoreItemsTurnedOff(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $max = self::SCORE_MAX['items_turned_off'];
        $sum = $this->enteredKeyValueSumForRange($store, self::SCORE_KEY_ITEMS_OFF, $start, $end);

        if ($sum <= 0) {
            $score = (float) $max;
        } elseif ($sum < 2) {
            $score = (float) $max - 3.5;
        } else {
            $score = 0.0;
        }

        return [
            'key' => 'items_turned_off',
            'label' => 'Items Turned Off',
            'score' => round($score, 2),
            'max' => $max,
            'count' => $sum,
        ];
    }

    /**
     * Orders-vs-sales category (max 15): Blue Line invoice total as a % of
     * week-to-date sales, compared symmetrically to the goal (each point of
     * difference, over or under, costs 1).
     */
    private function scoreOrdersVsSales(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        float $weekToDateSalesTotal,
        ?float $goal
    ): array {
        $max = self::SCORE_MAX['orders_vs_sales'];
        $blueLine = $this->blueLineTotalForRange($store, $start, $end);
        $actual = $weekToDateSalesTotal > 0
            ? round(($blueLine / $weekToDateSalesTotal) * 100, 2)
            : 0.0;

        if ($goal === null) {
            $score = 0.0;
        } else {
            $score = max(0.0, $max - abs($actual - $goal));
        }

        return [
            'key' => 'orders_vs_sales',
            'label' => 'Orders vs Sales',
            'score' => round($score, 2),
            'max' => $max,
            'actual_percent' => $actual,
            'goal_percent' => $goal,
            'blue_line_total' => round($blueLine, 2),
            'four_week_sales' => round($weekToDateSalesTotal, 2),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
        ];
    }

    /**
     * Whole-score deduction for non-negotiable entries in the range:
     * 25 per "Downtime", 35 per any other action.
     */
    private function scoreNonNegotiablePenalty(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $actions = NonNegotiableReport::where('store_number', $store)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('action');

        $downtime = 0;
        $other = 0;
        foreach ($actions as $action) {
            if ($action === 'Downtime') {
                $downtime++;
            } else {
                $other++;
            }
        }

        $penalty = $downtime * self::NON_NEGOTIABLE_DOWNTIME_PENALTY
            + $other * self::NON_NEGOTIABLE_OTHER_PENALTY;

        return [
            'entries' => $downtime + $other,
            'downtime' => $downtime,
            'other' => $other,
            'penalty' => $penalty,
        ];
    }

    /** Pick the label for the highest threshold the score meets. */
    private function labelForScore(float $score): string
    {
        foreach (self::STORE_SCORE_LABELS as $threshold => $label) {
            if ($score >= $threshold) {
                return $label;
            }
        }

        return 'Poor';
    }

    /** First goal value for a metric id in the already-built goal_metrics array. */
    private function goalValueFromMetrics(array $goalMetrics, int $metricId): ?float
    {
        foreach ($goalMetrics as $metric) {
            if ((int) ($metric['metric_id'] ?? 0) === $metricId) {
                $goals = $metric['goals'] ?? [];

                return $goals === [] ? null : (float) ($goals[0]['goal'] ?? 0);
            }
        }

        return null;
    }

    /** Latest entered-key value for a store within the range (0 if none). */
    private function enteredKeyValueLatest(
        string $store,
        int $keyId,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        $row = EnteredKeyValue::query()
            ->where('store_id', $store)
            ->where('key_id', $keyId)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->where('is_mistaken', false)
            ->orderBy('entry_date', 'desc')
            ->orderBy('id', 'desc')
            ->first(['value_number']);

        return $row !== null ? (float) $row->value_number : 0.0;
    }

    /** Per-day summary rows for HnR + portal daily means (memoized, one query). */
    private function dailySummaryRowsForRange(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = "dailySummaryRows:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): array => DailyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('business_date')
            ->get([
                'business_date',
                'hnr_transactions',
                'hnr_broken_promises',
                'portal_eligible_orders',
                'portal_used_orders',
                'portal_on_time_orders',
            ])
            ->all());
    }

    /** Blue Line invoice total for a store within the range (memoized). */
    private function blueLineTotalForRange(string $store, CarbonImmutable $start, CarbonImmutable $end): float
    {
        $key = "blueLineTotalForRange:{$store}:{$start->toDateString()}:{$end->toDateString()}";

        return $this->remember($key, fn(): float => (float) InventoryOrder::where('store_number', $store)
            ->whereBetween('delivery_date', [$start->toDateString(), $end->toDateString()])
            ->where('vendor_name', 'like', '%BLUE LINE%')
            ->sum('invoice_total'));
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

    /**
     * Sum of the latest entered-key value per day for a store within the range.
     * Multiple users can fill the same key/day (fill_mode = role_each); only the
     * latest entry per day counts, regardless of who filled it out.
     */
    private function enteredKeyValueSumForRange(
        string $store,
        int $keyId,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        return (float) EnteredKeyValue::query()
            ->whereIn('id', function ($q) use ($store, $keyId, $start, $end) {
                $q->from('entered_key_values')
                    ->selectRaw('MAX(id)')
                    ->where('store_id', $store)
                    ->where('key_id', $keyId)
                    ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
                    ->where('is_mistaken', false)
                    ->groupBy('entry_date');
            })
            ->sum('value_number');
    }

    /**
     * Constrains a query to the entered-key rows that hold labor cost for the
     * business dates in [$start, $end], covering the key 23 -> key 28 switchover:
     * dates before the cutoff read key 23 at entry_date = date; dates on/after the
     * cutoff read key 28 at entry_date = date + 1 day.
     */
    private function applyLaborKeySegments(Builder $query, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $cutoff = CarbonImmutable::parse(self::LABOR_YESTERDAY_KEY_CUTOFF);
        $oldEnd = $end->lt($cutoff) ? $end : $cutoff->subDay();
        $newStart = $start->gte($cutoff) ? $start : $cutoff;

        $query->where(function (Builder $q) use ($start, $oldEnd, $newStart, $end) {
            if ($start->lte($oldEnd)) {
                $q->orWhere(function (Builder $q2) use ($start, $oldEnd) {
                    $q2->where('key_id', self::LABOR_ENTERED_KEY_ID)
                        ->whereBetween('entry_date', [$start->toDateString(), $oldEnd->toDateString()]);
                });
            }

            if ($newStart->lte($end)) {
                $q->orWhere(function (Builder $q2) use ($newStart, $end) {
                    $q2->where('key_id', self::LABOR_YESTERDAY_KEY_ID)
                        ->whereBetween('entry_date', [
                            $newStart->addDay()->toDateString(),
                            $end->addDay()->toDateString(),
                        ]);
                });
            }
        });
    }

    /**
     * Sum of labor cost per business day for a store within the range, accounting
     * for the key 23 -> key 28 (yesterday's labor) switchover. See applyLaborKeySegments().
     */
    private function laborValueSumForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): float {
        return (float) EnteredKeyValue::query()
            ->whereIn('id', function ($q) use ($store, $start, $end) {
                $q->from('entered_key_values')
                    ->selectRaw('MAX(id)')
                    ->where('store_id', $store)
                    ->where('is_mistaken', false);
                $this->applyLaborKeySegments($q, $start, $end);
                $q->groupBy('entry_date');
            })
            ->sum('value_number');
    }

    /**
     * Labor cost (value + percent of sales) for each individual business day
     * within the range, e.g. week-to-date so far.
     */
    private function laborByDayForRange(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $salesByDay
    ): array {
        $out = [];

        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $value = $this->laborValueSumForRange($store, $d, $d);
            $sales = (float) ($salesByDay[$d->toDateString()] ?? 0.0);

            $out[$d->toDateString()] = [
                'value' => round($value, 2),
                'percent' => $this->percentOfSales($value, $sales),
            ];
        }

        return $out;
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
        return [
            'portal_eligible_orders' => $this->averageValue((float) ($metrics['portal_eligible_orders'] ?? 0), $days),
            'portal_used_orders' => $this->averageValue((float) ($metrics['portal_used_orders'] ?? 0), $days),
            'portal_on_time_orders' => $this->averageValue((float) ($metrics['portal_on_time_orders'] ?? 0), $days),
            'put_into_portal_percent' => (float) ($metrics['put_into_portal_percent'] ?? 0),
            'in_portal_on_time_percent' => (float) ($metrics['in_portal_on_time_percent'] ?? 0),
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
        $rows = DailyStoreSummary::where('franchise_store', $store)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->get(['portal_eligible_orders', 'portal_used_orders', 'portal_on_time_orders']);

        $totalEligible = 0;
        $totalUsed = 0;
        $totalOnTime = 0;
        $sumPutPct = 0.0;
        $sumOnTimePct = 0.0;
        $count = 0;

        foreach ($rows as $row) {
            $eligible = (int) ($row->portal_eligible_orders ?? 0);
            $used = (int) ($row->portal_used_orders ?? 0);
            $onTime = (int) ($row->portal_on_time_orders ?? 0);
            $totalEligible += $eligible;
            $totalUsed += $used;
            $totalOnTime += $onTime;

            if ($eligible > 0) {
                $sumPutPct += ($used / $eligible) * 100;
                $sumOnTimePct += $used > 0 ? ($onTime / $used) * 100 : 0.0;
                $count++;
            }
        }

        return [
            'portal_eligible_orders' => $totalEligible,
            'portal_used_orders' => $totalUsed,
            'portal_on_time_orders' => $totalOnTime,
            'put_into_portal_percent' => $count > 0 ? round($sumPutPct / $count, 2) : 0,
            'in_portal_on_time_percent' => $count > 0 ? round($sumOnTimePct / $count, 2) : 0,
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
        $queries = DatabaseRouter::routedQueries('order_line', $start->toMutable(), $end->toMutable());
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
                COALESCE(SUM(net_amount), 0) as promo_sales,
                COALESCE(SUM(CASE WHEN order_placed_method = 'Doordash'
                    THEN net_amount ELSE 0 END), 0) as doordash_sales,
                COALESCE(SUM(CASE WHEN order_placed_method IN ('UberEats','Uber Eats')
                    THEN net_amount ELSE 0 END), 0) as ubereats_sales,
                COALESCE(SUM(CASE WHEN order_placed_method IN ('Grubhub','GrubHub')
                    THEN net_amount ELSE 0 END), 0) as grubhub_sales
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
        $prevFiscalYearStart = $this->fiscalYearStart($fiscalYear - 1);
        $weekIdx = (int) ($yearStart->diffInDays($weekStart) / 7);
        $periodIdx = (int) floor($weekIdx / 4);
        $periodStart = $yearStart->addWeeks($periodIdx * 4);
        $quarterStart = $this->quarterBounds($yearStart, $prevFiscalYearStart, $periodIdx)['quarterStart'];

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
        $prevFiscalYearStart = $this->fiscalYearStart($fiscalYear - 1);
        $weekIdx = (int) ($yearStart->diffInDays($weekStart) / 7);
        $periodIdx = (int) floor($weekIdx / 4);
        $periodStart = $yearStart->addWeeks($periodIdx * 4);
        $daysInPeriod = (int) $periodStart->diffInDays($day);

        $prevPeriodStart = $periodStart->subWeeks(4);
        $lastYearPeriodStart = $prevFiscalYearStart->addWeeks($periodIdx * 4);

        // ── Quarter (fiscal quarters group periods as 3-3-3-4: Q1=1-3, Q2=4-6, Q3=7-9, Q4=10-13) ───
        $quarterBounds = $this->quarterBounds($yearStart, $prevFiscalYearStart, $periodIdx);
        $quarterStart = $quarterBounds['quarterStart'];
        $daysInQuarter = (int) $quarterStart->diffInDays($day);

        $prevQuarterStart = $quarterBounds['prevQuarterStart'];
        $lastYearQuarterStart = $quarterBounds['lastYearQuarterStart'];

        // ── Year ─────────────────────────────────────────────────────────
        $daysInYear = (int) $yearStart->diffInDays($day);
        $prevYearStart = $prevFiscalYearStart;

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

    /**
     * Fiscal quarters group the 13 four-week periods as 3-3-3-4:
     * Q1 = periods 1-3, Q2 = periods 4-6, Q3 = periods 7-9, Q4 = periods 10-13.
     */
    private function quarterBounds(
        CarbonImmutable $yearStart,
        CarbonImmutable $prevFiscalYearStart,
        int $periodIdx
    ): array {
        $quarterStartPeriodIdx = 0;

        foreach ([3, 3, 3, 4] as $periodsInQuarter) {
            if ($periodIdx < $quarterStartPeriodIdx + $periodsInQuarter) {
                break;
            }

            $quarterStartPeriodIdx += $periodsInQuarter;
        }

        // Every quarter is 3 periods (12 weeks) except Q4, which is 4 (16 weeks).
        $prevQuarterWeeks = $quarterStartPeriodIdx === 0 ? 16 : 12;
        $quarterStart = $yearStart->addWeeks($quarterStartPeriodIdx * 4);

        return [
            'quarterStart' => $quarterStart,
            'prevQuarterStart' => $quarterStart->subWeeks($prevQuarterWeeks),
            'lastYearQuarterStart' => $prevFiscalYearStart->addWeeks($quarterStartPeriodIdx * 4),
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
        $prevFiscalYearStart = $this->fiscalYearStart($fiscalYear - 1);
        $weekIdx = (int) ($yearStart->diffInDays($weekStart) / 7);
        $periodIdx = (int) floor($weekIdx / 4);
        $periodStart = $yearStart->addWeeks($periodIdx * 4);
        $daysInPeriod = (int) $periodStart->diffInDays($day);

        $prevPeriodStart = $periodStart->subWeeks(4);
        $lastYearPeriodStart = $prevFiscalYearStart->addWeeks($periodIdx * 4);

        $quarterBounds = $this->quarterBounds($yearStart, $prevFiscalYearStart, $periodIdx);
        $quarterStart = $quarterBounds['quarterStart'];
        $daysInQuarter = (int) $quarterStart->diffInDays($day);

        $prevQuarterStart = $quarterBounds['prevQuarterStart'];
        $lastYearQuarterStart = $quarterBounds['lastYearQuarterStart'];

        $daysInYear = (int) $yearStart->diffInDays($day);
        $prevYearStart = $prevFiscalYearStart;

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
